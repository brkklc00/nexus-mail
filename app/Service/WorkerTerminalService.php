<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Process\Process;
use RuntimeException;

class WorkerTerminalService
{
    private const WORKER_NAME = 'email-worker';
    private const MAX_OUTPUT_BYTES = 204800; // 200 KB
    private const ACTION_TIMEOUT_SECONDS = 15;
    private const LOG_TIMEOUT_SECONDS = 20;

    /** @var array<string, array<int, string>> */
    private const ACTION_MAP = [
        'status' => ['pm2', 'jlist'],
        'restart' => ['pm2', 'restart', 'email-worker'],
        'stop' => ['pm2', 'stop', 'email-worker'],
        'start' => ['pm2', 'start', 'email-worker'],
        'reset' => ['pm2', 'reset', 'email-worker'],
        'logs' => ['pm2', 'logs', 'email-worker', '--lines', '100', '--nostream'],
        'error_logs' => ['pm2', 'logs', 'email-worker', '--err', '--lines', '100', '--nostream'],
        'flush_logs' => ['pm2', 'flush', 'email-worker'],
        'pm2_status' => ['pm2', 'status'],
        'pm2_save' => ['pm2', 'save'],
        'health' => ['curl', '-s', 'http://127.0.0.1:4050/health'],
        'queue_health' => ['php', 'bin/console', 'app:worker:health'],
        'disk' => ['df', '-h'],
        'memory' => ['free', '-m'],
    ];

    /** @var array<string, array<int, string>> */
    private const SAFE_COMMANDS = [
        'pm2 status' => ['pm2', 'status'],
        'pm2 jlist' => ['pm2', 'jlist'],
        'pm2 restart email-worker' => ['pm2', 'restart', 'email-worker'],
        'pm2 stop email-worker' => ['pm2', 'stop', 'email-worker'],
        'pm2 start email-worker' => ['pm2', 'start', 'email-worker'],
        'pm2 reset email-worker' => ['pm2', 'reset', 'email-worker'],
        'pm2 logs email-worker --lines 100 --nostream' => ['pm2', 'logs', 'email-worker', '--lines', '100', '--nostream'],
        'pm2 logs email-worker --err --lines 100 --nostream' => ['pm2', 'logs', 'email-worker', '--err', '--lines', '100', '--nostream'],
        'pm2 flush email-worker' => ['pm2', 'flush', 'email-worker'],
        'pm2 save' => ['pm2', 'save'],
        'df -h' => ['df', '-h'],
        'free -m' => ['free', '-m'],
        'curl -s http://127.0.0.1:4050/health' => ['curl', '-s', 'http://127.0.0.1:4050/health'],
        'php bin/console about' => ['php', 'bin/console', 'about'],
        'php bin/console doctrine:migrations:status' => ['php', 'bin/console', 'doctrine:migrations:status'],
        'php bin/console app:worker:health' => ['php', 'bin/console', 'app:worker:health'],
    ];

    public function getWorkerStatus(): array
    {
        $result = $this->runProcess(['pm2', 'jlist'], self::ACTION_TIMEOUT_SECONDS);
        if (!$result['success']) {
            return [
                'ok' => false,
                'found' => false,
                'message' => 'PM2 durumu alınamadı',
                'error' => $result['output'],
            ];
        }

        $parsed = json_decode($result['output'], true);
        if (!is_array($parsed)) {
            return [
                'ok' => false,
                'found' => false,
                'message' => 'PM2 çıktısı çözümlenemedi',
            ];
        }

        foreach ($parsed as $process) {
            if (($process['name'] ?? '') !== self::WORKER_NAME) {
                continue;
            }

            $status = (string) ($process['pm2_env']['status'] ?? 'stopped');
            $restartCount = (int) ($process['pm2_env']['restart_time'] ?? 0);
            $cpu = (float) ($process['monit']['cpu'] ?? 0);
            $memoryBytes = (int) ($process['monit']['memory'] ?? 0);
            $uptimeSeconds = 0;
            $pmUptime = (int) ($process['pm2_env']['pm_uptime'] ?? 0);
            if ($pmUptime > 0) {
                $uptimeSeconds = max(0, time() - (int) floor($pmUptime / 1000));
            }

            return [
                'ok' => true,
                'found' => true,
                'name' => self::WORKER_NAME,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'restart_count' => $restartCount,
                'cpu_percent' => round($cpu, 2),
                'memory_bytes' => $memoryBytes,
                'memory_human' => $this->formatBytes($memoryBytes),
                'uptime_seconds' => $uptimeSeconds,
                'uptime_human' => $this->formatDuration($uptimeSeconds),
            ];
        }

        return [
            'ok' => false,
            'found' => false,
            'message' => 'email-worker PM2 icinde bulunamadi',
        ];
    }

