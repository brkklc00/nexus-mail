<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\EmailSmtpAccount;
use App\Domain\Entities\EmailSmtpDailyReport;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class AlibabaDirectMailReportService
{
    private const SOURCE = 'alibaba_directmail';

    private int $remainingHttpCalls = 1;

    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * @return array{synced:int,errors:string[]}
     */
    public function syncRecentReports(int $days = 7): array
    {
        $days = max(1, min(31, $days));
        $maxCalls = (int) ($_ENV['ALIBABA_DM_SYNC_MAX_HTTP_CALLS'] ?? 200);
        $this->remainingHttpCalls = max(12, $maxCalls);

        $credentials = $this->getCredentials();
        $smtps = $this->getActiveSmtps();
        $synced = 0;
        $errors = [];

        if (empty($smtps)) {
            return ['synced' => 0, 'errors' => ['Aktif SMTP bulunamadi.']];
        }

        $smtpsByDomain = [];
        foreach ($smtps as $smtp) {
            $domain = $this->extractDomain($smtp->getFromEmail());
            if ($domain === null) {
                continue;
            }
            $smtpsByDomain[strtolower($domain)][] = $smtp;
        }

        foreach ($smtpsByDomain as $domain => $domainSmtps) {
            $firstSmtp = $domainSmtps[0];

            for ($i = 0; $i < $days; $i++) {
                if ($this->remainingHttpCalls <= 0) {
                    $errors[] = 'HTTP cagri limiti doldu (ALIBABA_DM_SYNC_MAX_HTTP_CALLS). Kismi senkron; tekrar deneyin.';
                    break 2;
                }

                $reportDate = $this->reportDateInAlibabaTimezone($i);
                try {
                    $metrics = $this->fetchDailyMetrics($credentials, $firstSmtp, $domain, $reportDate);
                    if ($metrics === null) {
                        continue;
                    }

                    foreach ($domainSmtps as $smtp) {
                        $this->upsertReport($smtp->getName(), $domain, $reportDate, $metrics['total'], $metrics['successful'], $metrics['failed'], $metrics['invalid_address'], $metrics['raw_payload']);
                        $synced++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s: %s', $domain, $e->getMessage());
                }
            }
        }

        $this->em->flush();

        return [
            'synced' => $synced,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getDailySummary(int $days = 7): array
    {
        $days = max(1, min(60, $days));
        $tz = new DateTimeZone($_ENV['ALIBABA_DM_REPORT_TIMEZONE'] ?? 'Asia/Singapore');
        $from = (new DateTimeImmutable('today', $tz))->modify('-' . ($days - 1) . ' days')->format('Y-m-d');

        $sql = 'SELECT
                    report_date,
                    SUM(total) AS total,
                    SUM(successful) AS successful,
                    SUM(failed) AS failed,
                    SUM(invalid_address) AS invalid_address,
                    CASE WHEN SUM(total) > 0 THEN (SUM(successful) / SUM(total)) * 100 ELSE 0 END AS success_rate,
                    CASE WHEN SUM(total) > 0 THEN (SUM(invalid_address) / SUM(total)) * 100 ELSE 0 END AS invalid_rate
                FROM email_smtp_daily_reports
                WHERE source = :source
                  AND report_date >= :from_date
                GROUP BY report_date
                ORDER BY report_date DESC';

        $rows = $this->em->getConnection()->fetchAllAssociative($sql, [
            'source' => self::SOURCE,
            'from_date' => $from,
        ]);

        return array_map(static function (array $row): array {
            return [
                'report_date' => $row['report_date'],
                'total' => (int) ($row['total'] ?? 0),
                'successful' => (int) ($row['successful'] ?? 0),
                'failed' => (int) ($row['failed'] ?? 0),
                'invalid_address' => (int) ($row['invalid_address'] ?? 0),
                'success_rate' => round((float) ($row['success_rate'] ?? 0), 2),
                'invalid_rate' => round((float) ($row['invalid_rate'] ?? 0), 2),
            ];
        }, $rows);
    }

    /**
     * @return array{access_key_id:string,access_key_secret:string,region:string,endpoints:string[]}
     */
    private function getCredentials(): array
    {
        $accessKeyId = trim((string) ($_ENV['ALIBABA_DM_ACCESS_KEY_ID'] ?? ''));
        $accessKeySecret = trim((string) ($_ENV['ALIBABA_DM_ACCESS_KEY_SECRET'] ?? ''));
        $region = trim((string) ($_ENV['ALIBABA_DM_REGION'] ?? 'ap-southeast-1'));

        if ($accessKeyId === '' || $accessKeySecret === '') {
            throw new \RuntimeException('ALIBABA_DM_ACCESS_KEY_ID / ALIBABA_DM_ACCESS_KEY_SECRET eksik.');
        }

        $configuredEndpoint = trim((string) ($_ENV['ALIBABA_DM_ENDPOINT'] ?? ''));
        // Bölgesel endpoint önce (Singapur: dm.ap-southeast-1.aliyuncs.com)
        $endpoints = array_values(array_unique(array_filter([
            "https://dm.{$region}.aliyuncs.com/",
            $configuredEndpoint,
            'https://dm.aliyuncs.com/',
        ])));

        return [
            'access_key_id' => $accessKeyId,
            'access_key_secret' => $accessKeySecret,
            'region' => $region,
            'endpoints' => $endpoints,
        ];
    }

    /**
     * @return EmailSmtpAccount[]
     */
    private function getActiveSmtps(): array
    {
        return $this->em->getRepository(EmailSmtpAccount::class)->findBy(['isActive' => true], ['priority' => 'ASC']);
    }

    /**
     * Alibaba konsolu UTC+8 (Singapur ile aynı takvim günü) ile hizalı rapor tarihi.
     */
    private function reportDateInAlibabaTimezone(int $daysAgo): DateTimeImmutable
    {
        $tzName = $_ENV['ALIBABA_DM_REPORT_TIMEZONE'] ?? 'Asia/Singapore';
        $tz = new DateTimeZone($tzName);

        return (new DateTimeImmutable('today', $tz))->modify('-' . max(0, $daysAgo) . ' days')->setTime(0, 0, 0);
    }

    /**
     * @return array{total:int,successful:int,failed:int,invalid_address:int,raw_payload:string}|null
     */
    private function fetchDailyMetrics(array $credentials, EmailSmtpAccount $smtp, string $domain, DateTimeImmutable $reportDate): ?array
    {
        $accountName = trim($smtp->getFromEmail());
        if ($accountName === '') {
            return null;
        }

        $dateStart = $reportDate->setTime(0, 0, 0);
        $dateEnd = $reportDate->setTime(23, 59, 59);
        // SenderStatisticsByTagNameAndBatchID: StartTime/EndTime sadece yyyy-MM-dd (InvalidDate.Malformed aksi halde)
        $dayStr = $dateStart->format('Y-m-d');

        $lastError = null;

        foreach ($credentials['endpoints'] as $endpoint) {
            try {
                $response = $this->requestAlibabaRpc(
                    endpoint: $endpoint,
                    accessKeyId: $credentials['access_key_id'],
                    accessKeySecret: $credentials['access_key_secret'],
                    action: 'SenderStatisticsByTagNameAndBatchID',
                    query: [
                        'RegionId' => $credentials['region'],
                        'AccountName' => $accountName,
                        'Domain' => $domain,
                        'StartTime' => $dayStr,
                        'EndTime' => $dayStr,
                    ]
                );

                $metrics = $this->aggregateSenderStatisticsResponse($response, $reportDate);
                if ($metrics !== null) {
                    return array_merge($metrics, [
                        'raw_payload' => json_encode($response, JSON_UNESCAPED_UNICODE),
                    ]);
                }
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        $legacy = array_filter(array_map('trim', explode(',', (string) ($_ENV['ALIBABA_DM_LEGACY_STATS_ACTIONS'] ?? ''))));
        foreach ($credentials['endpoints'] as $endpoint) {
            foreach ($legacy as $action) {
                try {
                    $response = $this->requestAlibabaRpc(
                        endpoint: $endpoint,
                        accessKeyId: $credentials['access_key_id'],
                        accessKeySecret: $credentials['access_key_secret'],
                        action: $action,
                        query: [
                            'RegionId' => $credentials['region'],
                            'DomainName' => $domain,
                            'StartTime' => $dateStart->format('Y-m-d\TH:i:s\Z'),
                            'EndTime' => $dateEnd->format('Y-m-d\TH:i:s\Z'),
                            'StartDate' => $dateStart->format('Y-m-d'),
                            'EndDate' => $dateEnd->format('Y-m-d'),
                            'Date' => $dateStart->format('Y-m-d'),
                            'PageNumber' => '1',
                            'PageSize' => '200',
                        ]
                    );

                    $metrics = $this->extractMetrics($response, $reportDate);
                    if ($metrics !== null) {
                        return array_merge($metrics, [
                            'raw_payload' => json_encode($response, JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $lastError = $e;
                }
            }
        }

        if ($lastError !== null) {
            throw new \RuntimeException($lastError->getMessage());
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractSenderStatRows(array $response): array
    {
        $data = $response['data'] ?? $response['Data'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        $stat = $data['stat'] ?? $data['Stat'] ?? [];
        if ($stat === []) {
            return [];
        }

        if (isset($stat[0]) && is_array($stat[0])) {
            return $stat;
        }

        if (is_array($stat)) {
            return [$stat];
        }

        return [];
    }

    /**
     * @return array{total:int,successful:int,failed:int,invalid_address:int}|null
     */
    private function aggregateSenderStatisticsResponse(array $response, DateTimeImmutable $reportDate): ?array
    {
        $rows = $this->extractSenderStatRows($response);
        if ($rows === []) {
            return null;
        }

        $dayPrefix = $reportDate->format('Y-m-d');
        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ct = (string) ($row['CreateTime'] ?? $row['createTime'] ?? '');
            $rowDay = $ct === '' ? $dayPrefix : substr(str_replace('T', ' ', $ct), 0, 10);
            if ($rowDay === $dayPrefix) {
                $filtered[] = $row;
            }
        }

        $use = $filtered !== [] ? $filtered : $rows;

        $total = 0;
        $successful = 0;
        $failed = 0;
        $invalid = 0;

        foreach ($use as $row) {
            if (!is_array($row)) {
                continue;
            }
            $total += (int) ($row['requestCount'] ?? $row['RequestCount'] ?? 0);
            $successful += (int) ($row['successCount'] ?? $row['SuccessCount'] ?? 0);
            $failed += (int) ($row['faildCount'] ?? $row['FaildCount'] ?? $row['failedCount'] ?? $row['FailedCount'] ?? 0);
            $invalid += (int) ($row['unavailableCount'] ?? $row['UnavailableCount'] ?? 0);
        }

        if ($total === 0 && $successful === 0 && $failed === 0 && $invalid === 0) {
            return null;
        }

        if ($total === 0) {
            $total = $successful + $failed + $invalid;
        }

        if ($total === 0) {
            return null;
        }

        if ($successful + $failed + $invalid > $total) {
            $failed = max(0, $total - $successful - $invalid);
        }

        return [
            'total' => max(0, $total),
            'successful' => max(0, $successful),
            'failed' => max(0, $failed),
            'invalid_address' => max(0, $invalid),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function requestAlibabaRpc(string $endpoint, string $accessKeyId, string $accessKeySecret, string $action, array $query): array
    {
        $common = [
            'Format' => 'JSON',
            'Version' => (string) ($_ENV['ALIBABA_DM_API_VERSION'] ?? '2015-11-23'),
            'AccessKeyId' => $accessKeyId,
            'SignatureMethod' => 'HMAC-SHA1',
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(16)),
            'Action' => $action,
        ];

        $params = array_merge($common, $query);
        ksort($params);

        $canonicalizedQuery = [];
        foreach ($params as $key => $value) {
            $canonicalizedQuery[] = $this->percentEncode((string) $key) . '=' . $this->percentEncode((string) $value);
        }
        $canonicalizedQueryString = implode('&', $canonicalizedQuery);

        $stringToSign = 'GET&%2F&' . $this->percentEncode($canonicalizedQueryString);
        $signature = base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
        $finalQuery = 'Signature=' . $this->percentEncode($signature) . '&' . $canonicalizedQueryString;

        if ($this->remainingHttpCalls <= 0) {
            throw new \RuntimeException('Senkron HTTP cagri limiti asildi.');
        }
        $this->remainingHttpCalls--;

        $client = new Client([
            'timeout' => 12.0,
            'connect_timeout' => 5.0,
        ]);

        try {
            $httpResponse = $client->request('GET', rtrim($endpoint, '/') . '/?' . $finalQuery);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Alibaba API istegi basarisiz: ' . $e->getMessage());
        }

        $body = (string) $httpResponse->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Alibaba API JSON decode edilemedi.');
        }

        if (isset($decoded['Code']) && (string) $decoded['Code'] !== 'OK') {
            $message = (string) ($decoded['Message'] ?? 'Bilinmeyen hata');
            throw new \RuntimeException("Alibaba API hatasi: {$message}");
        }

        return $decoded;
    }

    private function percentEncode(string $value): string
    {
        return str_replace(['+', '*', '%7E'], ['%20', '%2A', '~'], rawurlencode($value));
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2 || trim($parts[1]) === '') {
            return null;
        }

        return strtolower(trim($parts[1]));
    }

    /**
     * @return array{total:int,successful:int,failed:int,invalid_address:int}|null
     */
    private function extractMetrics(array $response, DateTimeImmutable $reportDate): ?array
    {
        $rows = $this->extractRows($response);
        if (empty($rows)) {
            $rows = [$response];
        }

        $selected = null;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowDate = $this->findString($row, ['Date', 'ReportDate', 'StatDate', 'Time']);
            if ($rowDate !== null && !str_starts_with($rowDate, $reportDate->format('Y-m-d'))) {
                continue;
            }

            $selected = $row;
            break;
        }

        if ($selected === null) {
            $selected = is_array($rows[0] ?? null) ? $rows[0] : null;
        }

        if (!is_array($selected)) {
            return null;
        }

        $total = $this->findInt($selected, ['Total', 'TotalCount', 'RequestCount', 'SendCount', 'SentCount']);
        $successful = $this->findInt($selected, ['Successful', 'SuccessCount', 'Delivered', 'DeliveredCount', 'PassCount']);
        $invalid = $this->findInt($selected, ['InvalidAddress', 'InvalidAddressCount', 'InvalidCount', 'InvalidMailCount', 'InvalidRcptCount']);
        $failed = $this->findInt($selected, ['Failed', 'FailCount', 'FailedCount', 'RejectCount']);

        if ($total === null) {
            return null;
        }

        $successful = $successful ?? 0;
        $invalid = $invalid ?? 0;
        $failed = $failed ?? max(0, $total - $successful - $invalid);

        return [
            'total' => max(0, $total),
            'successful' => max(0, $successful),
            'failed' => max(0, $failed),
            'invalid_address' => max(0, $invalid),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractRows(array $response): array
    {
        $candidates = [
            $response['Data']['Items'] ?? null,
            $response['Data']['Statistics'] ?? null,
            $response['DomainStatistics'] ?? null,
            $response['Statistics'] ?? null,
            $response['Items'] ?? null,
            $response['DomainStatisticsList']['DomainStatistics'] ?? null,
            $response['Report']['Items'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            if (isset($candidate[0]) && is_array($candidate[0])) {
                return $candidate;
            }

            if (!empty($candidate) && array_is_list($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }

            if (!empty($candidate) && !array_is_list($candidate)) {
                return [$candidate];
            }
        }

        return [];
    }

    private function findInt(array $row, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            if (is_numeric($row[$key])) {
                return (int) $row[$key];
            }
        }

        foreach ($row as $value) {
            if (is_array($value)) {
                $nested = $this->findInt($value, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function findString(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_scalar($row[$key])) {
                return (string) $row[$key];
            }
        }

        foreach ($row as $value) {
            if (is_array($value)) {
                $nested = $this->findString($value, $keys);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    private function upsertReport(
        string $smtpName,
        string $domain,
        DateTimeImmutable $reportDate,
        int $total,
        int $successful,
        int $failed,
        int $invalidAddress,
        ?string $rawPayload
    ): void {
        $repo = $this->em->getRepository(EmailSmtpDailyReport::class);
        $report = $repo->findOneBy([
            'source' => self::SOURCE,
            'reportDate' => $reportDate,
            'domain' => strtolower($domain),
            'smtpName' => $smtpName,
        ]);

        if (!$report instanceof EmailSmtpDailyReport) {
            $report = new EmailSmtpDailyReport();
            $report
                ->setSource(self::SOURCE)
                ->setReportDate($reportDate)
                ->setDomain($domain)
                ->setSmtpName($smtpName);
            $this->em->persist($report);
        }

        $total = max(0, $total);
        $successful = max(0, $successful);
        $invalidAddress = max(0, $invalidAddress);
        $failed = max(0, $failed);
        if ($successful + $failed + $invalidAddress > $total) {
            $failed = max(0, $total - $successful - $invalidAddress);
        }

        $successRate = $total > 0 ? ($successful / $total) * 100 : 0;
        $invalidRate = $total > 0 ? ($invalidAddress / $total) * 100 : 0;

        $report
            ->setTotal($total)
            ->setSuccessful($successful)
            ->setFailed($failed)
            ->setInvalidAddress($invalidAddress)
            ->setSuccessRate($successRate)
            ->setInvalidRate($invalidRate)
            ->setRawPayload($rawPayload);
    }
}

