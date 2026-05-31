<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Application\Services\EmailDataPoolJobService;
use App\Application\Services\EmailDataPoolStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DataPoolJobsWorkCommand extends Command
{
    protected static $defaultName = 'data-pool:jobs:work';

    public function __construct(private ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Data pool kuyrugundaki bekleyen isleri batch olarak calistirir.')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Tek bir job calistirip cikar')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Maksimum calisma suresi (sn)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var EntityManagerInterface $em */
        $em = $this->container->get(\Doctrine\ORM\EntityManager::class);
        /** @var EmailDataPoolJobService $jobService */
        $jobService = $this->container->get(EmailDataPoolJobService::class);
        /** @var EmailDataPoolStatsService $statsService */
        $statsService = $this->container->get(EmailDataPoolStatsService::class);

        $batchSize = max(1000, (int) ($_ENV['DATA_POOL_JOB_BATCH_SIZE'] ?? 10000));
        $sleepMs = max(10, (int) ($_ENV['DATA_POOL_JOB_SLEEP_MS'] ?? 100));
        $maxRuntime = max(30, (int) ($input->getOption('max-runtime') ?? ($_ENV['DATA_POOL_JOB_MAX_RUNTIME'] ?? 300)));
        $once = (bool) $input->getOption('once');
        $startedAt = time();

        $io->text(sprintf('Data pool worker basladi (batch=%d, sleep=%dms, max_runtime=%ds)', $batchSize, $sleepMs, $maxRuntime));

        while (true) {
            if ((time() - $startedAt) >= $maxRuntime) {
                $io->text('Max runtime doldu, worker sonlaniyor.');
                break;
            }

            $job = $this->claimNextQueuedJob($em);
            if (!$job) {
                if ($once) {
                    break;
                }
                usleep($sleepMs * 1000);
                continue;
            }

            $jobId = (int) ($job['id'] ?? 0);
            $poolId = (int) ($job['pool_id'] ?? 0);
            $type = (string) ($job['type'] ?? '');
            $io->text(sprintf('#%d isleniyor: %s (pool: %d)', $jobId, $type, $poolId));
            $jobService->markRunning($jobId);

            try {
                $result = match ($type) {
                    'analyze_pool' => $this->processAnalyzePool($em, $jobService, $jobId, $poolId, $batchSize),
                    'remove_non_gmail' => $this->processRemoveNonGmail($em, $jobService, $jobId, $poolId, $batchSize),
                    'remove_duplicates' => $this->processRemoveDuplicates($em, $jobService, $jobId, $poolId, $batchSize),
                    'fix_gmail_typos' => $this->processFixGmailTypos($em, $jobService, $jobId, $poolId, $batchSize),
                    'export_pool' => $this->processExportPool($em, $jobService, $jobId, $job, $poolId, $batchSize),
                    default => throw new \RuntimeException('Desteklenmeyen job tipi: ' . $type),
                };

                $statsService->refreshFromPoolCache($poolId);
                $jobService->markCompleted(
                    $jobId,
                    (int) ($result['processed_count'] ?? 0),
                    (int) ($result['success_count'] ?? 0),
                    (int) ($result['failed_count'] ?? 0),
                    $result
                );
                $io->success(sprintf('#%d tamamlandi', $jobId));
            } catch (\Throwable $e) {
                $jobService->markFailed($jobId, $e->getMessage());
                $io->error(sprintf('#%d basarisiz: %s', $jobId, $e->getMessage()));
            } finally {
                $em->clear();
            }

            if ($once) {
                break;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimNextQueuedJob(EntityManagerInterface $em): ?array
    {
        $conn = $em->getConnection();
        $lock = (int) $conn->fetchOne("SELECT GET_LOCK('data_pool_jobs_worker', 0)");
        if ($lock !== 1) {
            return null;
        }

        try {
            $job = $conn->fetchAssociative(
                "SELECT * FROM data_pool_jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1"
            );
            if (!$job) {
                return null;
            }
            $conn->executeStatement(
                "UPDATE data_pool_jobs SET status = 'running', started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status = 'queued'",
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), (int) ($job['id'] ?? 0)]
            );

            return $job;
        } finally {
            $conn->executeQuery("SELECT RELEASE_LOCK('data_pool_jobs_worker')");
        }
    }

    /**
     * @return array<string, int|float|string>
     */
    private function processAnalyzePool(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $total = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$poolId]);
        $processed = 0;
        $lastId = 0;
        $gmail = 0;
        $nonGmail = 0;
        $invalid = 0;

        $typos = [
            'gmial.com','gamil.com','gmai.com','gmail.co','gmail.con','gmal.com',
            'gmaill.com','gml.com','gnail.com','gmaiil.com','gmail.cm','gmail.om','gmail.com.tr','gmail.coom','gmail.comm',
        ];

        while (true) {
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email, domain
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                  ORDER BY id ASC
                  LIMIT $batchSize",
                [$poolId, $lastId]
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) ($row['id'] ?? $lastId);
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));
                if ($domain === '' && str_contains($email, '@')) {
                    [, $domain] = explode('@', $email, 2);
                    $domain = strtolower(trim($domain));
                }
                if ($domain === 'gmail.com') {
                    $gmail++;
                } else {
                    $nonGmail++;
                }
                if (in_array($domain, $typos, true)) {
                    $invalid++;
                }
                $processed++;
            }

            $jobService->updateProgress($jobId, $processed, $total, $processed, 0);
        }

        $duplicateCount = (int) $conn->fetchOne(
            'SELECT COALESCE(SUM(t.cnt - 1), 0)
               FROM (
                    SELECT COUNT(*) AS cnt
                      FROM email_data_pool
                     WHERE pool_list_id = ?
                     GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                    HAVING COUNT(*) > 1
               ) t',
            [$poolId]
        );

        $ratio = $total > 0 ? round(($gmail / max(1, $total)) * 100, 2) : 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'INSERT INTO email_data_pool_analysis_cache
                (list_id, total_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, deletable_count, gmail_ratio, target_limit, over_limit_count, missing_count, normalized_preview, non_gmail_preview, last_analyzed_at, status, error_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_count = VALUES(total_count),
                gmail_count = VALUES(gmail_count),
                non_gmail_count = VALUES(non_gmail_count),
                invalid_gmail_count = VALUES(invalid_gmail_count),
                duplicate_count = VALUES(duplicate_count),
                deletable_count = VALUES(deletable_count),
                gmail_ratio = VALUES(gmail_ratio),
                target_limit = VALUES(target_limit),
                last_analyzed_at = VALUES(last_analyzed_at),
                status = VALUES(status),
                error_message = NULL,
                updated_at = VALUES(updated_at)',
            [$poolId, $total, $gmail, $nonGmail, $invalid, $duplicateCount, $nonGmail + $duplicateCount, $ratio, (int) ($_ENV['EMAIL_POOL_DEFAULT_TARGET_LIMIT'] ?? 250000), '[]', '[]', $now, 'completed', $now, $now]
        );

        return [
            'processed_count' => $processed,
            'success_count' => $processed,
            'failed_count' => 0,
            'gmail_count' => $gmail,
            'non_gmail_count' => $nonGmail,
            'invalid_gmail_count' => $invalid,
            'duplicate_count' => $duplicateCount,
            'gmail_ratio' => $ratio,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processRemoveNonGmail(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $totalToDelete = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ? AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'",
            [$poolId]
        );

        $deleted = 0;
        while (true) {
            $ids = $conn->fetchFirstColumn(
                "SELECT id
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
                  ORDER BY id ASC
                  LIMIT $batchSize",
                [$poolId]
            );
            if ($ids === []) {
                break;
            }
            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $deleted += $conn->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)",
                array_merge([$poolId], $ids)
            );
            $jobService->updateProgress($jobId, $deleted, $totalToDelete, $deleted, 0);
        }

        $this->refreshListCounts($conn, $poolId);

        return [
            'processed_count' => $deleted,
            'success_count' => $deleted,
            'failed_count' => 0,
            'deleted_count' => $deleted,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processRemoveDuplicates(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $totalToDelete = (int) $conn->fetchOne(
            'SELECT COALESCE(SUM(t.cnt - 1), 0)
               FROM (
                    SELECT COUNT(*) AS cnt
                      FROM email_data_pool
                     WHERE pool_list_id = ?
                     GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                    HAVING COUNT(*) > 1
               ) t',
            [$poolId]
        );

        $deleted = 0;
        $lastId = 0;
        while (true) {
            $ids = $conn->fetchFirstColumn(
                "SELECT d1.id
                   FROM email_data_pool d1
                   JOIN email_data_pool d2
                     ON d1.pool_list_id = d2.pool_list_id
                    AND COALESCE(d1.normalized_email, LOWER(TRIM(d1.email))) = COALESCE(d2.normalized_email, LOWER(TRIM(d2.email)))
                    AND d1.id > d2.id
                  WHERE d1.pool_list_id = ?
                    AND d1.id > ?
                  ORDER BY d1.id ASC
                  LIMIT $batchSize",
                [$poolId, $lastId]
            );
            if ($ids === []) {
                break;
            }

            $ids = array_values(array_map('intval', $ids));
            $lastId = max($ids);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $deleted += $conn->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)",
                array_merge([$poolId], $ids)
            );
            $jobService->updateProgress($jobId, $deleted, $totalToDelete, $deleted, 0);
        }

        $this->refreshListCounts($conn, $poolId);

        return [
            'processed_count' => $deleted,
            'success_count' => $deleted,
            'failed_count' => 0,
            'deleted_count' => $deleted,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processFixGmailTypos(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $hasNormCols = $this->hasNormalizationColumns($conn);
        $typos = [
            'gmial.com','gamil.com','gmai.com','gmail.co','gmail.con','gmal.com',
            'gmaill.com','gml.com','gnail.com','gmaiil.com','gmail.cm','gmail.om','gmail.com.tr','gmail.coom','gmail.comm',
        ];
        $inTypos = implode(',', array_fill(0, count($typos), '?'));
        $totalToFix = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool
              WHERE pool_list_id = ?
                AND INSTR(email, '@') > 1
                AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($inTypos)",
            array_merge([$poolId], $typos)
        );

        $processed = 0;
        $fixed = 0;
        $lastId = 0;
        while (true) {
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                    AND INSTR(email, '@') > 1
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($inTypos)
                  ORDER BY id ASC
                  LIMIT $batchSize",
                array_merge([$poolId, $lastId], $typos)
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $lastId = max($lastId, $id);
                if ($id < 1 || !str_contains($email, '@')) {
                    continue;
                }
                [$local] = explode('@', $email, 2);
                if ($local === '') {
                    continue;
                }
                $normalized = $local . '@gmail.com';
                if ($hasNormCols) {
                    $conn->executeStatement(
                        "UPDATE email_data_pool
                            SET email = ?, normalized_email = LOWER(TRIM(?)), domain = 'gmail.com', updated_at = NOW()
                          WHERE id = ? AND pool_list_id = ?",
                        [$normalized, $normalized, $id, $poolId]
                    );
                } else {
                    $conn->executeStatement(
                        "UPDATE email_data_pool
                            SET email = ?, updated_at = NOW()
                          WHERE id = ? AND pool_list_id = ?",
                        [$normalized, $id, $poolId]
                    );
                }
                $fixed++;
                $processed++;
            }
            $jobService->updateProgress($jobId, $processed, $totalToFix, $fixed, 0);
        }

        $this->refreshListCounts($conn, $poolId);

        return [
            'processed_count' => $processed,
            'success_count' => $fixed,
            'failed_count' => 0,
            'fixed_count' => $fixed,
        ];
    }

    private function hasNormalizationColumns(\Doctrine\DBAL\Connection $conn): bool
    {
        try {
            $dbName = (string) $conn->getDatabase();
            $norm = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'email_data_pool', 'normalized_email']
            ) > 0;
            $domain = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'email_data_pool', 'domain']
            ) > 0;

            return $norm && $domain;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string>
     */
    private function processExportPool(
        EntityManagerInterface $em,
        EmailDataPoolJobService $jobService,
        int $jobId,
        array $job,
        int $poolId,
        int $batchSize
    ): array {
        $conn = $em->getConnection();
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $scope = (string) ($payload['scope'] ?? 'full_csv');
        $listName = trim((string) ($payload['list_name'] ?? ('list-' . $poolId)));
        $safeList = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $listName) ?: ('list_' . $poolId);
        $timestamp = date('Ymd_His');
        $ext = $scope === 'full_csv' ? 'csv' : 'txt';
        $filename = sprintf('pool_%d_%s_%s.%s', $poolId, $scope, $timestamp, $ext);

        $exportPath = rtrim((string) ($_ENV['DATA_POOL_EXPORT_PATH'] ?? 'storage/exports'), '/');
        $baseDir = dirname(__DIR__, 3) . '/' . ltrim($exportPath, '/');
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('Export klasörü oluşturulamadı: ' . $baseDir);
        }
        $path = $baseDir . '/' . $filename;
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Export dosyası oluşturulamadı.');
        }

        $total = max(0, (int) ($job['total_count'] ?? 0));
        $processed = 0;
        $written = 0;
        $lastId = 0;
        $limit = max(1000, min(50000, $batchSize));
        $typos = [
            'gmial.com','gamil.com','gmai.com','gmail.co','gmail.con','gmal.com',
            'gmaill.com','gml.com','gnail.com','gmaiil.com','gmail.cm','gmail.om','gmail.com.tr','gmail.coom','gmail.comm',
        ];
        $typoPlaceholders = implode(',', array_fill(0, count($typos), '?'));

        if ($scope === 'full_csv') {
            fputcsv($fp, ['id', 'email', 'name', 'status', 'created_at']);
        }

        try {
            while (true) {
                if ($scope === 'non_gmail') {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT id, email
                           FROM email_data_pool
                          WHERE pool_list_id = ?
                            AND id > ?
                            AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
                          ORDER BY id ASC
                          LIMIT $limit",
                        [$poolId, $lastId]
                    );
                } elseif ($scope === 'typo_gmail') {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT id, email
                           FROM email_data_pool
                          WHERE pool_list_id = ?
                            AND id > ?
                            AND INSTR(email, '@') > 1
                            AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoPlaceholders)
                          ORDER BY id ASC
                          LIMIT $limit",
                        array_merge([$poolId, $lastId], $typos)
                    );
                } else {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT id, email, COALESCE(name, '') AS name, is_active, created_at
                           FROM email_data_pool
                          WHERE pool_list_id = ?
                            AND id > ?
                          ORDER BY id ASC
                          LIMIT $limit",
                        [$poolId, $lastId]
                    );
                }

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $lastId = (int) ($row['id'] ?? $lastId);
                    $email = trim((string) ($row['email'] ?? ''));
                    if ($email === '') {
                        continue;
                    }
                    if ($scope === 'full_csv') {
                        fputcsv($fp, [
                            (int) ($row['id'] ?? 0),
                            $email,
                            (string) ($row['name'] ?? ''),
                            ((int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'passive'),
                            (string) ($row['created_at'] ?? ''),
                        ]);
                    } else {
                        fwrite($fp, $email . PHP_EOL);
                    }
                    $written++;
                }

                $processed += count($rows);
                $jobService->updateProgress($jobId, $processed, max(1, $total), $written, 0);
            }
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($path);
            throw $e;
        }

        fclose($fp);
        $downloadUrl = '/admin/email-data-pool/exports/' . rawurlencode($filename);

        return [
            'processed_count' => $processed,
            'success_count' => $written,
            'failed_count' => 0,
            'download_url' => $downloadUrl,
            'filename' => $filename,
            'scope' => $scope,
            'label' => $safeList,
        ];
    }

    private function refreshListCounts(\Doctrine\DBAL\Connection $conn, int $poolId): void
    {
        $row = $conn->fetchAssociative(
            'SELECT COUNT(*) AS total_count, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count FROM email_data_pool WHERE pool_list_id = ?',
            [$poolId]
        ) ?: [];
        $total = (int) ($row['total_count'] ?? 0);
        $active = (int) ($row['active_count'] ?? 0);

        $conn->executeStatement(
            'UPDATE email_data_pool_lists
                SET total_count = ?, active_count = ?, passive_count = ?, updated_count_at = NOW()
              WHERE id = ?',
            [$total, $active, max(0, $total - $active), $poolId]
        );
    }
}