    public function runAction(string $action): array
    {
        if (!isset(self::ACTION_MAP[$action])) {
            return [
                'success' => false,
                'message' => 'Gecersiz action',
                'output' => '',
            ];
        }

        $command = self::ACTION_MAP[$action];
        $timeout = $action === 'logs' || $action === 'error_logs' ? self::LOG_TIMEOUT_SECONDS : self::ACTION_TIMEOUT_SECONDS;
        $result = $this->runProcess($command, $timeout);

        if ($action === 'queue_health' && !$result['success']) {
            $result['success'] = true;
            $result['message'] = 'Queue health komutu calistirildi (komut desteklenmiyor olabilir).';
        }

        return $result;
    }

    public function getLogs(string $type, int $lines): array
    {
        $safeLines = max(10, min(500, $lines));
        $command = ['pm2', 'logs', self::WORKER_NAME];
        if ($type === 'out') {
            $command[] = '--out';
        } elseif ($type === 'error') {
            $command[] = '--err';
        }
        $command[] = '--lines';
        $command[] = (string) $safeLines;
        $command[] = '--nostream';

        return $this->runProcess($command, self::LOG_TIMEOUT_SECONDS);
    }

    public function runSafeCommand(string $command): array
    {
        $normalized = $this->normalizeCommand($command);
        if (!isset(self::SAFE_COMMANDS[$normalized])) {
            return [
                'success' => false,
                'blocked' => true,
                'message' => 'Bu komut guvenlik nedeniyle engellendi.',
                'output' => '',
                'command' => $normalized,
            ];
        }

        return $this->runProcess(self::SAFE_COMMANDS[$normalized], self::ACTION_TIMEOUT_SECONDS);
    }

    public function maskSecrets(string $output): string
    {
        $keys = [
            'DATABASE_URL',
            'REDIS_URL',
            'SMTP_PASSWORD',
            'PASSWORD',
            'TOKEN',
            'SECRET',
            'API_KEY',
            'AUTH_SECRET',
            'TRACKING_SECRET',
            'WASENDER_API_KEY',
            'ALIYUN',
            'ACCESS_KEY',
        ];

        $masked = $output;
        foreach ($keys as $key) {
            $pattern = '/(' . preg_quote($key, '/') . '\s*[:=]\s*)([^\s"\']+)/i';
            $masked = (string) preg_replace($pattern, '$1********', $masked);
        }

        $masked = (string) preg_replace('/(https?:\/\/[^:\s\/]+:)[^@\s\/]+@/i', '$1********@', $masked);
        return $masked;
    }

    public function createStreamProcess(string $type = 'combined'): Process
    {
        if (!class_exists(Process::class)) {
            throw new RuntimeException('symfony/process paketi yuklu degil');
        }

        $command = ['pm2', 'logs', self::WORKER_NAME];
        if ($type === 'out') {
            $command[] = '--out';
        } elseif ($type === 'error') {
            $command[] = '--err';
        }
        $command[] = '--raw';
        $command[] = '--lines';
        $command[] = '0';

        $process = new Process($command, $this->projectRoot());
        $process->setTimeout(null);
        return $process;
    }

    /** @param array<int, string> $command */
    private function runProcess(array $command, int $timeoutSeconds): array
    {
        if (!class_exists(Process::class)) {
            return [
                'success' => false,
                'command' => implode(' ', $command),
                'exit_code' => 1,
                'output' => 'symfony/process paketi yuklu degil',
                'message' => 'Komut calistirilamadi',
            ];
        }

        $process = new Process($command, $this->projectRoot());
        $process->setTimeout($timeoutSeconds);
        $process->run();

        $output = trim($process->getOutput() . "\n" . $process->getErrorOutput());
        $output = $this->limitOutput($this->maskSecrets($output));

        return [
            'success' => $process->isSuccessful(),
            'command' => implode(' ', $command),
            'exit_code' => $process->getExitCode() ?? 1,
            'output' => $output,
            'message' => $process->isSuccessful() ? 'Komut basariyla calisti' : 'Komut hatali tamamlandi',
        ];
    }

    private function normalizeCommand(string $command): string
    {
        $trimmed = trim($command);
        return (string) preg_replace('/\s+/', ' ', $trimmed);
    }

    private function limitOutput(string $output): string
    {
        if (strlen($output) <= self::MAX_OUTPUT_BYTES) {
            return $output;
        }

        return substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n...[output kisaltildi]";
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'online' => 'Online',
            'errored' => 'Errored',
            default => 'Stopped',
        };
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }

        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);

        if ($days > 0) {
            return sprintf('%d gun %d saat', $days, $hours);
        }
        if ($hours > 0) {
            return sprintf('%d saat %d dk', $hours, $minutes);
        }
        return sprintf('%d dk', max(1, $minutes));
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) max(0, $bytes);
        $idx = 0;
        while ($size >= 1024 && $idx < count($units) - 1) {
            $size /= 1024;
            ++$idx;
        }
        return round($size, 2) . ' ' . $units[$idx];
    }

    private function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }
}

