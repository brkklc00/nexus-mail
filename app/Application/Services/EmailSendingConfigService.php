<?php

declare(strict_types=1);

namespace App\Application\Services;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Email gönderim planı — tüm SMTP'ler için tek limit; worker ince ayarları DB'de.
 */
class EmailSendingConfigService
{
    /** Günlük kota ÷ 86.400 ile gelen hızın üst sınırı (50M/gün ≈ 578,7/sn). */
    public const MAX_RATE_PER_SECOND = 600.0;

    public const RATE_SOURCE_MANUAL = 'manual';

    public const RATE_SOURCE_DAILY_AVERAGE_24H = 'daily_average_24h';

    /**
     * Hazır paketler — sadece günlük hacim. Saniyelik hız = kotayı 24 saate eşit yay (otomatik).
     * Paket seçilince worker (batch, lane, throttle, pool…) hıza göre otomatik doldurulur.
     *
     * @var array<string, array{daily_limit: int, title: string}>
     */
    public const PLANS = [
        '5k' => ['daily_limit' => 5_000, 'title' => '5 bin / gün'],
        '10k' => ['daily_limit' => 10_000, 'title' => '10 bin / gün'],
        '25k' => ['daily_limit' => 25_000, 'title' => '25 bin / gün'],
        '50k' => ['daily_limit' => 50_000, 'title' => '50 bin / gün'],
        '100k' => ['daily_limit' => 100_000, 'title' => '100 bin / gün'],
        '250k' => ['daily_limit' => 250_000, 'title' => '250 bin / gün'],
        '500k' => ['daily_limit' => 500_000, 'title' => '500 bin / gün'],
        '750k' => ['daily_limit' => 750_000, 'title' => '750 bin / gün'],
        '1m' => ['daily_limit' => 1_000_000, 'title' => '1 milyon / gün'],
        '1m5' => ['daily_limit' => 1_500_000, 'title' => '1,5 milyon / gün'],
        '2m' => ['daily_limit' => 2_000_000, 'title' => '2 milyon / gün'],
        '3m' => ['daily_limit' => 3_000_000, 'title' => '3 milyon / gün'],
        '5m' => ['daily_limit' => 5_000_000, 'title' => '5 milyon / gün'],
        '10m' => ['daily_limit' => 10_000_000, 'title' => '10 milyon / gün'],
        '15m' => ['daily_limit' => 15_000_000, 'title' => '15 milyon / gün'],
        '20m' => ['daily_limit' => 20_000_000, 'title' => '20 milyon / gün'],
        '25m' => ['daily_limit' => 25_000_000, 'title' => '25 milyon / gün'],
        '50m' => ['daily_limit' => 50_000_000, 'title' => '50 milyon / gün'],
    ];

