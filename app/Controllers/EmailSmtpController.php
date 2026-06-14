<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Services\AlibabaDirectMailReportService;
use App\Application\Services\EmailSmtpService;
use App\Application\Services\EmailSmtpSelector;
use App\Domain\Entities\EmailSmtpAccount;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailSmtpController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig,
        private EmailSmtpService $smtpService,
        private EmailSmtpSelector $smtpSelector,
        private \App\Application\Services\EmailSendingConfigService $sendingConfigService,
        private ?AlibabaDirectMailReportService $alibabaReportService = null
    ) {
    }

    /**
     * SMTP listesi
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $status = trim((string) ($params['status'] ?? 'all'));
        $provider = trim((string) ($params['provider'] ?? 'all'));
        $sort = trim((string) ($params['sort'] ?? 'newest'));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? 20);
        if ($perPage < 10) {
            $perPage = 20;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $qb = $this->createListQueryBuilder($search, $status, $provider, $sort);

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $smtps = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $stats = $this->smtpService->getStats();
        $sendingConfig = $this->sendingConfigService->getConfig();
        $globalTotals = $this->smtpSelector->getGlobalUsageTotals();
        $deliveryReports = $this->getAlibabaReportService()->getDailySummary(14);

        $html = $this->twig->render('email-smtp/index.twig', [
            'smtps' => $smtps,
            'stats' => $stats,
            'search' => $search,
            'status' => $status,
            'provider' => $provider,
            'sort' => $sort,
            'per_page' => $perPage,
            'page' => $page,
            'total' => $total,
            'total_pages' => $totalPages,
            'sending_config' => $sendingConfig,
            'global_totals' => $globalTotals,
            'delivery_reports' => $deliveryReports,
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null
        ]);
        
        // Flash messages'ı temizle
        unset($_SESSION['success'], $_SESSION['error']);
        
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * SMTP kaydetme - Limitler plan config'den alınır
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $config = $this->sendingConfigService->getConfig();

        try {
            $smtp = new EmailSmtpAccount();
            $smtp->setName($data['name']);
            $smtp->setHost($data['host']);
            $smtp->setPort((int) $data['port']);
            $smtp->setUsername($data['username']);
            
            // Şifreyi encrypt et
            $encryptedPassword = $this->smtpService->encryptPassword($data['password']);
            $smtp->setPassword($encryptedPassword);
            
            $smtp->setEncryption($data['encryption'] ?? 'tls');
            $smtp->setFromEmail($data['from_email']);
            $smtp->setFromName($data['from_name'] ?? '');
            // Limitler plan config'den - tüm SMTP'ler için tek limit
            $smtp->setDailyLimit($config['daily_limit']);
            $smtp->setHourlyLimit($config['hourly_limit']);
            $smtp->setMinuteLimit($config['minute_limit']);
            $smtp->setPriority((int) ($data['priority'] ?? 1));
            $smtp->setIsActive(isset($data['is_active']));

            $this->em->persist($smtp);
            $this->em->flush();

            $_SESSION['success'] = sprintf(
                'SMTP hesabı "%s" başarıyla eklendi. Plan: %s/gün, Öncelik: %d',
                $data['name'],
                number_format($config['daily_limit']),
                $data['priority'] ?? 1
            );
            
            return $response
                ->withHeader('Location', '/admin/email-smtp')
                ->withStatus(302);

        } catch (\Exception $e) {
            $_SESSION['error'] = 'SMTP eklenirken hata: ' . $e->getMessage();
            
            return $response
                ->withHeader('Location', '/admin/email-smtp')
                ->withStatus(302);
        }
    }

    /**
     * SMTP düzenleme verilerini getir (JSON)
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);

        if (!$smtp) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'SMTP hesabı bulunamadı'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }

        $config = $this->sendingConfigService->getConfig();
        $data = [
            'id' => $smtp->getId(),
            'name' => $smtp->getName(),
            'host' => $smtp->getHost(),
            'port' => $smtp->getPort(),
            'username' => $smtp->getUsername(),
            'encryption' => $smtp->getEncryption(),
            'from_email' => $smtp->getFromEmail(),
            'from_name' => $smtp->getFromName(),
            'daily_limit' => $config['daily_limit'],
            'hourly_limit' => $config['hourly_limit'],
            'minute_limit' => $config['minute_limit'],
            'priority' => $smtp->getPriority(),
            'is_active' => $smtp->isActive(),
            'total_sent' => $smtp->getTotalSent(),
            'total_failed' => $smtp->getTotalFailed(),
            'success_rate' => $smtp->getSuccessRate(),
            'daily_sent' => $smtp->getDailySent(),
            'hourly_sent' => $smtp->getHourlySent(),
            'minute_sent' => $smtp->getMinuteSent(),
            'usage_percentage' => $config['daily_limit'] > 0 ? ($smtp->getDailySent() / $config['daily_limit']) * 100 : 0
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Alibaba DirectMail raporlarini cekip gunluk tabloya kaydet
     */
    public function syncDeliveryReports(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $days = (int) ($data['days'] ?? 14);

        @set_time_limit(300);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '300');
        }

        try {
            $result = $this->getAlibabaReportService()->syncRecentReports($days);

            if (!empty($result['errors'])) {
                $_SESSION['error'] = 'Raporlar kismen senkronlandi: ' . implode(' | ', array_slice($result['errors'], 0, 3));
            } else {
                $_SESSION['success'] = sprintf('Delivery report senkronu tamamlandi (%d kayit).', (int) ($result['synced'] ?? 0));
            }
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Delivery report senkron hatasi: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/email-smtp')
            ->withStatus(302);
    }

    /**
     * SMTP güncelleme
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);

        if (!$smtp) {
            $_SESSION['error'] = 'SMTP hesabı bulunamadı';
            return $response
                ->withHeader('Location', '/admin/email-smtp')
                ->withStatus(302);
        }

        $data = $request->getParsedBody();
        $config = $this->sendingConfigService->getConfig();

        try {
            $smtp->setName($data['name']);
            $smtp->setHost($data['host']);
            $smtp->setPort((int) $data['port']);
            $smtp->setUsername($data['username']);
            
            // Eğer yeni şifre girildiyse güncelle
            if (!empty($data['password'])) {
                $encryptedPassword = $this->smtpService->encryptPassword($data['password']);
                $smtp->setPassword($encryptedPassword);
            }
            
            $smtp->setEncryption($data['encryption'] ?? 'tls');
            $smtp->setFromEmail($data['from_email']);
            $smtp->setFromName($data['from_name'] ?? '');
            // Limitler plan config'den - tüm SMTP'ler için tek limit
            $smtp->setDailyLimit($config['daily_limit']);
            $smtp->setHourlyLimit($config['hourly_limit']);
            $smtp->setMinuteLimit($config['minute_limit']);
            $smtp->setPriority((int) ($data['priority'] ?? 1));
            $smtp->setIsActive(isset($data['is_active']));

            $this->em->persist($smtp);
            $this->em->flush();

            $_SESSION['success'] = 'SMTP hesabı başarıyla güncellendi';

        } catch (\Exception $e) {
            $_SESSION['error'] = 'SMTP güncellenirken hata: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/email-smtp')
            ->withStatus(302);
    }

    /**
     * SMTP silme
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);

        if (!$smtp) {
            $_SESSION['error'] = 'SMTP hesabı bulunamadı';
            return $response
                ->withHeader('Location', '/admin/email-smtp')
                ->withStatus(302);
        }

        try {
            $this->em->remove($smtp);
            $this->em->flush();

            $_SESSION['success'] = 'SMTP hesabı başarıyla silindi';

        } catch (\Exception $e) {
            $_SESSION['error'] = 'SMTP silinirken hata: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/email-smtp')
            ->withStatus(302);
    }

    /**
     * Seçili SMTP hesaplarını toplu sil (ilişkili kayıtlar SET NULL ile kalır)
     */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $idsRaw = $data['ids'] ?? [];
        if (!is_array($idsRaw)) {
            $idsRaw = $idsRaw !== '' && $idsRaw !== null ? [$idsRaw] : [];
        }

        $ids = [];
        foreach ($idsRaw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);

        if ($ids === []) {
            $_SESSION['error'] = 'Silinecek SMTP seçilmedi';

            return $response
                ->withHeader('Location', '/admin/email-smtp')
                ->withStatus(302);
        }

        $maxBatch = 200;
        if (count($ids) > $maxBatch) {
            $_SESSION['error'] = sprintf('Tek seferde en fazla %d SMTP silebilirsiniz.', $maxBatch);

            return $response
                ->withHeader('Location', '/admin/email-smtp')
                ->withStatus(302);
        }

        $deleted = 0;
        $conn = $this->em->getConnection();

        try {
            $conn->beginTransaction();

            foreach ($ids as $id) {
                $smtp = $this->em->find(EmailSmtpAccount::class, $id);
                if ($smtp instanceof EmailSmtpAccount) {
                    $this->em->remove($smtp);
                    ++$deleted;
                }
            }

            $this->em->flush();
            $conn->commit();

            $_SESSION['success'] = $deleted > 0
                ? sprintf('%d SMTP hesabı silindi.', $deleted)
                : 'Silinecek hesap bulunamadı (seçimler geçersiz olabilir).';
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->em->clear();

            $_SESSION['error'] = 'Toplu silme sırasında hata: ' . $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/admin/email-smtp')
            ->withStatus(302);
    }

    /**
     * SMTP aktif/pasif toggle
     */
    public function toggleStatus(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);

        if (!$smtp) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'SMTP hesabı bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        try {
            $body = json_decode((string) $request->getBody(), true);
            if (is_array($body) && array_key_exists('is_active', $body)) {
                $smtp->setIsActive((bool) $body['is_active']);
            } else {
                $smtp->setIsActive(!$smtp->isActive());
            }
            $this->em->persist($smtp);
            $this->em->flush();

            $response->getBody()->write(json_encode([
                'success' => true,
                'is_active' => $smtp->isActive(),
                'message' => 'Durum güncellendi'
            ]));

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function bulkSetStatus(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $idsRaw = $data['ids'] ?? [];
        $isActive = filter_var($data['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (!is_array($idsRaw) || $isActive === null) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Geçersiz istek',
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $ids = [];
        foreach ($idsRaw as $idRaw) {
            $id = (int) $idRaw;
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        $ids = array_keys($ids);
        if ($ids === []) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'En az bir SMTP seçin',
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        }

        $updated = 0;
        foreach ($ids as $id) {
            $smtp = $this->em->find(EmailSmtpAccount::class, $id);
            if ($smtp instanceof EmailSmtpAccount) {
                $smtp->setIsActive($isActive);
                ++$updated;
            }
        }
        $this->em->flush();

        $response->getBody()->write(json_encode([
            'success' => true,
            'updated' => $updated,
            'is_active' => $isActive,
            'message' => $isActive ? 'Seçilen SMTP hesapları aktif edildi.' : 'Seçilen SMTP hesapları pasif edildi.',
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function resetSingleLimits(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);
        if (!$smtp) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'SMTP hesabı bulunamadı',
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $smtp->resetDailySent();
        $smtp->resetHourlySent();
        $smtp->resetMinuteSent();
        $this->em->flush();

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => 'SMTP limit/throttle sayaçları sıfırlandı.',
        ], JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * SMTP bağlantı testi (kayıtlı SMTP için)
     */
    public function test(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);

        if (!$smtp) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'SMTP hesabı bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // JSON body'yi oku
        $body = (string) $request->getBody();
        $data = json_decode($body, true) ?? [];
        $testEmail = $data['test_email'] ?? null;

        $result = $this->smtpService->testSmtpConnection($smtp, $testEmail);

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * SMTP gönderen domaini için DNS/kimlik kontrolü
     */
    public function domainCheck(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);
        if (!$smtp) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'SMTP hesabı bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $fromEmail = $smtp->getFromEmail();
        $domain = $this->extractDomain($fromEmail);

        if (!$domain) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Gönderen email domaini okunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $txtRecords = $this->getTxtRecords($domain);
        $spfFound = $this->containsTxtValue($txtRecords, 'v=spf1');
        $dmarcRecords = $this->getTxtRecords('_dmarc.' . $domain);
        $dmarcFound = $this->containsTxtValue($dmarcRecords, 'v=dmarc1');

        $mxRecords = @dns_get_record($domain, DNS_MX) ?: [];
        $aRecords = @dns_get_record($domain, DNS_A) ?: [];
        $aaaaRecords = @dns_get_record($domain, DNS_AAAA) ?: [];

        $selectors = ['default', 'mail', 'smtp', 'dkim', 'aliyun', 'dm'];
        $dkimFound = false;
        $dkimSelector = null;
        foreach ($selectors as $selector) {
            $dkimDomain = $selector . '._domainkey.' . $domain;
            $dkimTxt = $this->getTxtRecords($dkimDomain);
            $dkimCname = @dns_get_record($dkimDomain, DNS_CNAME) ?: [];
            if ($this->containsTxtValue($dkimTxt, 'v=dkim1') || !empty($dkimCname)) {
                $dkimFound = true;
                $dkimSelector = $selector;
                break;
            }
        }

        $score = 0;
        $score += $spfFound ? 30 : 0;
        $score += $dmarcFound ? 30 : 0;
        $score += $dkimFound ? 30 : 0;
        $score += (!empty($mxRecords) || !empty($aRecords) || !empty($aaaaRecords)) ? 10 : 0;

        $response->getBody()->write(json_encode([
            'success' => true,
            'smtp_id' => $smtp->getId(),
            'smtp_name' => $smtp->getName(),
            'from_email' => $fromEmail,
            'domain' => $domain,
            'score' => $score,
            'checks' => [
                'spf' => [
                    'ok' => $spfFound,
                    'records' => $txtRecords
                ],
                'dmarc' => [
                    'ok' => $dmarcFound,
                    'records' => $dmarcRecords
                ],
                'dkim' => [
                    'ok' => $dkimFound,
                    'selector' => $dkimSelector
                ],
                'mx' => [
                    'ok' => !empty($mxRecords),
                    'count' => count($mxRecords)
                ],
                'a_or_aaaa' => [
                    'ok' => !empty($aRecords) || !empty($aaaaRecords),
                    'a_count' => count($aRecords),
                    'aaaa_count' => count($aaaaRecords)
                ]
            ],
            'recommendations' => $this->buildDomainRecommendations($spfFound, $dmarcFound, $dkimFound, !empty($mxRecords))
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * SMTP bağlantı testi (form verileriyle, kaydetmeden önce)
     */
    public function testConnection(Request $request, Response $response): Response
    {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if (!$data) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Geçersiz veri'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        try {
            // Geçici SMTP hesabı oluştur
            $tempSmtp = new EmailSmtpAccount();
            $tempSmtp->setName('Test');
            $tempSmtp->setHost($data['host']);
            $tempSmtp->setPort((int) $data['port']);
            $tempSmtp->setUsername($data['username']);
            
            // Şifreyi encrypt et (test için)
            $encryptedPassword = $this->smtpService->encryptPassword($data['password']);
            $tempSmtp->setPassword($encryptedPassword);
            
            $tempSmtp->setEncryption($data['encryption'] ?? 'tls');
            $tempSmtp->setFromEmail($data['from_email']);
            $tempSmtp->setFromName($data['from_name'] ?? '');
            
            // Test et (email göndermeden)
            $result = $this->smtpService->testSmtpConnection($tempSmtp, null);

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Hata: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
    }

    /**
     * Toplu SMTP içe aktarma.
     * - email:password satırları (ortak host/port form alanlarından)
     * - CSV (Dışa aktar ile aynı format; her satırda host/port/şifre)
     * JSON body: bulk_lines, import_format? (auto|lines|csv), host?, port?, ...
     */
    public function bulkImport(Request $request, Response $response): Response
    {
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $data = $request->getParsedBody() ?: [];
        }

        $raw = trim((string) ($data['bulk_lines'] ?? ''));
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        if ($raw === '') {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'İçe aktarılacak veri boş.',
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(400);
        }

        $importFormat = trim((string) ($data['import_format'] ?? 'auto'));
        if ($importFormat === 'auto') {
            $importFormat = $this->detectBulkImportFormat($raw);
        }

        $testEach = filter_var($data['test_after_each'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($testEach && function_exists('set_time_limit')) {
            @set_time_limit(900);
        }

        $config = $this->sendingConfigService->getConfig();

        if ($importFormat === 'csv') {
            $result = $this->bulkImportFromCsv($raw, $config, $testEach);
        } else {
            $host = trim((string) ($data['host'] ?? 'smtpdm-ap-southeast-1.aliyuncs.com'));
            $port = (int) ($data['port'] ?? 465);
            $encryption = strtolower(trim((string) ($data['encryption'] ?? 'ssl')));
            if (!in_array($encryption, ['ssl', 'tls', ''], true)) {
                $encryption = 'ssl';
            }
            $priority = max(1, min(100, (int) ($data['priority'] ?? 5)));
            $isActive = filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($host === '') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'SMTP host boş olamaz.',
                ], JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(400);
            }

            if ($port < 1 || $port > 65535) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Geçersiz port.',
                ], JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus(400);
            }

            $result = $this->bulkImportFromLines($raw, $host, $port, $encryption, $priority, $isActive, $config, $testEach);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'message' => sprintf(
                '%d hesap eklendi, %d atlandı (zaten var), %d hata. (%s)',
                count($result['created']),
                count($result['skipped']),
                count($result['failed']),
                $importFormat === 'csv' ? 'CSV' : 'satır'
            ),
            'import_format' => $importFormat,
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'tests' => $result['tests'],
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    /**
     * @return array{created: list<string>, skipped: list<string>, failed: list<array<string, string>>, tests: list<array<string, mixed>>}
     */
    private function bulkImportFromLines(
        string $raw,
        string $host,
        int $port,
        string $encryption,
        int $priority,
        bool $isActive,
        array $config,
        bool $testEach
    ): array {
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $created = [];
        $skipped = [];
        $failed = [];
        $tests = [];
        $processed = 0;
        $maxLines = 400;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($processed >= $maxLines) {
                $failed[] = ['line' => $line, 'error' => "Üst sınır: {$maxLines} hesap"];
                break;
            }
            ++$processed;

            $pos = strpos($line, ':');
            if ($pos === false) {
                $failed[] = ['line' => $line, 'error' => 'email:password formatı gerekli'];

                continue;
            }
            $email = strtolower(trim(substr($line, 0, $pos)));
            $password = trim(substr($line, $pos + 1));

            if ($email === '' || $password === '') {
                $failed[] = ['line' => $line, 'error' => 'Email veya şifre boş'];

                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = ['line' => $line, 'error' => 'Geçersiz e-posta'];

                continue;
            }

            $rowResult = $this->importSmtpAccount([
                'username' => $email,
                'password' => $password,
                'host' => $host,
                'port' => $port,
                'encryption' => $encryption !== '' ? $encryption : 'ssl',
                'name' => $email,
                'from_email' => $email,
                'from_name' => strstr($email, '@', true) ?: '',
                'is_active' => $isActive,
                'priority' => $priority,
                'daily_limit' => $config['daily_limit'],
                'hourly_limit' => $config['hourly_limit'],
                'minute_limit' => $config['minute_limit'],
            ], $config, $testEach);

            if ($rowResult['status'] === 'created') {
                $created[] = $email;
                if ($rowResult['test'] !== null) {
                    $tests[] = $rowResult['test'];
                }
            } elseif ($rowResult['status'] === 'skipped') {
                $skipped[] = $email;
            } else {
                $failed[] = ['line' => $line, 'error' => $rowResult['error'] ?? 'Bilinmeyen hata'];
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'tests' => $tests];
    }

    /**
     * @return array{created: list<string>, skipped: list<string>, failed: list<array<string, string>>, tests: list<array<string, mixed>>}
     */
    private function bulkImportFromCsv(string $raw, array $config, bool $testEach): array
    {
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $created = [];
        $skipped = [];
        $failed = [];
        $tests = [];
        $processed = 0;
        $maxLines = 10000;
        $headerMap = null;
        $delimiter = ';';

        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cols = str_getcsv($line, $delimiter);
            if ($cols === false || $cols === []) {
                continue;
            }

            if ($headerMap === null) {
                $normalized = array_map(fn (string $h) => $this->normalizeImportHeader($h), $cols);
                if (in_array('username', $normalized, true) && in_array('password', $normalized, true)) {
                    $headerMap = [];
                    foreach ($normalized as $idx => $key) {
                        if ($key !== '') {
                            $headerMap[$key] = $idx;
                        }
                    }
                    continue;
                }
                if (count($cols) >= 4 && str_contains($line, ';')) {
                    $headerMap = [
                        'username' => 0,
                        'password' => 1,
                        'host' => 2,
                        'port' => 3,
                        'encryption' => 4,
                        'name' => 5,
                        'from_email' => 6,
                        'from_name' => 7,
                        'is_active' => 8,
                        'priority' => 9,
                        'daily_limit' => 10,
                        'hourly_limit' => 11,
                        'minute_limit' => 12,
                    ];
                } else {
                    $failed[] = ['line' => (string) ($lineNum + 1), 'error' => 'CSV başlık satırı tanınmadı (Kullanıcı;Şifre;Host;… gerekli)'];
                    break;
                }
            }

            if ($processed >= $maxLines) {
                $failed[] = ['line' => (string) ($lineNum + 1), 'error' => "Üst sınır: {$maxLines} hesap"];
                break;
            }
            ++$processed;

            $row = $this->csvRowToImportData($cols, $headerMap, $config);
            if ($row === null) {
                $failed[] = ['line' => (string) ($lineNum + 1), 'error' => 'Satır okunamadı'];
                continue;
            }

            if ($row['error'] !== null) {
                $failed[] = ['line' => (string) ($lineNum + 1), 'error' => $row['error']];
                continue;
            }

            $username = strtolower($row['data']['username']);
            $rowResult = $this->importSmtpAccount($row['data'], $config, $testEach);

            if ($rowResult['status'] === 'created') {
                $created[] = $username;
                if ($rowResult['test'] !== null) {
                    $tests[] = $rowResult['test'];
                }
            } elseif ($rowResult['status'] === 'skipped') {
                $skipped[] = $username;
            } else {
                $failed[] = ['line' => (string) ($lineNum + 1), 'error' => $rowResult['error'] ?? 'Bilinmeyen hata'];
            }
        }

        if ($headerMap === null) {
            $failed[] = ['line' => '1', 'error' => 'CSV başlık satırı bulunamadı'];
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'tests' => $tests];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{status: string, error?: string, test?: array<string, mixed>|null}
     */
    private function importSmtpAccount(array $data, array $config, bool $testEach): array
    {
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $host = trim((string) ($data['host'] ?? ''));
        $port = (int) ($data['port'] ?? 0);

        if ($username === '' || $password === '' || $host === '') {
            return ['status' => 'failed', 'error' => 'Kullanıcı, şifre ve host zorunlu'];
        }
        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'failed', 'error' => 'Geçersiz e-posta'];
        }
        if ($port < 1 || $port > 65535) {
            return ['status' => 'failed', 'error' => 'Geçersiz port'];
        }

        $dup = $this->em->getRepository(EmailSmtpAccount::class)->findOneBy(['username' => $username]);
        if ($dup !== null) {
            return ['status' => 'skipped'];
        }

        $encryption = strtolower(trim((string) ($data['encryption'] ?? 'ssl')));
        if (!in_array($encryption, ['ssl', 'tls', ''], true)) {
            $encryption = 'ssl';
        }

        try {
            $smtp = new EmailSmtpAccount();
            $smtp->setName(trim((string) ($data['name'] ?? $username)) ?: $username);
            $smtp->setHost($host);
            $smtp->setPort($port);
            $smtp->setUsername($username);
            $smtp->setPassword($this->smtpService->encryptPassword($password));
            $smtp->setEncryption($encryption !== '' ? $encryption : 'ssl');
            $fromEmail = trim((string) ($data['from_email'] ?? $username)) ?: $username;
            $smtp->setFromEmail($fromEmail);
            $smtp->setFromName(trim((string) ($data['from_name'] ?? '')));
            $smtp->setDailyLimit(max(1, (int) ($data['daily_limit'] ?? $config['daily_limit'])));
            $smtp->setHourlyLimit(max(1, (int) ($data['hourly_limit'] ?? $config['hourly_limit'])));
            $smtp->setMinuteLimit(max(1, (int) ($data['minute_limit'] ?? $config['minute_limit'])));
            $smtp->setPriority(max(1, min(100, (int) ($data['priority'] ?? 5))));
            $smtp->setIsActive((bool) ($data['is_active'] ?? true));

            $this->em->persist($smtp);
            $this->em->flush();

            $test = null;
            if ($testEach) {
                $test = array_merge(
                    ['email' => $username],
                    $this->smtpService->testSmtpConnection($smtp, null)
                );
            }

            return ['status' => 'created', 'test' => $test];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function detectBulkImportFormat(string $raw): string
    {
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, ';')) {
                $cols = str_getcsv($line, ';');
                if ($cols !== false && $cols !== []) {
                    $normalized = array_map(fn (string $h) => $this->normalizeImportHeader($h), $cols);
                    if (in_array('username', $normalized, true) && in_array('password', $normalized, true)) {
                        return 'csv';
                    }
                }
            }
            break;
        }

        return 'lines';
    }

    private function normalizeImportHeader(string $header): string
    {
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = strtr($header, [
            'ş' => 's', 'ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c',
            'â' => 'a', 'î' => 'i', 'û' => 'u',
        ]);
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
        $header = trim($header, '_');

        return match ($header) {
            'kullanici', 'username', 'user', 'eposta', 'email' => 'username',
            'sifre', 'password', 'parola' => 'password',
            'host', 'sunucu' => 'host',
            'port' => 'port',
            'sifreleme', 'encryption', 'encrypt' => 'encryption',
            'ad', 'name', 'smtp_adi' => 'name',
            'gonderen_e_posta', 'gonderen_email', 'from_email', 'from' => 'from_email',
            'gonderen_ad', 'from_name' => 'from_name',
            'aktif', 'active', 'is_active', 'durum' => 'is_active',
            'oncelik', 'priority' => 'priority',
            'gunluk_limit', 'daily_limit' => 'daily_limit',
            'saatlik_limit', 'hourly_limit' => 'hourly_limit',
            'dakikalik_limit', 'minute_limit' => 'minute_limit',
            default => $header,
        };
    }

    /**
     * @param list<string> $cols
     * @param array<string, int> $headerMap
     * @return array{data: array<string, mixed>, error: ?string}|null
     */
    private function csvRowToImportData(array $cols, array $headerMap, array $config): ?array
    {
        $get = function (string $key) use ($cols, $headerMap): string {
            if (!isset($headerMap[$key])) {
                return '';
            }
            $idx = $headerMap[$key];

            return trim((string) ($cols[$idx] ?? ''));
        };

        $username = strtolower($get('username'));
        $password = $get('password');
        $host = $get('host');
        $port = (int) $get('port');

        if ($username === '' && $password === '' && $host === '') {
            return null;
        }

        if ($username === '' || $password === '' || $host === '') {
            return ['data' => [], 'error' => 'Kullanıcı, şifre ve host zorunlu'];
        }

        return [
            'data' => [
                'username' => $username,
                'password' => $password,
                'host' => $host,
                'port' => $port > 0 ? $port : 465,
                'encryption' => $get('encryption') ?: 'ssl',
                'name' => $get('name') ?: $username,
                'from_email' => $get('from_email') ?: $username,
                'from_name' => $get('from_name'),
                'is_active' => $this->parseImportBool($get('is_active'), true),
                'priority' => (int) ($get('priority') !== '' ? $get('priority') : 5),
                'daily_limit' => (int) ($get('daily_limit') !== '' ? $get('daily_limit') : $config['daily_limit']),
                'hourly_limit' => (int) ($get('hourly_limit') !== '' ? $get('hourly_limit') : $config['hourly_limit']),
                'minute_limit' => (int) ($get('minute_limit') !== '' ? $get('minute_limit') : $config['minute_limit']),
            ],
            'error' => null,
        ];
    }

    private function parseImportBool(string $value, bool $default): bool
    {
        $v = mb_strtolower(trim($value), 'UTF-8');
        if ($v === '') {
            return $default;
        }

        return in_array($v, ['1', 'true', 'yes', 'evet', 'aktif', 'on', 'e'], true);
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', trim($email));
        if (count($parts) !== 2 || empty($parts[1])) {
            return null;
        }

        return strtolower($parts[1]);
    }

    private function getTxtRecords(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_TXT) ?: [];
        $values = [];
        foreach ($records as $record) {
            $txt = $record['txt'] ?? ($record['entries'][0] ?? null);
            if ($txt) {
                $values[] = (string) $txt;
            }
        }

        return $values;
    }

    private function containsTxtValue(array $records, string $needle): bool
    {
        $needle = strtolower($needle);
        foreach ($records as $record) {
            if (str_contains(strtolower((string) $record), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function buildDomainRecommendations(bool $spf, bool $dmarc, bool $dkim, bool $mx): array
    {
        $items = [];
        if (!$spf) {
            $items[] = 'SPF kaydı ekleyin (v=spf1 ...).';
        }
        if (!$dkim) {
            $items[] = 'DKIM selector kaydınızı (TXT veya CNAME) DNS tarafında doğrulayın.';
        }
        if (!$dmarc) {
            $items[] = 'DMARC kaydı ekleyin (_dmarc.domain).';
        }
        if (!$mx) {
            $items[] = 'Domain için MX kaydı yok, alıcı sunucular spam puanı yükseltebilir.';
        }
        if (empty($items)) {
            $items[] = 'Temel domain ayarları sağlıklı görünüyor.';
        }

        return $items;
    }

    /**
     * SMTP günlük limitleri sıfırla (Manuel Sıfırlama - Tüm Limitler)
     */
    public function resetLimits(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody() ?? [];
            $type = (string) ($data['type'] ?? 'all');
            if (!in_array($type, ['daily', 'hourly', 'minute', 'all'], true)) {
                $type = 'all';
            }

            $smtps = $this->em->getRepository(\App\Domain\Entities\EmailSmtpAccount::class)->findAll();
            foreach ($smtps as $smtp) {
                if ($type === 'daily' || $type === 'all') $smtp->resetDailySent();
                if ($type === 'hourly' || $type === 'all') $smtp->resetHourlySent();
                if ($type === 'minute' || $type === 'all') $smtp->resetMinuteSent();
                $this->em->persist($smtp);
            }
            $this->em->flush();

            $count = count($smtps);
            $labels = ['daily' => 'günlük', 'hourly' => 'saatlik', 'minute' => 'dakikalık', 'all' => 'tüm'];
            $label = $labels[$type] ?? 'tüm';

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "{$count} SMTP hesabının {$label} sayaçları sıfırlandı."
            ]));
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * En uygun SMTP'yi seç (API)
     */
    public function selectBest(Request $request, Response $response): Response
    {
        try {
            $smtp = $this->smtpSelector->selectBestSmtp();

            if (!$smtp) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Kullanılabilir SMTP bulunamadı'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // Şifreyi decrypt et
            $password = $this->smtpService->decryptPassword($smtp->getPassword());

            $response->getBody()->write(json_encode([
                'success' => true,
                'smtp' => [
                    'id' => $smtp->getId(),
                    'name' => $smtp->getName(),
                    'host' => $smtp->getHost(),
                    'port' => $smtp->getPort(),
                    'username' => $smtp->getUsername(),
                    'password' => $password,
                    'encryption' => $smtp->getEncryption(),
                    'from_email' => $smtp->getFromEmail(),
                    'from_name' => $smtp->getFromName()
                ]
            ]));

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * SMTP kullanım kaydı (API)
     */
    public function recordUsage(Request $request, Response $response, array $args): Response
    {
        $smtp = $this->em->find(EmailSmtpAccount::class, (int) $args['id']);

        if (!$smtp) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'SMTP hesabı bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $data = $request->getParsedBody();
        $success = $data['success'] ?? false;
        $error = $data['error'] ?? null;

        try {
            $this->smtpService->recordUsage($smtp, $success, $error);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Kullanım kaydedildi'
            ]));

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * SMTP listesini CSV olarak dışa aktar (şifre dahil; Toplu içe aktar ile uyumlu format).
     */
    public function exportCsv(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $status = trim((string) ($params['status'] ?? 'all'));
        $provider = trim((string) ($params['provider'] ?? 'all'));
        $sort = trim((string) ($params['sort'] ?? 'newest'));

        $smtps = $this->createListQueryBuilder($search, $status, $provider, $sort)
            ->getQuery()
            ->getResult();

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            $response->getBody()->write('CSV oluşturulamadı.');
            return $response->withStatus(500);
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [
            'Kullanıcı', 'Şifre', 'Host', 'Port', 'Şifreleme', 'Ad', 'Gönderen E-posta', 'Gönderen Ad',
            'Aktif', 'Öncelik', 'Günlük Limit', 'Saatlik Limit', 'Dakikalık Limit',
        ], ';');

        foreach ($smtps as $smtp) {
            /** @var EmailSmtpAccount $smtp */
            $plainPassword = '';
            try {
                $plainPassword = $this->smtpService->decryptPassword($smtp->getPassword());
            } catch (\Throwable) {
                $plainPassword = '';
            }

            fputcsv($fp, [
                $smtp->getUsername(),
                $plainPassword,
                $smtp->getHost(),
                $smtp->getPort(),
                $smtp->getEncryption() ?? '',
                $smtp->getName(),
                $smtp->getFromEmail(),
                $smtp->getFromName() ?? '',
                $smtp->isActive() ? 'Evet' : 'Hayır',
                $smtp->getPriority(),
                $smtp->getDailyLimit(),
                $smtp->getHourlyLimit(),
                $smtp->getMinuteLimit(),
            ], ';');
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        $response->getBody()->write($csv !== false ? $csv : '');
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="smtp-hesaplari_' . date('Y-m-d_His') . '.csv"');
    }

    private function createListQueryBuilder(string $search, string $status, string $provider, string $sort): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('s')
            ->from(EmailSmtpAccount::class, 's');

        if ($search !== '') {
            $qb->andWhere('s.name LIKE :search OR s.host LIKE :search OR s.username LIKE :search OR s.fromEmail LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        if ($status === 'active') {
            $qb->andWhere('s.isActive = true');
        } elseif ($status === 'passive') {
            $qb->andWhere('s.isActive = false');
        } elseif ($status === 'healthy') {
            $qb->andWhere('s.isActive = true')
                ->andWhere('s.totalSent >= 100')
                ->andWhere('s.successRate >= 85')
                ->andWhere('(s.lastError IS NULL OR s.lastError = \'\')');
        } elseif ($status === 'risk') {
            $qb->andWhere('s.isActive = true')
                ->andWhere('(s.lastError IS NOT NULL AND s.lastError <> \'\') OR (s.totalSent >= 15 AND s.successRate < 85)');
        } elseif ($status === 'warming') {
            $qb->andWhere('s.isActive = true')
                ->andWhere('s.totalSent < 100');
        } elseif ($status === 'throttled') {
            $qb->andWhere('s.dailySent >= (s.dailyLimit * 0.9)');
        }

        if ($provider === 'alibaba') {
            $qb->andWhere('LOWER(s.host) LIKE :providerAlibaba')
                ->setParameter('providerAlibaba', '%aliy%');
        } elseif ($provider === 'mailgun') {
            $qb->andWhere('LOWER(s.host) LIKE :providerMailgun')
                ->setParameter('providerMailgun', '%mailgun%');
        } elseif ($provider === 'other') {
            $qb->andWhere('LOWER(s.host) NOT LIKE :providerAlibabaOther')
                ->andWhere('LOWER(s.host) NOT LIKE :providerMailgunOther')
                ->setParameter('providerAlibabaOther', '%aliy%')
                ->setParameter('providerMailgunOther', '%mailgun%');
        }

        if ($sort === 'most_sent') {
            $qb->orderBy('s.totalSent', 'DESC');
        } elseif ($sort === 'error_rate') {
            $qb->orderBy('s.totalFailed', 'DESC');
        } elseif ($sort === 'last_used') {
            $qb->orderBy('s.lastUsedAt', 'DESC');
        } else {
            $qb->orderBy('s.createdAt', 'DESC');
        }

        return $qb;
    }

    private function getAlibabaReportService(): AlibabaDirectMailReportService
    {
        if ($this->alibabaReportService instanceof AlibabaDirectMailReportService) {
            return $this->alibabaReportService;
        }

        $this->alibabaReportService = new AlibabaDirectMailReportService($this->em);
        return $this->alibabaReportService;
    }
}

