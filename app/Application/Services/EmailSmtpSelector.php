<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\EmailSmtpAccount;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

class EmailSmtpSelector
{
    private const STRATEGY_PRIORITY = 'priority';
    private const STRATEGY_ROUND_ROBIN = 'round_robin';
    private const STRATEGY_LEAST_USED = 'least_used';
    private const STRATEGY_HIGHEST_SUCCESS = 'highest_success';

    public function __construct(
        private EntityManagerInterface $em,
        private ?EmailSendingConfigService $sendingConfigService = null,
        private string $strategy = self::STRATEGY_PRIORITY
    ) {
    }

    /**
     * Tüm aktif SMTP'lerin toplam kullanımını getir (plan limiti SMTP toplamına uygulanır)
     */
    public function getGlobalUsageTotals(): array
    {
        $smtps = $this->getActiveSmtps();
        $dailySent = 0;
        $hourlySent = 0;
        $minuteSent = 0;

        foreach ($smtps as $smtp) {
            if ($smtp->shouldResetToday()) {
                $smtp->resetDailySent();
                $this->em->persist($smtp);
            }
            if ($smtp->shouldResetHour()) {
                $smtp->resetHourlySent();
                $this->em->persist($smtp);
            }
            if ($smtp->shouldResetMinute()) {
                $smtp->resetMinuteSent();
                $this->em->persist($smtp);
            }
            $dailySent += $smtp->getDailySent();
            $hourlySent += $smtp->getHourlySent();
            $minuteSent += $smtp->getMinuteSent();
        }

        $this->em->flush();

        return [
            'daily_sent' => $dailySent,
            'hourly_sent' => $hourlySent,
            'minute_sent' => $minuteSent,
        ];
    }

    /**
     * Plan limiti aşıldı mı? (Tüm SMTP'lerin toplamına göre)
     */
    private function isGlobalPlanLimitReached(array $planConfig, array $totals): bool
    {
        return $totals['daily_sent'] >= $planConfig['daily_limit']
            || $totals['hourly_sent'] >= $planConfig['hourly_limit']
            || $totals['minute_sent'] >= $planConfig['minute_limit'];
    }

    /**
     * En uygun SMTP hesabını seç
     * Plan limiti = TÜM SMTP'lerin toplam günlük/saatlik/dakikalık gönderim limiti
     */
    public function selectBestSmtp(array $excludeSmtpIds = []): ?EmailSmtpAccount
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            $smtps = $this->getActiveSmtps(lockRows: true);
            if (!empty($excludeSmtpIds)) {
                $excludeMap = array_fill_keys(array_map('intval', $excludeSmtpIds), true);
                $smtps = array_values(array_filter(
                    $smtps,
                    static fn (EmailSmtpAccount $smtp): bool => !isset($excludeMap[$smtp->getId()])
                ));
            }
            if (empty($smtps)) {
                $conn->commit();
                return null;
            }

            $planConfig = $this->sendingConfigService?->getConfig() ?? [
                'daily_limit' => 20000,
                'hourly_limit' => 3600,
                'minute_limit' => 60,
            ];

            $totals = $this->getGlobalUsageTotals();
            if ($this->isGlobalPlanLimitReached($planConfig, $totals)) {
                $conn->commit();
                return null;
            }

            $selected = $this->applyStrategy($smtps, $totals);
            $conn->commit();
            return $selected;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Aktif SMTP'leri getir
     */
    private function getActiveSmtps(bool $lockRows = false): array
    {
        $qb = $this->em->createQueryBuilder();

        $query = $qb->select('s')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('s.priority', 'ASC')
            ->getQuery();

        if ($lockRows) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        return $query->getResult();
    }

    /**
     * Seçim stratejisi uygula
     */
    private function applyStrategy(array $smtps, array $globalTotals = []): ?EmailSmtpAccount
    {
        $effectiveStrategy = $this->resolveStrategy($smtps, $globalTotals);

        return match ($effectiveStrategy) {
            self::STRATEGY_PRIORITY => $this->selectByPriority($smtps),
            self::STRATEGY_ROUND_ROBIN => $this->selectByRoundRobin($smtps),
            self::STRATEGY_LEAST_USED => $this->selectByLeastUsed($smtps),
            self::STRATEGY_HIGHEST_SUCCESS => $this->selectByHighestSuccess($smtps),
            default => $this->selectByPriority($smtps)
        };
    }

