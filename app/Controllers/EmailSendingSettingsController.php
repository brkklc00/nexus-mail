<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Services\EmailSendingConfigService;
use App\Application\Services\EmailSmtpSelector;
use App\Domain\Entities\EmailSmtpAccount;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailSendingSettingsController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig,
        private EmailSendingConfigService $sendingConfigService,
        private EmailSmtpSelector $smtpSelector
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $config = $this->sendingConfigService->getConfig();
        $plans = $this->sendingConfigService->getPlansWithPreview();
        $globalTotals = $this->smtpSelector->getGlobalUsageTotals();
        $previewAutoRate = EmailSendingConfigService::computeRateFromDailyAverage($config['daily_limit']);

        $html = $this->twig->render('email-sending-settings/index.twig', [
            'config' => $config,
            'plans' => $plans,
            'global_totals' => $globalTotals,
            'preview_auto_rate' => $previewAutoRate,
            'email_max_rate_per_second' => EmailSendingConfigService::MAX_RATE_PER_SECOND,
            'RATE_MANUAL' => EmailSendingConfigService::RATE_SOURCE_MANUAL,
            'RATE_DAILY_AVG' => EmailSendingConfigService::RATE_SOURCE_DAILY_AVERAGE_24H,
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);
        unset($_SESSION['success'], $_SESSION['error']);

        $response->getBody()->write($html);
        return $response;
    }

    public function save(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];

        $dailyLimit = (int) ($data['daily_limit'] ?? 0);
        $rateSource = (string) ($data['rate_source'] ?? EmailSendingConfigService::RATE_SOURCE_MANUAL);
        if ($rateSource !== EmailSendingConfigService::RATE_SOURCE_DAILY_AVERAGE_24H) {
            $rateSource = EmailSendingConfigService::RATE_SOURCE_MANUAL;
        }

        $ratePerSecond = (float) str_replace(',', '.', (string) ($data['rate_per_second'] ?? '0'));

        $alibabaRaw = trim((string) ($data['alibaba_rate_cap'] ?? ''));
        $maxRaw = trim((string) ($data['max_rate_per_second'] ?? ''));

        $alibabaCap = $alibabaRaw === '' ? null : max(0.1, (float) str_replace(',', '.', $alibabaRaw));
        $maxCap = $maxRaw === '' ? null : max(0.1, (float) str_replace(',', '.', $maxRaw));

        $workerBatch = (int) ($data['worker_batch_gap_ms'] ?? 100);
        $workerChunk = (int) ($data['worker_chunk_gap_ms'] ?? 50);
        $workerConc = (int) ($data['worker_send_concurrency'] ?? 1);
        $workerPool = (int) ($data['worker_smtp_pool_connections'] ?? 0);
        $fetchBatch = (int) ($data['worker_fetch_batch_size'] ?? 10000);
        $sendBatch = (int) ($data['worker_send_batch_size'] ?? 500);
        $maxLanes = (int) ($data['worker_max_smtp_lanes'] ?? 10);
        $throttleStep = (float) str_replace(',', '.', (string) ($data['worker_throttle_step_up'] ?? '0.5'));
        $throttleCd = (int) ($data['worker_throttle_cooldown_ms'] ?? 15000);
        $poolMaxMsg = (int) ($data['worker_smtp_pool_max_messages'] ?? 100);
        $aliWarmRaw = trim((string) ($data['alibaba_warmup_max_rate_per_second'] ?? ''));

        if ($dailyLimit < 1 || $dailyLimit > 100000000) {
            $_SESSION['error'] = 'Günlük limit 1 ile 100.000.000 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($rateSource === EmailSendingConfigService::RATE_SOURCE_MANUAL) {
            $maxR = EmailSendingConfigService::MAX_RATE_PER_SECOND;
            if ($ratePerSecond < 0.01 || $ratePerSecond > $maxR) {
                $_SESSION['error'] = 'Manuel modda saniyelik hız 0,01 ile '
                    . number_format($maxR, 0, ',', '.') . ' arasında olmalıdır.';
                return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
            }
        }

        if ($workerBatch < 0 || $workerBatch > 60000 || $workerChunk < 0 || $workerChunk > 60000) {
            $_SESSION['error'] = 'Batch bekleme süreleri 0–60000 ms arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($workerConc < 1 || $workerConc > 50) {
            $_SESSION['error'] = 'Lane eşzamanlılığı 1–50 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($workerPool < 0 || $workerPool > EmailSendingConfigService::MAX_SMTP_POOL_CONNECTIONS) {
            $_SESSION['error'] = 'SMTP pool bağlantı sayısı 0–'
                . number_format(EmailSendingConfigService::MAX_SMTP_POOL_CONNECTIONS, 0, ',', '.')
                . ' arasında olmalıdır (0 = kapalı).';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($fetchBatch < 100 || $fetchBatch > 500000) {
            $_SESSION['error'] = 'Havuzdan çekim boyutu 100–500.000 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($sendBatch < 10 || $sendBatch > 5000) {
            $_SESSION['error'] = 'Gönderim alt-grup boyutu 10–5.000 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($maxLanes < 1 || $maxLanes > 50) {
            $_SESSION['error'] = 'Paralel SMTP lane sayısı 1–50 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($throttleStep < 0.01 || $throttleStep > 10) {
            $_SESSION['error'] = 'Throttle artış adımı 0,01–10 / sn arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($throttleCd < 1000 || $throttleCd > 600000) {
            $_SESSION['error'] = 'Throttle bekleme süresi 1.000–600.000 ms arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        if ($poolMaxMsg < 1 || $poolMaxMsg > 50000) {
            $_SESSION['error'] = 'SMTP pool max mesaj 1–50.000 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        $aliWarmupSave = $aliWarmRaw === '' ? null : max(
            0.2,
            min(EmailSendingConfigService::MAX_RATE_PER_SECOND, (float) str_replace(',', '.', $aliWarmRaw))
        );

        try {
            $this->sendingConfigService->saveAdminSettings([
                'daily_limit' => $dailyLimit,
                'rate_per_second' => $ratePerSecond,
                'rate_source' => $rateSource,
                'alibaba_rate_cap' => $alibabaCap,
                'max_rate_per_second' => $maxCap,
                'worker_batch_gap_ms' => $workerBatch,
                'worker_chunk_gap_ms' => $workerChunk,
                'worker_send_concurrency' => $workerConc,
                'worker_smtp_pool_connections' => $workerPool,
                'worker_fetch_batch_size' => $fetchBatch,
                'worker_send_batch_size' => $sendBatch,
                'worker_max_smtp_lanes' => $maxLanes,
                'worker_throttle_step_up' => $throttleStep,
                'worker_throttle_cooldown_ms' => $throttleCd,
                'worker_smtp_pool_max_messages' => $poolMaxMsg,
                'alibaba_warmup_max_rate_per_second' => $aliWarmupSave,
            ]);
            $cfg = $this->sendingConfigService->getConfig();
            foreach ($this->em->getRepository(EmailSmtpAccount::class)->findAll() as $smtp) {
                $smtp->setDailyLimit($cfg['daily_limit']);
                $smtp->setHourlyLimit($cfg['hourly_limit']);
                $smtp->setMinuteLimit($cfg['minute_limit']);
            }
            $this->em->flush();
            $_SESSION['success'] = 'Gönderim ayarları kaydedildi. SMTP limitleri ve email worker (bir sonraki API çekiminde) güncellenir.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Kayıt hatası: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
    }

    public function applyPreset(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $planKey = (string) ($data['plan'] ?? '');

        if ($planKey === '' || !isset(EmailSendingConfigService::PLANS[$planKey])) {
            $_SESSION['error'] = 'Geçersiz hazır plan.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        try {
            $this->sendingConfigService->applyPlan($planKey);
            $cfg = $this->sendingConfigService->getConfig();
            foreach ($this->em->getRepository(EmailSmtpAccount::class)->findAll() as $smtp) {
                $smtp->setDailyLimit($cfg['daily_limit']);
                $smtp->setHourlyLimit($cfg['hourly_limit']);
                $smtp->setMinuteLimit($cfg['minute_limit']);
            }
            $this->em->flush();
            $title = EmailSendingConfigService::PLANS[$planKey]['title'];
            $_SESSION['success'] = 'Paket uygulandı: ' . $title
                . '. Günlük kota, saniyelik hız (24 saate eşit yayım) ve worker ayarları otomatik güncellendi.';
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Plan uygulanamadı: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
    }
}
