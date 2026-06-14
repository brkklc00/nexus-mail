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
        $activeSmtpCount = count($this->em->getRepository(EmailSmtpAccount::class)->findBy(['isActive' => true]));

        $html = $this->twig->render('email-sending-settings/index.twig', [
            'config' => $config,
            'plans' => $plans,
            'global_totals' => $globalTotals,
            'active_smtp_count' => $activeSmtpCount,
            'email_max_rate_per_second' => EmailSendingConfigService::MAX_RATE_PER_SECOND,
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

        if ($dailyLimit < 1 || $dailyLimit > 100000000) {
            $_SESSION['error'] = 'Günlük limit 1 ile 100.000.000 arasında olmalıdır.';
            return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
        }

        $effectiveRate = EmailSendingConfigService::computeRateFromDailyAverage($dailyLimit);
        $workerProfile = EmailSendingConfigService::workerProfileForRate($effectiveRate, $dailyLimit);

        $activeSmtps = $this->em->getRepository(EmailSmtpAccount::class)->findBy(['isActive' => true]);
        $activeCount = count($activeSmtps);
        if ($activeCount > 1) {
            $workerProfile['worker_max_smtp_lanes'] = $activeCount;
        }

        try {
            $this->sendingConfigService->saveAdminSettings(array_merge([
                'daily_limit' => $dailyLimit,
                'rate_per_second' => $effectiveRate,
                'rate_source' => EmailSendingConfigService::RATE_SOURCE_DAILY_AVERAGE_24H,
                'alibaba_rate_cap' => null,
                'max_rate_per_second' => null,
            ], $workerProfile));

            $cfg = $this->sendingConfigService->getConfig();
            $laneCount = max(1, $activeCount);
            foreach ($this->em->getRepository(EmailSmtpAccount::class)->findAll() as $smtp) {
                $smtp->setDailyLimit((int) ceil($cfg['daily_limit'] / $laneCount));
                $smtp->setHourlyLimit((int) ceil($cfg['hourly_limit'] / $laneCount));
                $smtp->setMinuteLimit((int) ceil($cfg['minute_limit'] / $laneCount));
            }
            $this->em->flush();

            $_SESSION['success'] = number_format($dailyLimit, 0, '.', '.') . ' mail/gün hedefi uygulandı.'
                . ($activeCount > 0 ? ' ' . $activeCount . ' aktif SMTP hesabı eş zamanlı çalışacak şekilde yapılandırıldı.' : '');
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
            $plan = EmailSendingConfigService::PLANS[$planKey];
            $daily = (int) $plan['daily_limit'];
            $effectiveRate = EmailSendingConfigService::computeRateFromDailyAverage($daily);
            $workerProfile = EmailSendingConfigService::workerProfileForRate($effectiveRate, $daily);

            $activeSmtps = $this->em->getRepository(EmailSmtpAccount::class)->findBy(['isActive' => true]);
            $activeCount = count($activeSmtps);
            if ($activeCount > 1) {
                $workerProfile['worker_max_smtp_lanes'] = $activeCount;
            }

            $this->sendingConfigService->saveAdminSettings(array_merge([
                'daily_limit' => $daily,
                'rate_per_second' => $effectiveRate,
                'rate_source' => EmailSendingConfigService::RATE_SOURCE_DAILY_AVERAGE_24H,
                'alibaba_rate_cap' => null,
                'max_rate_per_second' => null,
            ], $workerProfile));

            $cfg = $this->sendingConfigService->getConfig();
            $laneCount = max(1, $activeCount);
            foreach ($this->em->getRepository(EmailSmtpAccount::class)->findAll() as $smtp) {
                $smtp->setDailyLimit((int) ceil($cfg['daily_limit'] / $laneCount));
                $smtp->setHourlyLimit((int) ceil($cfg['hourly_limit'] / $laneCount));
                $smtp->setMinuteLimit((int) ceil($cfg['minute_limit'] / $laneCount));
            }
            $this->em->flush();

            $title = EmailSendingConfigService::PLANS[$planKey]['title'];
            $_SESSION['success'] = 'Paket uygulandı: ' . $title
                . '. Tüm worker ayarları otomatik yapılandırıldı.'
                . ($activeCount > 0 ? ' ' . $activeCount . ' SMTP eş zamanlı çalışacak.' : '');
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'Plan uygulanamadı: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/email-sending-settings')->withStatus(302);
    }
}