    /**
     * Çok SMTP varsa yükü dağıtmak için stratejiyi otomatik belirle.
     */
    private function resolveStrategy(array $smtps, array $globalTotals = []): string
    {
        // Env ile override edilebilsin (priority|round_robin|least_used|highest_success)
        $envStrategy = $_ENV['EMAIL_SMTP_SELECTION_STRATEGY'] ?? null;
        if (is_string($envStrategy) && $envStrategy !== '') {
            return $envStrategy;
        }

        // 3+ aktif SMTP varsa tek hesaba yük binmemesi için least_used kullan.
        if (count($smtps) >= 3) {
            return self::STRATEGY_LEAST_USED;
        }

        return $this->strategy;
    }

    /**
     * Öncelik stratejisi (Varsayılan)
     * Önce priority'ye göre, sonra en az kullanılana göre seç
     */
    private function selectByPriority(array $smtps): ?EmailSmtpAccount
    {
        usort($smtps, function (EmailSmtpAccount $a, EmailSmtpAccount $b) {
            // Önce önceliğe göre (düşük sayı = yüksek öncelik)
            if ($a->getPriority() !== $b->getPriority()) {
                return $a->getPriority() <=> $b->getPriority();
            }
            // Sonra günlük kullanıma göre (az kullanılan önce)
            return $a->getDailySent() <=> $b->getDailySent();
        });

        return $smtps[0] ?? null;
    }

    /**
     * Round Robin stratejisi
     * Sırayla her SMTP'yi kullan
     */
    private function selectByRoundRobin(array $smtps): ?EmailSmtpAccount
    {
        // En son kullanılanı bul
        usort($smtps, function (EmailSmtpAccount $a, EmailSmtpAccount $b) {
            $aTime = $a->getLastUsedAt() ? $a->getLastUsedAt()->getTimestamp() : 0;
            $bTime = $b->getLastUsedAt() ? $b->getLastUsedAt()->getTimestamp() : 0;
            return $aTime <=> $bTime;
        });

        return $smtps[0] ?? null;
    }

    /**
     * En az kullanılan stratejisi
     * Bugün en az gönderim yapanı seç
     */
    private function selectByLeastUsed(array $smtps): ?EmailSmtpAccount
    {
        usort($smtps, function (EmailSmtpAccount $a, EmailSmtpAccount $b) {
            return $a->getDailySent() <=> $b->getDailySent();
        });

        return $smtps[0] ?? null;
    }

    /**
     * En yüksek başarı oranı stratejisi
     */
    private function selectByHighestSuccess(array $smtps): ?EmailSmtpAccount
    {
        usort($smtps, function (EmailSmtpAccount $a, EmailSmtpAccount $b) {
            // Önce başarı oranına göre (yüksekten düşüğe)
            if ($a->getSuccessRate() !== $b->getSuccessRate()) {
                return $b->getSuccessRate() <=> $a->getSuccessRate();
            }
            // Sonra günlük kullanıma göre
            return $a->getDailySent() <=> $b->getDailySent();
        });

        return $smtps[0] ?? null;
    }

    /**
     * Strateji değiştir
     */
    public function setStrategy(string $strategy): self
    {
        $this->strategy = $strategy;
        return $this;
    }

    /**
     * Mevcut stratejiyi al
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }

    /**
     * Kullanılabilir SMTP sayısı (global limit aşılmamışsa tüm aktif SMTP'ler)
     */
    public function getAvailableSmtpCount(): int
    {
        $smtps = $this->getActiveSmtps();
        $planConfig = $this->sendingConfigService?->getConfig() ?? ['daily_limit' => 20000];
        $totals = $this->getGlobalUsageTotals();

        if ($totals['daily_sent'] >= $planConfig['daily_limit']) {
            return 0;
        }

        return count($smtps);
    }
}