    /**
     * Efektif saniyelik hıza göre worker alanları (paket uygularken).
     *
     * @return array{
     *   worker_batch_gap_ms: int,
     *   worker_chunk_gap_ms: int,
     *   worker_send_concurrency: int,
     *   worker_smtp_pool_connections: int,
     *   worker_fetch_batch_size: int,
     *   worker_send_batch_size: int,
     *   worker_max_smtp_lanes: int,
     *   worker_throttle_step_up: float,
     *   worker_throttle_cooldown_ms: int,
     *   worker_smtp_pool_max_messages: int,
     *   alibaba_warmup_max_rate_per_second: float
     * }
     */
    public static function workerProfileForRate(float $effectiveRatePerSecond, int $dailyLimit): array
    {
        $r = self::clampRate($effectiveRatePerSecond);

        if ($r < 0.5) {
            $batchGap = 400;
            $chunkGap = 200;
        } elseif ($r < 2) {
            $batchGap = 200;
            $chunkGap = 100;
        } elseif ($r < 8) {
            $batchGap = 120;
            $chunkGap = 60;
        } elseif ($r < 25) {
            $batchGap = 80;
            $chunkGap = 40;
        } elseif ($r < 60) {
            $batchGap = 50;
            $chunkGap = 25;
        } else {
            $batchGap = 30;
            $chunkGap = 15;
        }

        $sendConc = 1;
        if ($r >= 1) {
            $sendConc = max(1, min(25, (int) ceil($r / 2.5)));
        }

        $poolConn = 0;
        if ($r >= 5 && $r < 20) {
            $poolConn = 2;
        } elseif ($r >= 20 && $r < 50) {
            $poolConn = 3;
        } elseif ($r >= 50) {
            $poolConn = min(12, max(4, (int) floor($r / 12)));
        }

        $fetch = min(500_000, max(2_000, (int) round($dailyLimit * 0.02)));
        $sendBatch = (int) max(10, min(3_000, round($r * 30)));

        $lanes = 2;
        if ($r >= 0.3) {
            $lanes = max(2, min(35, (int) ceil($r / 1.8)));
        }

        $step = min(5.0, max(0.05, round($r * 0.07, 3)));
        $cooldown = $r < 2 ? 20_000 : ($r < 12 ? 16_000 : ($r < 35 ? 14_000 : 12_000));

        $poolMsg = (int) max(50, min(400, round($r * 10)));

        $aliWarm = min(self::MAX_RATE_PER_SECOND, max(4.0, round($r * 1.25, 2)));

        return [
            'worker_batch_gap_ms' => $batchGap,
            'worker_chunk_gap_ms' => $chunkGap,
            'worker_send_concurrency' => $sendConc,
            'worker_smtp_pool_connections' => min(20, $poolConn),
            'worker_fetch_batch_size' => max(100, $fetch),
            'worker_send_batch_size' => $sendBatch,
            'worker_max_smtp_lanes' => $lanes,
            'worker_throttle_step_up' => $step,
            'worker_throttle_cooldown_ms' => $cooldown,
            'worker_smtp_pool_max_messages' => $poolMsg,
            'alibaba_warmup_max_rate_per_second' => $aliWarm,
        ];
    }

    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * Günlük kotayı 24 saate eşit yayarak saniyelik hedef (tavan MAX_RATE_PER_SECOND, taban 0,01/sn).
     */
    public static function computeRateFromDailyAverage(int $dailyLimit): float
    {
        return self::clampRate($dailyLimit / 86400.0);
    }

    public static function clampRate(float $rate): float
    {
        return min(self::MAX_RATE_PER_SECOND, max(0.01, round($rate, 4)));
    }

