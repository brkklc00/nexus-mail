<?php

declare(strict_types=1);

namespace App\Support;

final class AppErrorLogger
{
    public static function log(\Throwable $e, ?string $logPath = null, array $context = []): string
    {
        $errorId = 'ERR-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $path = self::resolveLogPath($logPath);
        self::ensureLogDir($path);

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '-');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '-');
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '-');
        $userId = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;

        $lines = [
            str_repeat('=', 72),
            '[' . date('Y-m-d H:i:s') . "] {$errorId}",
            "Host: {$host}",
            "Request: {$method} {$uri}",
            'User ID: ' . ($userId !== null ? (string) $userId : '-'),
            'Exception: ' . $e::class,
            'Message: ' . $e->getMessage(),
            'File: ' . $e->getFile() . ':' . $e->getLine(),
        ];

        if ($context !== []) {
            $lines[] = 'Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }

        $lines[] = $e->getTraceAsString();
        $lines[] = '';

        @file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND | LOCK_EX);
        error_log("[{$errorId}] " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine());

        return $errorId;
    }

    public static function tail(int $lines = 80, ?string $logPath = null): string
    {
        $path = self::resolveLogPath($logPath);
        if (!is_readable($path)) {
            return "Log dosyası yok: {$path}";
        }

        $content = (string) file_get_contents($path);
        if ($content === '') {
            return 'Log dosyası boş.';
        }

        $all = explode("\n", $content);
        $slice = array_slice($all, max(0, count($all) - $lines));

        return implode("\n", $slice);
    }

    private static function resolveLogPath(?string $logPath): string
    {
        $base = dirname(__DIR__, 2);
        $raw = trim((string) ($logPath ?? $_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: ''));
        if ($raw === '') {
            return $base . '/storage/logs/app-' . date('Y-m-d') . '.log';
        }
        if (!str_starts_with($raw, '/')) {
            return $base . '/' . ltrim($raw, '/');
        }

        return $raw;
    }

    private static function ensureLogDir(string $filePath): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }
}
