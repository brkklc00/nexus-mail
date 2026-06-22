<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Services\TelegramNotificationService;
use App\Domain\Entities\EmailOrder;
use App\Domain\Entities\EmailOrderEmail;
use App\Domain\Entities\EmailDataPool;
use App\Domain\Entities\EmailSmtp;
use App\Domain\Entities\User;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ApiController
{
    private const WORKER_SCHEMA_REQUIRED_COLUMNS = [
        'email_orders' => ['worker_paused', 'worker_stop_requested'],
    ];
    private const WORKER_SCHEMA_REQUIRED_TABLES = [
        'campaign_batch_metrics',
        'campaign_runtime_summary',
    ];

    private EntityManager $em;
    private array $settings;
    private \App\Application\Services\EmailSmtpService $emailSmtpService;
    private \App\Application\Services\EmailSmtpSelector $emailSmtpSelector;
    private \App\Application\Services\EmailSendingConfigService $emailSendingConfigService;
    private TelegramNotificationService $telegramNotificationService;

    public function __construct(
        EntityManager $em,
        array $settings,
        \App\Application\Services\EmailSmtpService $emailSmtpService,
        \App\Application\Services\EmailSmtpSelector $emailSmtpSelector,
        \App\Application\Services\EmailSendingConfigService $emailSendingConfigService,
        TelegramNotificationService $telegramNotificationService
    )
    {
        $this->em = $em;
        $this->settings = $settings;
        $this->emailSmtpService = $emailSmtpService;
        $this->emailSmtpSelector = $emailSmtpSelector;
        $this->emailSendingConfigService = $emailSendingConfigService;
        $this->telegramNotificationService = $telegramNotificationService;
    }

    /**
     * API Token kontrolü helper metodu
     */
    private function validateApiToken(Request $request, Response $response): ?Response
    {
        $headers = $request->getHeaders();
        $apiToken = $headers['X-Api-Token'][0] ?? $request->getQueryParams()['api_token'] ?? null;
        $validToken = $this->settings['api']['token'] ?? null;

        if (!$validToken || $apiToken !== $validToken) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Unauthorized: Invalid API token',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        return null;
    }

    /**
     * API: Başka panelden taşınan siparişi al (paneller arası sipariş taşıma).
     * Sadece sipariş bilgisi gelir; müşteri/alıcı/liste burada onay aşamasında seçilir.
     * POST /api/email-orders/receive  (X-Api-Token korumalı)
     */
    public function receiveTransferredOrder(Request $request, Response $response): Response
    {
        if ($authError = $this->validateApiToken($request, $response)) {
            return $authError;
        }

        $raw = (string) $request->getBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = $request->getParsedBody() ?: [];
        }

        $subject = trim((string) ($data['subject'] ?? ''));
        $body = (string) ($data['body'] ?? '');
        $total = (int) ($data['total'] ?? 0);

        if ($subject === '' || trim($body) === '' || $total < 1) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'subject, body ve total (>=1) zorunludur.',
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(422);
        }

        $deliveryPercentage = (int) ($data['delivery_percentage'] ?? 100);
        if ($deliveryPercentage < 1 || $deliveryPercentage > 100) {
            $deliveryPercentage = 100;
        }
        $rotation = (int) ($data['smtp_rotation_limit'] ?? 500);
        if ($rotation < 1) {
            $rotation = 500;
        }
        $sourceType = isset($data['source_type']) && $data['source_type'] !== '' ? (string) $data['source_type'] : null;

        try {
            // Sahip kullanıcı (kozmetik — gerçek müşteri onayda seçilir):
            // payload'daki kullanıcı adı → admin → ilk kullanıcı.
            $userRepo = $this->em->getRepository(User::class);
            $owner = null;
            $ownerUsername = trim((string) ($data['owner_username'] ?? ''));
            if ($ownerUsername !== '') {
                $owner = $userRepo->findOneBy(['username' => $ownerUsername]);
            }
            if (!$owner) {
                $owner = $userRepo->findOneBy(['username' => 'admin']);
            }
            if (!$owner) {
                $owner = $userRepo->findOneBy([], ['id' => 'ASC']);
            }
            if (!$owner) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Hedef panelde siparişe atanacak kullanıcı bulunamadı.',
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }

            $order = new \App\Domain\Entities\EmailOrder();
            $order->setUser($owner);
            $order->setSubject(mb_substr($subject, 0, 500));
            $order->setBody($body);
            // Şablon panele özgüdür (paneller arası taşınmaz) — null bırakılır.
            $order->setTemplate(null);
            $order->setTotal($total);
            $order->setCost((float) $total);
            $order->setStatus(\App\Domain\Enum\EmailOrderStatus::PENDING_APPROVAL);
            $order->setDeliveryPercentage($deliveryPercentage);
            $order->setSmtpRotationLimit($rotation);
            if ($sourceType !== null) {
                $order->setSourceType($sourceType);
            }

            $this->em->persist($order);
            $this->em->flush();

            error_log(sprintf(
                'Panel transfer alındı: yeni sipariş #%d (kaynak: %s #%s, total=%d)',
                (int) $order->getId(),
                (string) ($data['source_panel'] ?? '?'),
                (string) ($data['source_order_id'] ?? '?'),
                $total
            ));

            $response->getBody()->write(json_encode(['success' => true, 'order_id' => $order->getId()]));
            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            error_log('[receiveTransferredOrder] HATA: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Sipariş oluşturulamadı: ' . $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * API: Kullanıcının API Key'ini yenile
     * POST /api/regenerate-key
     */
    public function regenerateApiKey(Request $request, Response $response): Response
    {
        // Session kontrolü
        $userId = $_SESSION['user_id'] ?? null;

        if (!$userId) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Oturum bulunamadı',
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }

        try {
            $user = $this->em->find(User::class, $userId);

            if (!$user) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Kullanıcı bulunamadı',
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(404);
            }

            // Yeni API key oluştur (32 karakter random string)
            $newApiKey = bin2hex(random_bytes(16));
            $user->setApiKey($newApiKey);

            $this->em->flush();

            // Session'ı da güncelle
            if (isset($_SESSION['user'])) {
                $_SESSION['user']['api_key'] = $newApiKey;
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'API Key başarıyla yenilendi',
                'api_key' => $newApiKey,
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            error_log("Regenerate API Key error: " . $e->getMessage());

            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'API Key oluşturulamadı: ' . $e->getMessage(),
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }

    /**
     * API: Bekleyen email kampanyalarını getir
     * GET /api/email-campaigns/pending
     */
    public function getPendingEmailCampaigns(Request $request, Response $response): Response
    {
        // API Token kontrolü
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        try {
            $params = $request->getQueryParams();
            $limit = max(1, min(1000, (int) ($params['limit'] ?? 100)));
            // Claim edilebilir kayıtları DB saatine göre seç.
            // PHP/DB timezone farkı lock filtresinde yanlış pozitif üretebiliyor.
            $claimTtlSeconds = max(60, (int) ($_ENV['EMAIL_CLAIM_TTL_SECONDS'] ?? 900));
            $claimSql = "SELECT id
                 FROM email_orders
                 WHERE worker_paused = 0
                   AND (
                        status = 'pending'
                        OR (
                            status = 'processing'
                            AND (
                                locked_at IS NULL
                                OR locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)
                            )
                        )
                    )
                 ORDER BY created_at ASC
                 LIMIT " . (int) $limit;

            $claimableIds = $this->em->getConnection()->fetchFirstColumn(
                $claimSql,
                [$claimTtlSeconds],
                [ParameterType::INTEGER]
            );

            if (empty($claimableIds)) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'total_campaigns' => 0,
                    'campaigns' => [],
                ], JSON_UNESCAPED_UNICODE));

                return $response->withHeader('Content-Type', 'application/json');
            }

            $orders = $this->em->createQueryBuilder()
                ->select('o')
                ->from('App\Domain\Entities\EmailOrder', 'o')
                ->where('o.id IN (:ids)')
                ->setParameter('ids', array_map('intval', $claimableIds))
                ->orderBy('o.createdAt', 'ASC')
                ->getQuery()
                ->getResult();

            $campaigns = [];
            foreach ($orders as $order) {
                // Pool siparişleri: 0 EmailOrderEmail varsa pending = total (worker havuzdan çekecek)
                $pendingCount = 0;
                if ($order->isPoolOrder()) {
                    $allocatedCount = (int) $this->em->createQueryBuilder()
                        ->select('COUNT(e.id)')
                        ->from(EmailOrderEmail::class, 'e')
                        ->where('e.order = :order')
                        ->setParameter('order', $order)
                        ->getQuery()
                        ->getSingleScalarResult();
                    $pendingInDb = (int) $this->em->createQueryBuilder()
                        ->select('COUNT(e.id)')
                        ->from(EmailOrderEmail::class, 'e')
                        ->where('e.order = :order')
                        ->andWhere('e.status = :status')
                        ->setParameter('order', $order)
                        ->setParameter('status', 'pending')
                        ->getQuery()
                        ->getSingleScalarResult();
                    $pendingCount = $pendingInDb + max(0, $order->getTotal() - $allocatedCount);
                } else {
                    $pendingCount = (int) $this->em->createQueryBuilder()
                        ->select('COUNT(e.id)')
                        ->from(EmailOrderEmail::class, 'e')
                        ->where('e.order = :order')
                        ->andWhere('e.status = :status')
                        ->setParameter('order', $order)
                        ->setParameter('status', 'pending')
                        ->getQuery()
                        ->getSingleScalarResult();
                }

                $campaigns[] = [
                    'id' => $order->getId(),
                    'user_id' => $order->getUser()->getId(),
                    'user_name' => $order->getUser()->getName(),
                    'status' => $order->getStatus()->value,
                    'subject' => $order->getSubject(),
                    'body' => $order->getBody(),
                    'from_name' => 'Nexus',
                    'from_email' => 'noreply@nexus.com',
                    'delivery_percentage' => $order->getDeliveryPercentage(),
                    'email_delivery_percentage' => $order->getUser()->getEmailDeliveryPercentage(),
                    'total_emails' => $order->getTotal(),
                    'pending_emails' => (int) $pendingCount,
                    'last_pool_id' => $order->getLastPoolId(),
                    'smtp_rotation_limit' => $order->getSmtpRotationLimit(),
                    'created_at' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }

            $response->getBody()->write(json_encode([
                'success' => true,
                'total_campaigns' => count($campaigns),
                'campaigns' => $campaigns,
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            error_log('getPendingEmailCampaigns: ' . $e->getMessage());
            $errorPayload = $this->buildWorkerSchemaAwareErrorPayload($e, 'pending_campaigns_failed', 'Email kampanya listesi alınamadı.');
            $statusCode = (int) ($errorPayload['status_code'] ?? 500);
            unset($errorPayload['status_code']);
            $response->getBody()->write(json_encode($errorPayload, JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }

    /**
     * API: Email kampanyasını atomik claim et
     * POST /api/email-campaigns/{id}/claim
     */
    public function claimEmailCampaign(Request $request, Response $response, array $args): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $orderId = (int) ($args['id'] ?? 0);
        $body = (string) $request->getBody();
        $data = json_decode($body, true) ?: $request->getParsedBody() ?: [];
        $workerId = trim((string) ($data['worker_id'] ?? 'worker-unknown'));
        $claimTtlSeconds = max(60, (int) ($_ENV['EMAIL_CLAIM_TTL_SECONDS'] ?? 900));

        $conn = $this->em->getConnection();
        $claimed = false;
        $preClaimRow = $conn->fetchAssociative(
            'SELECT status, attempt_count FROM email_orders WHERE id = ?',
            [$orderId]
        ) ?: [];
        $previousStatus  = (string) ($preClaimRow['status'] ?? '');
        $previousAttempt = (int) ($preClaimRow['attempt_count'] ?? 0);

        try {
            $affected = $conn->executeStatement(
                "UPDATE email_orders
                 SET status = 'processing',
                     locked_at = NOW(),
                     locked_by = ?,
                     attempt_count = attempt_count + 1,
                     updated_at = NOW()
                 WHERE id = ?
                   AND worker_paused = 0
                   AND (
                        status = 'pending'
                        OR (
                            status = 'processing'
                            AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND) OR locked_by = ?)
                        )
                   )",
                [$workerId, $orderId, $claimTtlSeconds, $workerId]
            );
            $claimed = $affected > 0;
        } catch (\Throwable $e) {
            error_log('claimEmailCampaign error: ' . $e->getMessage());
            $errorPayload = $this->buildWorkerSchemaAwareErrorPayload($e, 'claim_campaign_failed', 'Kampanya claim işlemi başarısız oldu.');
            $statusCode = (int) ($errorPayload['status_code'] ?? 500);
            unset($errorPayload['status_code']);
            $response->getBody()->write(json_encode($errorPayload, JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }

        $claimReason = null;
        if (!$claimed) {
            $lockInfo = $conn->fetchAssociative(
                "SELECT status, locked_by, locked_at
                 FROM email_orders
                 WHERE id = ?",
                [$orderId]
            ) ?: [];
            $claimReason = sprintf(
                'locked_or_not_claimable (status=%s, locked_by=%s, locked_at=%s)',
                $lockInfo['status'] ?? 'unknown',
                $lockInfo['locked_by'] ?? 'null',
                $lockInfo['locked_at'] ?? 'null'
            );
        } elseif ($previousStatus === 'pending' && $previousAttempt === 0) {
            // Yalnızca ilk kez başlatıldığında bildirim gönder.
            // attempt_count > 0 ise worker restart sonrası recovery — TG tekrar atmamalı.
            $order = $this->em->find(EmailOrder::class, $orderId);
            if ($order instanceof EmailOrder) {
                $this->notifyTelegramSafely(
                    TelegramNotificationService::EVENT_PROCESSING,
                    $order,
                    [
                        'status' => 'processing',
                        'worker_name' => $workerId,
                        'worker_id' => $workerId,
                        'started_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    ]
                );
            }
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'claimed' => $claimed,
            'campaign_id' => $orderId,
            'worker_id' => $workerId,
            'reason' => $claimReason,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Worker kontrolü — pause / resume / stop isteği (panel veya otomasyon)
     * POST /api/email-campaigns/{id}/worker-control  body: {"action":"pause"|"resume"|"stop"}
     */
    public function controlEmailCampaignWorker(Request $request, Response $response, array $args): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $orderId = (int) ($args['id'] ?? 0);
        $body = (string) $request->getBody();
        $data = json_decode($body, true) ?: $request->getParsedBody() ?: [];
        $action = strtolower(trim((string) ($data['action'] ?? '')));

        $order = $this->em->find(EmailOrder::class, $orderId);
        if (!$order) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Order not found'], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if ($action === 'pause') {
            $order->setWorkerPaused(true);
        } elseif ($action === 'resume') {
            $order->setWorkerPaused(false);
            $order->setWorkerStopRequested(false);
        } elseif ($action === 'stop') {
            $order->setWorkerStopRequested(true);
            $order->setWorkerPaused(true);
        } else {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Invalid action (pause|resume|stop)',
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $this->em->flush();
        if ($action === 'pause' || $action === 'stop') {
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_PAUSED,
                $order,
                [
                    'status' => $order->getStatus()->value,
                    'worker_name' => 'worker-control',
                ]
            );
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_WORKER_STOPPED,
                $order,
                [
                    'status' => $order->getStatus()->value,
                    'worker_name' => 'worker-control',
                ]
            );
        } elseif ($action === 'resume') {
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_RESTARTED,
                $order,
                [
                    'status' => $order->getStatus()->value,
                    'worker_name' => 'worker-control',
                ]
            );
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'campaign_id' => $orderId,
            'worker_paused' => $order->isWorkerPaused(),
            'worker_stop_requested' => $order->isWorkerStopRequested(),
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Kampanya çalışma özeti (campaign_runtime_summary + son batch metrikleri)
     * GET /api/email-campaigns/{id}/runtime-metrics
     */
    public function getEmailCampaignRuntimeMetrics(Request $request, Response $response, array $args): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $orderId = (int) ($args['id'] ?? 0);
        $conn = $this->em->getConnection();

        try {
            $summary = $conn->fetchAssociative(
                'SELECT * FROM campaign_runtime_summary WHERE campaign_id = ?',
                [$orderId]
            );

            $batches = $conn->fetchAllAssociative(
                'SELECT * FROM campaign_batch_metrics WHERE campaign_id = ? ORDER BY id DESC LIMIT 50',
                [$orderId]
            );

            $response->getBody()->write(json_encode([
                'success' => true,
                'campaign_id' => $orderId,
                'runtime_summary' => $summary ?: null,
                'recent_batches' => $batches,
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Throwable $e) {
            error_log('getEmailCampaignRuntimeMetrics: ' . $e->getMessage());
            $errorPayload = $this->buildWorkerSchemaAwareErrorPayload($e, 'runtime_metrics_failed', 'Kampanya çalışma metrikleri alınamadı.');
            $statusCode = (int) ($errorPayload['status_code'] ?? 500);
            unset($errorPayload['status_code']);
            $response->getBody()->write(json_encode($errorPayload, JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }

    public function getEmailWorkerHealth(Request $request, Response $response): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $schemaHealth = $this->getWorkerSchemaHealth();
        $conn = $this->em->getConnection();

        $pendingCampaignCount = 0;
        $pausedCampaignCount = 0;
        try {
            $pendingCampaignCount = (int) $conn->fetchOne("SELECT COUNT(*) FROM email_orders WHERE status = 'pending'");
            $pausedCampaignCount = (int) $conn->fetchOne("SELECT COUNT(*) FROM email_orders WHERE worker_paused = 1");
        } catch (\Throwable $e) {
            error_log('getEmailWorkerHealth counters error: ' . $e->getMessage());
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'worker_schema_ok' => $schemaHealth['ok'],
            'missing_schema_items' => $schemaHealth['missing'],
            'migration_hint' => $schemaHealth['hint'],
            'pending_campaign_count' => $pendingCampaignCount,
            'worker_paused_campaign_count' => $pausedCampaignCount,
            'last_successful_poll_at' => null,
            'last_error' => $schemaHealth['ok'] ? null : 'Veritabanı migration eksik. Email worker kampanya çekemiyor.',
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Takılı kalan email kampanyalarını temizle
     * POST /api/email-campaigns/cleanup-stuck
     *
     * Body (optional): { "worker_id": "email-worker-hostname" }
     * worker_id verilirse o worker'ın sahiplendiği kampanyalar anında serbest bırakılır
     * (restart recovery — lock TTL beklenmez).
     */
    public function cleanupStuckEmailCampaigns(Request $request, Response $response): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $body = (string) $request->getBody();
        $data = json_decode($body, true) ?: (array) ($request->getParsedBody() ?? []);
        $workerId = isset($data['worker_id']) ? trim((string) $data['worker_id']) : '';

        $conn = $this->em->getConnection();
        $workerReleased = 0;

        // 1. Restart recovery: bu worker'ın sahiplendiği kampanyaları anında serbest bırak
        if ($workerId !== '') {
            $workerReleased = (int) $conn->executeStatement(
                "UPDATE email_orders
                 SET status = 'pending',
                     locked_at = NULL,
                     locked_by = NULL,
                     updated_at = NOW()
                 WHERE status = 'processing'
                   AND locked_by = ?",
                [$workerId]
            );
            if ($workerReleased > 0) {
                error_log("cleanup-stuck: {$workerReleased} kampanya worker='{$workerId}' restart sonrası serbest bırakıldı");
            }
        }

        // 2. Zaman bazlı genel temizleme (herhangi bir worker'dan kalan)
        $stuckHours = max(1, (int) ($_ENV['EMAIL_STUCK_TTL_HOURS'] ?? 6));
        $timeReleased = (int) $conn->executeStatement(
            "UPDATE email_orders
             SET status = 'pending',
                 locked_at = NULL,
                 locked_by = NULL,
                 updated_at = NOW()
             WHERE status = 'processing'
               AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL ? HOUR))",
            [$stuckHours]
        );

        $response->getBody()->write(json_encode([
            'success'          => true,
            'cleaned'          => $workerReleased + $timeReleased,
            'worker_released'  => $workerReleased,
            'time_released'    => $timeReleased,
            'worker_id'        => $workerId ?: null,
            'hours'            => $stuckHours,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Email kampanya emaillerini batch olarak getir (PAGINATION)
     * GET /api/email-campaigns/{id}/emails/batch?offset=0&limit=1000
     */
    public function getEmailCampaignEmailsBatch(Request $request, Response $response, array $args): Response
    {
        // API Token kontrolü
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        // Büyük havuz batch’i (JSON serileştirme) varsayılan 30s PHP limitini aşabilir
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $orderId = (int) $args['id'];
        $params = $request->getQueryParams();
        $offset = (int) ($params['offset'] ?? 0);
        $limit = min((int) ($params['limit'] ?? 10000), 50000); // Max 50k per batch
        $lastPoolId = isset($params['last_pool_id']) ? (int) $params['last_pool_id'] : null;

        $order = $this->em->find(EmailOrder::class, $orderId);

        if (!$order) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Order not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // Pool siparişleri: Worker havuzdan çeker, lazy-insert ile EmailOrderEmail oluşturulur
        $emails = [];
        $totalPending = 0;

        if ($order->isPoolOrder()) {
            $allocatedCount = (int) $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(EmailOrderEmail::class, 'e')
                ->where('e.order = :order')
                ->setParameter('order', $order)
                ->getQuery()
                ->getSingleScalarResult();

            // Önce mevcut pending kayıtları çek
            $emails = $this->em->createQueryBuilder()
                ->select('e.id, e.email, e.name')
                ->from(EmailOrderEmail::class, 'e')
                ->where('e.order = :order')
                ->andWhere('e.status = :status')
                ->setParameter('order', $order)
                ->setParameter('status', 'pending')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->orderBy('e.id', 'ASC')
                ->getQuery()
                ->getArrayResult();

            // Worker offset=0 ile çağırdığında pending yetersizse havuzdan yeni allocate et.
            if ($offset === 0 && count($emails) < $limit && $allocatedCount < $order->getTotal()) {
                $cursor = $lastPoolId ?? ($order->getLastPoolId() ?? 0);
                $fetchLimit = min($limit - count($emails), $order->getTotal() - $allocatedCount);

                if ($fetchLimit > 0) {
                    $conn = $this->em->getConnection();
                    $poolListId = $order->getPoolList()?->getId();
                    if ($poolListId === null || $poolListId < 1) {
                        $poolListId = (int) ($conn->fetchOne(
                            'SELECT id FROM email_data_pool_lists ORDER BY sort_order ASC, id ASC LIMIT 1'
                        ) ?: 1);
                    }
                    $poolRows = $conn->fetchAllAssociative(
                        'SELECT id, email FROM email_data_pool WHERE is_active = 1 AND pool_list_id = ? AND id > ? ORDER BY id ASC LIMIT ' . (int) $fetchLimit,
                        [$poolListId, $cursor]
                    );

                    if (!empty($poolRows)) {
                        $maxPoolId = $cursor;
                        foreach ($poolRows as $row) {
                            $maxPoolId = max($maxPoolId, (int) $row['id']);
                        }

                        // Havuz tahsisi: on binlerce Doctrine persist+tek flush MySQL’de dakikalar sürebilir;
                        // çok satırlı INSERT ile saniyeler mertebesine iner.
                        $orderIdVal = $order->getId();
                        $createdAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
                        $pendingVal = \App\Domain\Enum\EmailStatus::PENDING->value;
                        $systemName = '___SYSTEM_POOL___';
                        $allocatedEmails = [];
                        foreach (array_chunk($poolRows, 2000) as $insChunk) {
                            $placeholders = [];
                            $params = [];
                            foreach ($insChunk as $row) {
                                $placeholders[] = '(?, ?, ?, ?, ?)';
                                $params[] = $orderIdVal;
                                $params[] = $row['email'];
                                $params[] = $systemName;
                                $params[] = $pendingVal;
                                $params[] = $createdAt;
                            }
                            $sql = 'INSERT INTO email_order_emails (order_id, email, name, status, created_at) VALUES '
                                . implode(',', $placeholders);
                            $conn->executeStatement($sql, $params);

                            // Multi-row INSERT sonrası ilk id'yi alıp bu chunk için id setini hesapla.
                            // Böylece worker sonuç flush aşamasında email ile tek tek arama yerine
                            // id bazlı toplu update yolunu kullanabilir.
                            $firstId = null;
                            try {
                                $firstId = (int) $conn->lastInsertId();
                            } catch (\Throwable) {
                                $firstId = null;
                            }

                            foreach ($insChunk as $idx => $row) {
                                $rowId = ($firstId && $firstId > 0) ? ($firstId + $idx) : null;
                                $allocatedEmails[] = [
                                    'id' => $rowId,
                                    'email' => $row['email'],
                                    'name' => '___SYSTEM_POOL___',
                                ];
                            }
                        }

                        $allocatedCount += count($poolRows);
                        $order->setLastPoolId($maxPoolId);
                        $this->em->flush();
                        $emails = array_merge($emails, $allocatedEmails);
                    }
                }
            }

            $pendingInDb = (int) $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(EmailOrderEmail::class, 'e')
                ->where('e.order = :order')
                ->andWhere('e.status = :status')
                ->setParameter('order', $order)
                ->setParameter('status', 'pending')
                ->getQuery()
                ->getSingleScalarResult();

            // Pool: henüz allocate edilmemiş pending = total - allocated
            $remainingToAllocate = $order->getTotal() - $allocatedCount;
            $totalPending = $pendingInDb + max(0, $remainingToAllocate);
        } else {
            // Normal sipariş: pending'lerden çek
            $emails = $this->em->createQueryBuilder()
                ->select('e.id, e.email, e.name')
                ->from(EmailOrderEmail::class, 'e')
                ->where('e.order = :order')
                ->andWhere('e.status = :status')
                ->setParameter('order', $order)
                ->setParameter('status', 'pending')
                ->setFirstResult($offset)
                ->setMaxResults($limit)
                ->orderBy('e.id', 'ASC')
                ->getQuery()
                ->getArrayResult();

            $totalPending = (int) $this->em->createQueryBuilder()
                ->select('COUNT(e.id)')
                ->from(EmailOrderEmail::class, 'e')
                ->where('e.order = :order')
                ->andWhere('e.status = :status')
                ->setParameter('order', $order)
                ->setParameter('status', 'pending')
                ->getQuery()
                ->getSingleScalarResult();
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'campaign_id' => $order->getId(),
            'offset' => $offset,
            'limit' => $limit,
            'returned_count' => count($emails),
            'total_pending' => (int) $totalPending,
            'next_cursor' => $order->getLastPoolId(),
            'has_more' => (int) $totalPending > 0,
            'emails' => $emails,
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Email kampanya durumunu güncelle
     * POST /api/email-campaigns/{id}/status
     */
    public function updateEmailCampaignStatus(Request $request, Response $response, array $args): Response
    {
        // API Token kontrolü
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $orderId = (int) $args['id'];

        // JSON body'yi manuel parse et (Slim bazen otomatik yapmıyor)
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if ($data === null) {
            // Fallback: getParsedBody() dene
            $data = $request->getParsedBody();
        }

        if (!$data || !is_array($data)) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid request body']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $newStatus = $data['status'] ?? null;

        if (!$newStatus) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Status required']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $order = $this->em->find('App\Domain\Entities\EmailOrder', $orderId);

        if (!$order) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Order not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        $previousStatus = $order->getStatus()->value;
        $errorMessage = isset($data['error_message']) ? trim((string) $data['error_message']) : '';
        $workerName = isset($data['worker_name']) ? trim((string) $data['worker_name']) : (string) ($data['worker_id'] ?? '');

        // String'i EmailOrderStatus enum'a çevir
        try {
            $statusEnum = \App\Domain\Enum\EmailOrderStatus::from($newStatus);
            $order->setStatus($statusEnum);
        } catch (\ValueError $e) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Invalid status: ' . $newStatus]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Stats güncellemelerini yap
        if (isset($data['sent_count'])) {
            $order->setSent((int)$data['sent_count']);
        }

        if (isset($data['failed_count'])) {
            $order->setFailed((int)$data['failed_count']);
        }

        if (isset($data['delivered_count'])) {
            $order->setDelivered((int)$data['delivered_count']);
        }

        // Completed/failed durumlarında DB aggregate ile sayacı kesin doğrula.
        if (in_array($newStatus, ['completed', 'failed', 'sent'], true)) {
            $agg = $this->em->getConnection()->fetchAssociative(
                "SELECT
                    SUM(CASE WHEN status IN ('sent','delivered') THEN 1 ELSE 0 END) AS sent_count,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN status = 'bounced' THEN 1 ELSE 0 END) AS bounced_count
                 FROM email_order_emails
                 WHERE order_id = ?",
                [$orderId]
            ) ?: [];

            $order->setSent((int) ($agg['sent_count'] ?? $order->getSent()));
            $order->setDelivered((int) ($agg['delivered_count'] ?? $order->getDelivered()));
            $order->setFailed((int) ($agg['failed_count'] ?? $order->getFailed()));
            $order->setBounced((int) ($agg['bounced_count'] ?? $order->getBounced()));
        }

        // Completed veya failed durumlarında completedAt set et
        if (in_array($newStatus, ['completed', 'failed', 'sent'])) {
            if (!$order->getCompletedAt()) {
                $order->setCompletedAt(new \DateTimeImmutable());
            }
            $order->setLockedAt(null);
            $order->setLockedBy(null);
        }

        // Lock zamanını yalnızca claim endpoint'i yönetmeli.
        // Burada processing sırasında yeniden yazmak timezone drift üretip
        // kampanyayı uzun süre gereksiz kilitli bırakabiliyor.

        $this->em->flush();
        $currentStatus = $order->getStatus()->value;
        if ($previousStatus !== $currentStatus) {
            $this->notifyTelegramStatusChangeSafely($order, $currentStatus, [
                'status' => $currentStatus,
                'worker_name' => $workerName,
                'worker_id' => $workerName,
                'error_message' => $errorMessage,
                'success_count' => $order->getDelivered(),
                'failed_count' => $order->getFailed(),
                'bounce_count' => $order->getBounced(),
                'send_count' => $order->getTotal(),
                'completed_at' => $order->getCompletedAt()?->format('Y-m-d H:i:s'),
            ]);
        } elseif ($currentStatus === 'processing') {
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_PROGRESS,
                $order,
                [
                    'status' => $currentStatus,
                    'worker_name' => $workerName,
                    'worker_id' => $workerName,
                    'error_message' => $errorMessage,
                    'success_count' => $order->getDelivered(),
                    'failed_count' => $order->getFailed(),
                    'bounce_count' => $order->getBounced(),
                    'send_count' => $order->getTotal(),
                    'sent_count' => $order->getDelivered() + $order->getFailed() + $order->getBounced(),
                    'progress_percent' => $order->getTotal() > 0
                        ? round((($order->getDelivered() + $order->getFailed() + $order->getBounced()) / $order->getTotal()) * 100, 2)
                        : 0,
                ]
            );
        }

        $failedCount = max(0, (int) $order->getFailed());
        $totalCount = max(1, (int) $order->getTotal());
        $failedRatio = $failedCount / $totalCount;
        if ($failedCount >= 20 && $failedRatio >= 0.4) {
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_HIGH_ERROR_RATE,
                $order,
                [
                    'status' => $currentStatus,
                    'failed_count' => $failedCount,
                    'send_count' => $totalCount,
                    'error_message' => $errorMessage,
                    'worker_name' => $workerName,
                ]
            );
        }

        if ($errorMessage !== '' && str_contains(strtolower($errorMessage), 'throttle')) {
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_SMTP_THROTTLE_WARNING,
                $order,
                [
                    'status' => $currentStatus,
                    'error_message' => $errorMessage,
                    'worker_name' => $workerName,
                ]
            );
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_DAILY_LIMIT_REACHED,
                $order,
                [
                    'status' => $currentStatus,
                    'error_message' => $errorMessage,
                    'worker_name' => $workerName,
                ]
            );
        }

        if ($currentStatus === 'failed' && $errorMessage !== '') {
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_WORKER_ERROR,
                $order,
                [
                    'status' => $currentStatus,
                    'error_message' => $errorMessage,
                    'worker_name' => $workerName,
                ]
            );
            $this->notifyTelegramSafely(
                TelegramNotificationService::EVENT_SMTP_ERROR,
                $order,
                [
                    'status' => $currentStatus,
                    'error_message' => $errorMessage,
                    'worker_name' => $workerName,
                ]
            );
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Email kampanya sonuçlarını güncelle
     * POST /api/email-campaigns/{id}/emails
     */
    public function updateEmailCampaignEmails(Request $request, Response $response, array $args): Response
    {
        // API Token kontrolü
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $orderId = (int) $args['id'];

        // JSON body'yi manuel parse et
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        if ($data === null) {
            $data = $request->getParsedBody();
        }

        $emailResults = $data['emails'] ?? [];

        $order = $this->em->find('App\Domain\Entities\EmailOrder', $orderId);

        if (!$order) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Order not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $updatedCount = 0;
        $sentIncrements = 0;
        $deliveredIncrements = 0;
        $failedIncrements = 0;
        $bouncedIncrements = 0;
        $terminalStatuses = ['delivered', 'failed', 'bounced', 'skipped_blacklist', 'suppressed'];

        $resultMap = [];
        foreach ($emailResults as $result) {
            $email = trim((string) ($result['email'] ?? ''));
            if ($email === '') {
                continue;
            }
            $resultMap[strtolower($email)] = $result;
        }

        $emails = array_keys($resultMap);
        if (!empty($emails)) {
            $chunks = array_chunk($emails, 1000);
            foreach ($chunks as $chunk) {
                $entities = $this->em->createQueryBuilder()
                    ->select('e')
                    ->from('App\Domain\Entities\EmailOrderEmail', 'e')
                    ->where('e.order = :order')
                    ->andWhere('LOWER(e.email) IN (:emails)')
                    ->setParameter('order', $order)
                    ->setParameter('emails', $chunk)
                    ->getQuery()
                    ->getResult();

                foreach ($entities as $emailEntity) {
                    $key = strtolower($emailEntity->getEmail());
                    if (!isset($resultMap[$key])) {
                        continue;
                    }

                    $result = $resultMap[$key];
                    $status = (string) ($result['status'] ?? 'failed');
                    $messageId = $result['message_id'] ?? null;
                    $error = $result['error'] ?? null;
                    $previousStatus = $emailEntity->getStatus()->value;

                    // Idempotency: terminal durumdaki satırlar tekrar güncellenmez.
                    if (in_array($previousStatus, $terminalStatuses, true)) {
                        continue;
                    }

                    try {
                        $statusEnum = \App\Domain\Enum\EmailStatus::from($status);
                        $emailEntity->setStatus($statusEnum);
                    } catch (\ValueError $e) {
                        $emailEntity->setStatus(\App\Domain\Enum\EmailStatus::FAILED);
                        $status = 'failed';
                    }

                    $emailEntity->setMessageId($messageId);
                    $emailEntity->setError($error);

                    if ($previousStatus !== $status) {
                        if ($status === 'sent' || $status === 'delivered') {
                            if (!$emailEntity->getSentAt()) {
                                $emailEntity->setSentAt(new \DateTimeImmutable());
                            }
                            $sentIncrements++;
                        }

                        if ($status === 'delivered') {
                            if (!$emailEntity->getDeliveredAt()) {
                                $emailEntity->setDeliveredAt(new \DateTimeImmutable());
                            }
                            $deliveredIncrements++;
                        }

                        if ($status === 'failed' || $status === 'bounced') {
                            if ($status === 'bounced') {
                                $bouncedIncrements++;
                            } else {
                                $failedIncrements++;
                            }
                        }
                    }

                    $updatedCount++;
                }
            }
        }

        if ($sentIncrements > 0) {
            $order->setSent($order->getSent() + $sentIncrements);
        }
        if ($deliveredIncrements > 0) {
            $order->setDelivered($order->getDelivered() + $deliveredIncrements);
        }
        if ($failedIncrements > 0) {
            $order->setFailed($order->getFailed() + $failedIncrements);
        }
        if ($bouncedIncrements > 0) {
            $order->setBounced($order->getBounced() + $bouncedIncrements);
        }

        $this->em->flush();

        $response->getBody()->write(json_encode([
            'success' => true,
            'updated_count' => $updatedCount,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Panelden gelen email worker ince ayarları (token korumalı)
     * GET /api/email-sending/runtime-config
     */
    public function getEmailSendingRuntimeConfig(Request $request, Response $response): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $cfg = $this->emailSendingConfigService->getConfig();
        $response->getBody()->write(json_encode([
            'success' => true,
            'worker_runtime' => $this->emailSendingConfigService->getWorkerRuntimePayload(),
            'rate_source' => $cfg['rate_source'],
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: En iyi SMTP hesabını seç (Plan limitlerine göre)
     * GET /api/smtp/select-best
     */
    public function selectBestSmtp(Request $request, Response $response): Response
    {
        // API Token kontrolü
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $excludeRaw = (string) ($request->getQueryParams()['exclude_ids'] ?? '');
        $excludeSmtpIds = [];
        if ($excludeRaw !== '') {
            $excludeSmtpIds = array_values(array_filter(
                array_map('intval', explode(',', $excludeRaw)),
                static fn (int $id): bool => $id > 0
            ));
        }

        // EmailSmtpSelector plan limitlerini kullanır
        $bestSmtp = $this->emailSmtpSelector->selectBestSmtp($excludeSmtpIds);

        if (!$bestSmtp) {
            // 404 kullanma: worker axios’ta exception üretir; çoklu lane’de “hesap kalmadı” normaldir.
            $response->getBody()->write(json_encode([
                'success' => false,
                'smtp' => null,
                'error' => 'No active SMTP account found',
                'worker_runtime' => $this->emailSendingConfigService->getWorkerRuntimePayload(),
            ], JSON_UNESCAPED_UNICODE));

            return $response->withHeader('Content-Type', 'application/json');
        }

        // Plan config - limit TÜM SMTP'lerin toplamına uygulanır
        $planConfig = $this->emailSendingConfigService->getConfig();
        $globalTotals = $this->emailSmtpSelector->getGlobalUsageTotals();
        $isAlibabaHost = str_contains(strtolower($bestSmtp->getHost()), 'aliyun')
            || str_contains(strtolower($bestSmtp->getHost()), 'alibaba')
            || str_contains(strtolower($bestSmtp->getHost()), 'aliyuncs.com');
        $baseRate = (float) ($planConfig['rate_per_second'] ?? 1.0);
        $alibabaCap = $planConfig['alibaba_rate_cap'] ?? null;
        $alibabaProviderCap = $alibabaCap !== null ? max(0.1, (float) $alibabaCap) : $baseRate;
        $providerRate = $isAlibabaHost ? $alibabaProviderCap : $baseRate;
        $effectiveRate = min($baseRate, $providerRate);
        $maxCap = $planConfig['max_rate_per_second'] ?? null;
        if ($maxCap !== null && $maxCap > 0) {
            $effectiveRate = min($effectiveRate, (float) $maxCap);
        }

        // Global saat/dakika limiti aktif SMTP sayısına bölünür.
        // Her lane tam limiti kullanırsa N lane × limit = N× aşım olur.
        $activeSmtpCount    = max(1, $this->emailSmtpSelector->getAvailableSmtpCount());
        $perSmtpHourlyLimit = max(1, (int) floor($planConfig['hourly_limit'] / $activeSmtpCount));
        $perSmtpMinuteLimit = max(1, (int) floor($planConfig['minute_limit'] / $activeSmtpCount));

        // Şifreyi decrypt et (şifre encrypted saklanıyor)
        $decryptedPassword = $this->emailSmtpService->decryptPassword($bestSmtp->getPassword());

        $response->getBody()->write(json_encode([
            'success' => true,
            'smtp' => [
                'id' => $bestSmtp->getId(),
                'host' => $bestSmtp->getHost(),
                'port' => $bestSmtp->getPort(),
                'username' => $bestSmtp->getUsername(),
                'password' => $decryptedPassword,
                'encryption' => $bestSmtp->getEncryption(),
                'from_email' => $bestSmtp->getFromEmail(),
                'from_name' => $bestSmtp->getFromName(),
                'daily_limit' => $planConfig['daily_limit'],
                'daily_sent' => $bestSmtp->getDailySent(),
                'hourly_limit' => $perSmtpHourlyLimit,
                'hourly_sent' => $bestSmtp->getHourlySent(),
                'minute_limit' => $perSmtpMinuteLimit,
                'minute_sent' => $bestSmtp->getMinuteSent(),
                'rate_per_second' => $effectiveRate,
                'total_sent' => $bestSmtp->getTotalSent(),
                'remaining_today' => max(0, $planConfig['daily_limit'] - $globalTotals['daily_sent']),
                'global_daily_sent' => $globalTotals['daily_sent'],
                'global_hourly_sent' => $globalTotals['hourly_sent'],
                'global_minute_sent' => $globalTotals['minute_sent'],
                'pool_max_connections' => (int) ($planConfig['worker_smtp_pool_connections'] ?? 0),
                'pool_max_messages' => (int) ($planConfig['worker_smtp_pool_max_messages'] ?? 100),
            ],
            'worker_runtime' => $this->emailSendingConfigService->getWorkerRuntimePayload(),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Tek sorguda N adet SMTP seç (paralel lane kurulumu için)
     * GET /api/smtp/select-best-batch?count=N&exclude_ids=1,2,3
     */
    public function selectBestSmtpBatch(Request $request, Response $response): Response
    {
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $params     = $request->getQueryParams();
        $count      = max(1, min(20, (int) ($params['count'] ?? 1)));
        $excludeRaw = (string) ($params['exclude_ids'] ?? '');
        $excludeSmtpIds = $excludeRaw !== ''
            ? array_values(array_filter(array_map('intval', explode(',', $excludeRaw)), static fn ($id) => $id > 0))
            : [];

        $smtpList = $this->emailSmtpSelector->selectBestSmtpBatch($count, $excludeSmtpIds);

        if (empty($smtpList)) {
            $response->getBody()->write(json_encode([
                'success'        => false,
                'smtps'          => [],
                'error'          => 'No active SMTP accounts found',
                'worker_runtime' => $this->emailSendingConfigService->getWorkerRuntimePayload(),
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $planConfig   = $this->emailSendingConfigService->getConfig();
        $globalTotals = $this->emailSmtpSelector->getGlobalUsageTotals();

        // Aktif SMTP sayısına böl — her lane tam limiti alırsa N× aşım olur
        $activeSmtpCount    = max(1, $this->emailSmtpSelector->getAvailableSmtpCount());
        $perSmtpHourlyLimit = max(1, (int) floor($planConfig['hourly_limit'] / $activeSmtpCount));
        $perSmtpMinuteLimit = max(1, (int) floor($planConfig['minute_limit'] / $activeSmtpCount));

        $smtpPayloads = [];
        foreach ($smtpList as $bestSmtp) {
            $isAlibaba  = str_contains(strtolower($bestSmtp->getHost()), 'aliyun')
                       || str_contains(strtolower($bestSmtp->getHost()), 'alibaba')
                       || str_contains(strtolower($bestSmtp->getHost()), 'aliyuncs.com');
            $baseRate   = (float) ($planConfig['rate_per_second'] ?? 1.0);
            $alibabaCap = $planConfig['alibaba_rate_cap'] ?? null;
            $providerRate  = $isAlibaba && $alibabaCap !== null ? max(0.1, (float) $alibabaCap) : $baseRate;
            $effectiveRate = min($baseRate, $providerRate);
            $maxCap        = $planConfig['max_rate_per_second'] ?? null;
            if ($maxCap !== null && $maxCap > 0) {
                $effectiveRate = min($effectiveRate, (float) $maxCap);
            }

            $smtpPayloads[] = [
                'id'                   => $bestSmtp->getId(),
                'host'                 => $bestSmtp->getHost(),
                'port'                 => $bestSmtp->getPort(),
                'username'             => $bestSmtp->getUsername(),
                'password'             => $this->emailSmtpService->decryptPassword($bestSmtp->getPassword()),
                'encryption'           => $bestSmtp->getEncryption(),
                'from_email'           => $bestSmtp->getFromEmail(),
                'from_name'            => $bestSmtp->getFromName(),
                'daily_limit'          => $bestSmtp->getDailyLimit(),
                'daily_sent'           => $bestSmtp->getDailySent(),
                'hourly_limit'         => $perSmtpHourlyLimit,
                'hourly_sent'          => $bestSmtp->getHourlySent(),
                'minute_limit'         => $perSmtpMinuteLimit,
                'minute_sent'          => $bestSmtp->getMinuteSent(),
                'rate_per_second'      => $effectiveRate,
                'total_sent'           => $bestSmtp->getTotalSent(),
                'remaining_today'      => max(0, $planConfig['daily_limit'] - $globalTotals['daily_sent']),
                'global_daily_sent'    => $globalTotals['daily_sent'],
                'global_hourly_sent'   => $globalTotals['hourly_sent'],
                'global_minute_sent'   => $globalTotals['minute_sent'],
                'pool_max_connections' => (int) ($planConfig['worker_smtp_pool_connections'] ?? 0),
                'pool_max_messages'    => (int) ($planConfig['worker_smtp_pool_max_messages'] ?? 100),
            ];
        }

        $response->getBody()->write(json_encode([
            'success'        => true,
            'smtps'          => $smtpPayloads,
            'count'          => count($smtpPayloads),
            'worker_runtime' => $this->emailSendingConfigService->getWorkerRuntimePayload(),
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: SMTP kullanımını kaydet
     * POST /api/smtp/{id}/usage
     */
    public function recordSmtpUsage(Request $request, Response $response, array $args): Response
    {
        // API Token kontrolü
        $tokenError = $this->validateApiToken($request, $response);
        if ($tokenError) {
            return $tokenError;
        }

        $smtpId = (int) $args['id'];

        // JSON body parse
        $body = (string) $request->getBody();
        $data = json_decode($body, true);
        if ($data === null) {
            $data = $request->getParsedBody();
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $conn->executeQuery('SELECT id FROM email_smtp_accounts WHERE id = ? FOR UPDATE', [$smtpId]);
            $smtp = $this->em->find('App\Domain\Entities\EmailSmtpAccount', $smtpId);

            if (!$smtp) {
                $conn->rollBack();
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'SMTP not found']));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }

        // Idempotent usage event - aynı batch ikinci kez sayılmasın
        $eventKey = trim((string) ($data['event_key'] ?? ''));
            if ($eventKey !== '') {
                $existingEvent = $conn->fetchOne(
                    'SELECT id FROM email_smtp_usage_events WHERE event_key = ? LIMIT 1',
                    [$eventKey]
                );
                if ($existingEvent) {
                    $planConfig = $this->emailSendingConfigService->getConfig();
                    $globalTotals = $this->emailSmtpSelector->getGlobalUsageTotals();
                    $conn->rollBack();
                    $response->getBody()->write(json_encode([
                        'success' => true,
                        'idempotent' => true,
                        'daily_sent' => $globalTotals['daily_sent'],
                        'daily_remaining' => max(0, $planConfig['daily_limit'] - $globalTotals['daily_sent']),
                        'hourly_sent' => $globalTotals['hourly_sent'],
                        'hourly_remaining' => max(0, $planConfig['hourly_limit'] - $globalTotals['hourly_sent']),
                        'minute_sent' => $globalTotals['minute_sent'],
                        'minute_remaining' => max(0, $planConfig['minute_limit'] - $globalTotals['minute_sent']),
                        'total_sent' => $smtp->getTotalSent(),
                        'success_rate' => $smtp->getSuccessRate()
                    ]));
                    return $response->withHeader('Content-Type', 'application/json');
                }
            }

        // Limit reset kontrolleri
        if ($smtp->shouldResetToday()) {
            $smtp->resetDailySent();
        }
        if ($smtp->shouldResetHour()) {
            $smtp->resetHourlySent();
        }
        if ($smtp->shouldResetMinute()) {
            $smtp->resetMinuteSent();
        }

        // Kullanım istatistiklerini güncelle
        $successCount = (int) ($data['success_count'] ?? 1);
        $failedCount = (int) ($data['failed_count'] ?? 0);
        $errorMessage = $data['error_message'] ?? null;

        // Daily, hourly, minute sent artır
        $smtp->setDailySent($smtp->getDailySent() + $successCount);
        $smtp->setHourlySent($smtp->getHourlySent() + $successCount);
        $smtp->setMinuteSent($smtp->getMinuteSent() + $successCount);

        // Total stats güncelle
        $smtp->setTotalSent($smtp->getTotalSent() + $successCount);
        $smtp->setTotalFailed($smtp->getTotalFailed() + $failedCount);
        $smtp->calculateSuccessRate();

        // Son kullanım
        $smtp->setLastUsedAt(new \DateTimeImmutable());

        // Hata durumunda
        if ($errorMessage) {
            $smtp->setLastError($errorMessage);
        }

        // Çok fazla hata varsa devre dışı bırak
        if (isset($data['disable']) && $data['disable'] === true) {
            $smtp->setIsActive(false);
            error_log("SMTP #{$smtpId} devre dışı bırakıldı: {$errorMessage}");
        }

        $this->em->flush();

            if ($eventKey !== '') {
                try {
                    $this->em->getConnection()->executeStatement(
                        'INSERT INTO email_smtp_usage_events (smtp_id, event_key, created_at) VALUES (?, ?, NOW())',
                        [$smtpId, $eventKey]
                    );
                } catch (\Throwable $e) {
                    // Unique key çakışırsa aynı event daha önce işlendi demektir.
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }

        // Plan limitleri - TÜM SMTP'lerin toplamına göre (worker pool güncellemesi için)
        $planConfig = $this->emailSendingConfigService->getConfig();
        $globalTotals = $this->emailSmtpSelector->getGlobalUsageTotals();

        $response->getBody()->write(json_encode([
            'success' => true,
            'daily_sent' => $globalTotals['daily_sent'],
            'daily_remaining' => max(0, $planConfig['daily_limit'] - $globalTotals['daily_sent']),
            'hourly_sent' => $globalTotals['hourly_sent'],
            'hourly_remaining' => max(0, $planConfig['hourly_limit'] - $globalTotals['hourly_sent']),
            'minute_sent' => $globalTotals['minute_sent'],
            'minute_remaining' => max(0, $planConfig['minute_limit'] - $globalTotals['minute_sent']),
            'smtp_daily_sent' => $smtp->getDailySent(),
            'smtp_hourly_sent' => $smtp->getHourlySent(),
            'smtp_minute_sent' => $smtp->getMinuteSent(),
            'total_sent' => $smtp->getTotalSent(),
            'success_rate' => $smtp->getSuccessRate()
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Refund failed emails
     */
    public function refundFailedEmails(Request $request, Response $response, array $args): Response
    {
        try {
            $campaignId = (int) $args['id'];
            
            $campaign = $this->em->find(EmailOrder::class, $campaignId);
            if (!$campaign) {
                $response->getBody()->write(json_encode(['success' => false, 'error' => 'Campaign not found']));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            $user = $campaign->getUser();
            
            // Sadece refund enabled olan kullanıcılar için iade yap
            if (!$user->isEmailRefundEnabled()) {
                $response->getBody()->write(json_encode(['success' => true, 'refunded' => 0, 'message' => 'Refund disabled for user']));
                return $response->withHeader('Content-Type', 'application/json');
            }

            // Failed ve bounced email sayısını al
            $failedCount = $campaign->getFailed();
            $bouncedCount = $campaign->getBounced();
            $totalRefund = $failedCount + $bouncedCount;

            if ($totalRefund > 0) {
                // Cost per email hesapla
                $costPerEmail = $campaign->getTotal() > 0 ? $campaign->getCost() / $campaign->getTotal() : 0;
                $refundAmount = $totalRefund * $costPerEmail;

                // Kullanıcıya kredi iade et
                $user->setEmailCredit($user->getEmailCredit() + $refundAmount);
                $this->em->flush();

                $response->getBody()->write(json_encode([
                    'success' => true, 
                    'refunded' => $totalRefund,
                    'amount' => round($refundAmount, 2)
                ]));
            } else {
                $response->getBody()->write(json_encode(['success' => true, 'refunded' => 0]));
            }

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            error_log("refundFailedEmails error: " . $e->getMessage());
            $response->getBody()->write(json_encode(['success' => false, 'error' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    private function notifyTelegramSafely(string $eventType, ?EmailOrder $order, array $context = []): void
    {
        try {
            $this->telegramNotificationService->notifyEvent($eventType, $order, $context);
        } catch (\Throwable $e) {
            error_log('Telegram notify error (' . $eventType . '): ' . $e->getMessage());
        }
    }

    private function notifyTelegramStatusChangeSafely(EmailOrder $order, string $status, array $context = []): void
    {
        try {
            $this->telegramNotificationService->notifyEmailOrderStatusChanged($order, $status, $context);
        } catch (\Throwable $e) {
            error_log('Telegram status notify error (' . $status . '): ' . $e->getMessage());
        }
    }

    private function getWorkerSchemaHealth(): array
    {
        $conn = $this->em->getConnection();
        $dbName = (string) ($conn->getDatabase() ?: $conn->fetchOne('SELECT DATABASE()'));
        $missing = [];

        foreach (self::WORKER_SCHEMA_REQUIRED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    $exists = (int) $conn->fetchOne(
                        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                        [$dbName, $table, $column]
                    ) > 0;
                } catch (\Throwable) {
                    $exists = false;
                }

                if (!$exists) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }

        foreach (self::WORKER_SCHEMA_REQUIRED_TABLES as $table) {
            try {
                $exists = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, $table]
                ) > 0;
            } catch (\Throwable) {
                $exists = false;
            }
            if (!$exists) {
                $missing[] = $table;
            }
        }

        return [
            'ok' => empty($missing),
            'missing' => $missing,
            'hint' => 'Sunucuda Doctrine migration çalıştırın: `bash bin/run-doctrine-migrations.sh`',
        ];
    }

    private function buildWorkerSchemaAwareErrorPayload(\Throwable $e, string $errorCode, string $defaultMessage): array
    {
        $debug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN);
        $schemaHealth = $this->getWorkerSchemaHealth();
        $rawMessage = trim((string) $e->getMessage());
        $lowerMessage = strtolower($rawMessage);
        $schemaKeywordMatched = str_contains($lowerMessage, 'worker_paused')
            || str_contains($lowerMessage, 'worker_stop_requested')
            || str_contains($lowerMessage, 'campaign_batch_metrics')
            || str_contains($lowerMessage, 'campaign_runtime_summary')
            || str_contains($lowerMessage, 'unknown column')
            || str_contains($lowerMessage, "doesn't exist");
        $isSchemaError = !$schemaHealth['ok'] || $schemaKeywordMatched;

        $payload = [
            'success' => false,
            'error' => $errorCode,
            'message' => $debug ? ($rawMessage !== '' ? $rawMessage : $defaultMessage) : $defaultMessage,
            'hint' => null,
            'status_code' => 500,
        ];

        if ($isSchemaError) {
            $missing = $schemaHealth['missing'];
            if (!empty($missing)) {
                $payload['message'] = 'Veritabanı migration eksik: ' . implode(', ', $missing) . '.';
            } else {
                $payload['message'] = 'Veritabanı migration eksik veya şema worker ile uyumlu değil.';
            }
            $payload['hint'] = $schemaHealth['hint'];
            $payload['error'] = 'migration_required';
        }

        return $payload;
    }

    // ─── Dış Öncelik (hub-nexus → bu mail sunucusu) ───────────────────────────

    /**
     * JSON yanıt yardımcısı (dış öncelik endpoint'leri için).
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    /**
     * POST /api/email-campaigns/external-priority/receive
     * Hub-nexus'tan gelen priority slot kaydını sakla (idempotent).
     */
    public function receiveExternalPriority(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $required = ['hub_api_url','hub_api_token','hub_campaign_id','hub_slot_id','worker_slot'];
        foreach ($required as $field) {
            if (empty($data[$field]) && $data[$field] !== 0) {
                return $this->jsonResponse($response, ['success' => false, 'error' => "missing_field: $field"], 400);
            }
        }

        $conn = $this->em->getConnection();
        try {
            $existing = $conn->fetchOne(
                'SELECT id FROM external_priority_slots WHERE hub_slot_id = ? AND hub_campaign_id = ?',
                [(int)$data['hub_slot_id'], (int)$data['hub_campaign_id']]
            );
            if ($existing) {
                return $this->jsonResponse($response, ['success' => true, 'id' => (int)$existing, 'skipped' => true]);
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->insert('external_priority_slots', [
                'hub_api_url'     => $data['hub_api_url'],
                'hub_api_token'   => $data['hub_api_token'],
                'hub_campaign_id' => (int)$data['hub_campaign_id'],
                'hub_slot_id'     => (int)$data['hub_slot_id'],
                'worker_slot'     => (int)$data['worker_slot'],
                'email_id_min'    => isset($data['email_id_min']) ? (int)$data['email_id_min'] : null,
                'email_id_max'    => isset($data['email_id_max']) ? (int)$data['email_id_max'] : null,
                'total_emails'    => (int)($data['total_emails'] ?? 0),
                'status'          => 'pending',
                'campaign_meta'   => isset($data['campaign_meta']) ? (is_string($data['campaign_meta']) ? $data['campaign_meta'] : json_encode($data['campaign_meta'])) : null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $id = (int)$conn->lastInsertId();
            return $this->jsonResponse($response, ['success' => true, 'id' => $id]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/email-campaigns/external-priority/active?worker_slot=N
     */
    public function getActiveExternalPriority(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $conn   = $this->em->getConnection();
        try {
            if (isset($params['worker_slot'])) {
                // Sadece 'pending' dön — worker yeni iş alıyor. 'processing' orphan'ları tekrar alınmaz.
                $slot = $conn->fetchAssociative(
                    "SELECT * FROM external_priority_slots WHERE worker_slot = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 1",
                    [(int)$params['worker_slot']]
                );
                return $this->jsonResponse($response, ['success' => true, 'slot' => $slot ?: null]);
            }
            $slots = $conn->fetchAllAssociative(
                "SELECT * FROM external_priority_slots WHERE status IN ('pending','processing') ORDER BY created_at ASC"
            );
            return $this->jsonResponse($response, ['success' => true, 'slots' => $slots]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/email-campaigns/external-priority/{id}/claim
     */
    public function claimExternalPrioritySlot(Request $request, Response $response, array $args): Response
    {
        $id   = (int)$args['id'];
        $data = (array) $request->getParsedBody();
        $conn = $this->em->getConnection();
        try {
            $now     = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $updated = $conn->executeStatement(
                "UPDATE external_priority_slots SET status='processing', worker_id=?, claimed_at=?, updated_at=? WHERE id=? AND status='pending'",
                [$data['worker_id'] ?? null, $now, $now, $id]
            );
            return $this->jsonResponse($response, ['success' => true, 'claimed' => $updated > 0]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/email-campaigns/external-priority/{id}/complete
     */
    public function completeExternalPrioritySlot(Request $request, Response $response, array $args): Response
    {
        $id   = (int)$args['id'];
        $data = (array) $request->getParsedBody();
        $conn = $this->em->getConnection();
        try {
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement(
                "UPDATE external_priority_slots SET status='completed', sent_count=?, failed_count=?, completed_at=?, updated_at=? WHERE id=?",
                [(int)($data['sent_count'] ?? 0), (int)($data['failed_count'] ?? 0), $now, $now, $id]
            );
            return $this->jsonResponse($response, ['success' => true]);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, ['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