    /**
     * API / selector / worker için efektif config (rate_per_second her zaman uygulanacak değer).
     */
    public function getConfig(): array
    {
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT * FROM email_sending_config WHERE id = 1');

        if (!$row) {
            return $this->defaults();
        }

        $dailyLimit = (int) $row['daily_limit'];
        $storedRate = (float) $row['rate_per_second'];
        $rateSource = (string) ($row['rate_source'] ?? self::RATE_SOURCE_MANUAL);
        if ($rateSource !== self::RATE_SOURCE_DAILY_AVERAGE_24H) {
            $rateSource = self::RATE_SOURCE_MANUAL;
        }

        $effectiveRate = $rateSource === self::RATE_SOURCE_DAILY_AVERAGE_24H
            ? self::computeRateFromDailyAverage($dailyLimit)
            : self::clampRate($storedRate);

        $alibabaCap = isset($row['alibaba_rate_cap']) && $row['alibaba_rate_cap'] !== null && $row['alibaba_rate_cap'] !== ''
            ? (float) $row['alibaba_rate_cap']
            : null;
        $maxRate = isset($row['max_rate_per_second']) && $row['max_rate_per_second'] !== null && $row['max_rate_per_second'] !== ''
            ? (float) $row['max_rate_per_second']
            : null;

        $workerBatch = isset($row['worker_batch_gap_ms']) ? (int) $row['worker_batch_gap_ms'] : 100;
        $workerChunk = isset($row['worker_chunk_gap_ms']) ? (int) $row['worker_chunk_gap_ms'] : 50;
        $workerConc = isset($row['worker_send_concurrency']) ? (int) $row['worker_send_concurrency'] : 1;
        $workerPool = isset($row['worker_smtp_pool_connections']) ? (int) $row['worker_smtp_pool_connections'] : 0;

        $fetchSz = isset($row['worker_fetch_batch_size']) ? (int) $row['worker_fetch_batch_size'] : 10000;
        $sendSz = isset($row['worker_send_batch_size']) ? (int) $row['worker_send_batch_size'] : 500;
        $maxLanes = isset($row['worker_max_smtp_lanes']) ? (int) $row['worker_max_smtp_lanes'] : 10;
        $throttleStep = isset($row['worker_throttle_step_up']) ? (float) $row['worker_throttle_step_up'] : 0.5;
        $throttleCd = isset($row['worker_throttle_cooldown_ms']) ? (int) $row['worker_throttle_cooldown_ms'] : 15000;
        $poolMsg = isset($row['worker_smtp_pool_max_messages']) ? (int) $row['worker_smtp_pool_max_messages'] : 100;
        $aliWarm = isset($row['alibaba_warmup_max_rate_per_second']) && $row['alibaba_warmup_max_rate_per_second'] !== null && $row['alibaba_warmup_max_rate_per_second'] !== ''
            ? (float) $row['alibaba_warmup_max_rate_per_second']
            : null;
        if ($aliWarm !== null) {
            $aliWarm = max(0.2, min(self::MAX_RATE_PER_SECOND, $aliWarm));
        }

        return [
            'daily_limit' => $dailyLimit,
            'rate_per_second' => $effectiveRate,
            'stored_rate_per_second' => $storedRate,
            'rate_source' => $rateSource,
            'hourly_limit' => (int) floor($effectiveRate * 3600),
            'minute_limit' => (int) floor($effectiveRate * 60),
            'alibaba_rate_cap' => $alibabaCap,
            'max_rate_per_second' => $maxRate,
            'worker_batch_gap_ms' => max(0, $workerBatch),
            'worker_chunk_gap_ms' => max(0, $workerChunk),
            'worker_send_concurrency' => max(1, min(50, $workerConc)),
            'worker_smtp_pool_connections' => max(0, min(20, $workerPool)),
            'worker_fetch_batch_size' => max(100, min(500000, $fetchSz)),
            'worker_send_batch_size' => max(10, min(5000, $sendSz)),
            'worker_max_smtp_lanes' => max(1, min(50, $maxLanes)),
            'worker_throttle_step_up' => max(0.01, min(10.0, $throttleStep)),
            'worker_throttle_cooldown_ms' => max(1000, min(600000, $throttleCd)),
            'worker_smtp_pool_max_messages' => max(1, min(50000, $poolMsg)),
            'alibaba_warmup_max_rate_per_second' => $aliWarm,
        ];
    }

    /**
     * Email worker API yanıtı için kısa blok.
     */
    public function getWorkerRuntimePayload(): array
    {
        $c = $this->getConfig();

        return [
            'batch_gap_ms' => $c['worker_batch_gap_ms'],
            'chunk_gap_ms' => $c['worker_chunk_gap_ms'],
            'send_concurrency_per_lane' => $c['worker_send_concurrency'],
            'smtp_pool_max_connections' => $c['worker_smtp_pool_connections'],
            'smtp_pool_max_messages' => $c['worker_smtp_pool_max_messages'],
            'rate_per_second_effective' => $c['rate_per_second'],
            'fetch_batch_size' => $c['worker_fetch_batch_size'],
            'send_batch_size' => $c['worker_send_batch_size'],
            'max_smtp_lanes' => $c['worker_max_smtp_lanes'],
            'throttle_step_up_per_second' => $c['worker_throttle_step_up'],
            'throttle_cooldown_ms' => $c['worker_throttle_cooldown_ms'],
            'alibaba_warmup_max_rate_per_second' => $c['alibaba_warmup_max_rate_per_second'],
        ];
    }

    private function defaults(): array
    {
        return [
            'daily_limit' => 20000,
            'rate_per_second' => 1.0,
            'stored_rate_per_second' => 1.0,
            'rate_source' => self::RATE_SOURCE_MANUAL,
            'hourly_limit' => 3600,
            'minute_limit' => 60,
            'alibaba_rate_cap' => null,
            'max_rate_per_second' => null,
            'worker_batch_gap_ms' => 100,
            'worker_chunk_gap_ms' => 50,
            'worker_send_concurrency' => 1,
            'worker_smtp_pool_connections' => 0,
            'worker_fetch_batch_size' => 10000,
            'worker_send_batch_size' => 500,
            'worker_max_smtp_lanes' => 10,
            'worker_throttle_step_up' => 0.5,
            'worker_throttle_cooldown_ms' => 15000,
            'worker_smtp_pool_max_messages' => 100,
            'alibaba_warmup_max_rate_per_second' => null,
        ];
    }

    /**
     * @param array{
     *   daily_limit:int,
     *   rate_per_second?:float,
     *   rate_source:string,
     *   alibaba_rate_cap?:float|null,
     *   max_rate_per_second?:float|null,
     *   worker_batch_gap_ms:int,
     *   worker_chunk_gap_ms:int,
     *   worker_send_concurrency:int,
     *   worker_smtp_pool_connections:int,
     *   worker_fetch_batch_size:int,
     *   worker_send_batch_size:int,
     *   worker_max_smtp_lanes:int,
     *   worker_throttle_step_up:float,
     *   worker_throttle_cooldown_ms:int,
     *   worker_smtp_pool_max_messages:int,
     *   alibaba_warmup_max_rate_per_second?:float|null
     * } $data
     */
    public function saveAdminSettings(array $data): bool
    {
        $daily = (int) ($data['daily_limit'] ?? 0);
        $rateSource = (string) ($data['rate_source'] ?? self::RATE_SOURCE_MANUAL);
        if ($rateSource !== self::RATE_SOURCE_DAILY_AVERAGE_24H) {
            $rateSource = self::RATE_SOURCE_MANUAL;
        }

        $rateStored = $rateSource === self::RATE_SOURCE_DAILY_AVERAGE_24H
            ? self::computeRateFromDailyAverage($daily)
            : self::clampRate((float) ($data['rate_per_second'] ?? 1.0));

        $alibaba = $data['alibaba_rate_cap'] ?? null;
        $maxR = $data['max_rate_per_second'] ?? null;

        $aliWarmRaw = $data['alibaba_warmup_max_rate_per_second'] ?? null;
        $aliWarmSave = null;
        if ($aliWarmRaw !== null && $aliWarmRaw !== '') {
            $aliWarmSave = max(0.2, min(self::MAX_RATE_PER_SECOND, (float) $aliWarmRaw));
        }

        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO email_sending_config (
                id, daily_limit, rate_per_second, alibaba_rate_cap, max_rate_per_second,
                rate_source, worker_batch_gap_ms, worker_chunk_gap_ms, worker_send_concurrency, worker_smtp_pool_connections,
                worker_fetch_batch_size, worker_send_batch_size, worker_max_smtp_lanes,
                worker_throttle_step_up, worker_throttle_cooldown_ms, worker_smtp_pool_max_messages,
                alibaba_warmup_max_rate_per_second
            ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                daily_limit = VALUES(daily_limit),
                rate_per_second = VALUES(rate_per_second),
                alibaba_rate_cap = VALUES(alibaba_rate_cap),
                max_rate_per_second = VALUES(max_rate_per_second),
                rate_source = VALUES(rate_source),
                worker_batch_gap_ms = VALUES(worker_batch_gap_ms),
                worker_chunk_gap_ms = VALUES(worker_chunk_gap_ms),
                worker_send_concurrency = VALUES(worker_send_concurrency),
                worker_smtp_pool_connections = VALUES(worker_smtp_pool_connections),
                worker_fetch_batch_size = VALUES(worker_fetch_batch_size),
                worker_send_batch_size = VALUES(worker_send_batch_size),
                worker_max_smtp_lanes = VALUES(worker_max_smtp_lanes),
                worker_throttle_step_up = VALUES(worker_throttle_step_up),
                worker_throttle_cooldown_ms = VALUES(worker_throttle_cooldown_ms),
                worker_smtp_pool_max_messages = VALUES(worker_smtp_pool_max_messages),
                alibaba_warmup_max_rate_per_second = VALUES(alibaba_warmup_max_rate_per_second)',
            [
                $daily,
                $rateStored,
                $alibaba,
                $maxR,
                $rateSource,
                (int) $data['worker_batch_gap_ms'],
                (int) $data['worker_chunk_gap_ms'],
                (int) $data['worker_send_concurrency'],
                (int) $data['worker_smtp_pool_connections'],
                (int) $data['worker_fetch_batch_size'],
                (int) $data['worker_send_batch_size'],
                (int) $data['worker_max_smtp_lanes'],
                (float) $data['worker_throttle_step_up'],
                (int) $data['worker_throttle_cooldown_ms'],
                (int) $data['worker_smtp_pool_max_messages'],
                $aliWarmSave,
            ]
        );

        return true;
    }

    /**
     * @deprecated Tam form için saveAdminSettings kullanın; geriye dönük imza.
     */
    public function setConfig(int $dailyLimit, float $ratePerSecond, ?float $alibabaRateCap = null, ?float $maxRatePerSecond = null): bool
    {
        $cur = $this->getConfig();

        return $this->saveAdminSettings([
            'daily_limit' => $dailyLimit,
            'rate_per_second' => $ratePerSecond,
            'rate_source' => self::RATE_SOURCE_MANUAL,
            'alibaba_rate_cap' => $alibabaRateCap,
            'max_rate_per_second' => $maxRatePerSecond,
            'worker_batch_gap_ms' => $cur['worker_batch_gap_ms'],
            'worker_chunk_gap_ms' => $cur['worker_chunk_gap_ms'],
            'worker_send_concurrency' => $cur['worker_send_concurrency'],
            'worker_smtp_pool_connections' => $cur['worker_smtp_pool_connections'],
            'worker_fetch_batch_size' => $cur['worker_fetch_batch_size'],
            'worker_send_batch_size' => $cur['worker_send_batch_size'],
            'worker_max_smtp_lanes' => $cur['worker_max_smtp_lanes'],
            'worker_throttle_step_up' => $cur['worker_throttle_step_up'],
            'worker_throttle_cooldown_ms' => $cur['worker_throttle_cooldown_ms'],
            'worker_smtp_pool_max_messages' => $cur['worker_smtp_pool_max_messages'],
            'alibaba_warmup_max_rate_per_second' => $cur['alibaba_warmup_max_rate_per_second'],
        ]);
    }

    public function applyPlan(string $planKey): bool
    {
        if (!isset(self::PLANS[$planKey])) {
            return false;
        }
        $plan = self::PLANS[$planKey];
        $daily = (int) $plan['daily_limit'];
        $effective = self::computeRateFromDailyAverage($daily);
        $worker = self::workerProfileForRate($effective, $daily);

        return $this->saveAdminSettings(array_merge([
            'daily_limit' => $daily,
            'rate_per_second' => $effective,
            'rate_source' => self::RATE_SOURCE_DAILY_AVERAGE_24H,
            'alibaba_rate_cap' => null,
            'max_rate_per_second' => null,
        ], $worker));
    }

    public function getPlans(): array
    {
        return self::PLANS;
    }

    /**
     * Arayüz için: her pakette hesaplanmış saniyelik / saatlik / dakikalık yaklaşık değerler.
     *
     * @return array<string, array{daily_limit: int, title: string, effective_rate: float, hourly_approx: int, minute_approx: int}>
     */
    public function getPlansWithPreview(): array
    {
        $out = [];
        foreach (self::PLANS as $key => $plan) {
            $eff = self::computeRateFromDailyAverage($plan['daily_limit']);
            $out[$key] = [
                'daily_limit' => $plan['daily_limit'],
                'title' => $plan['title'],
                'effective_rate' => $eff,
                'hourly_approx' => (int) floor($eff * 3600),
                'minute_approx' => (int) floor($eff * 60),
            ];
        }

        return $out;
    }
}
