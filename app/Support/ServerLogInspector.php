<?php

declare(strict_types=1);

namespace App\Support;

final class ServerLogInspector
{
    /** @return list<array{key: string, label: string, path: string}> */
    public static function discoverLogFiles(): array
    {
        $candidates = [
            ['key' => 'app', 'label' => 'Uygulama (Nexus)', 'paths' => self::appLogCandidates()],
            ['key' => 'nginx_error', 'label' => 'Nginx error', 'paths' => [
                '/var/log/nginx/error.log',
                '/var/log/nginx/mail.error.log',
            ]],
            ['key' => 'nginx_access', 'label' => 'Nginx access', 'paths' => [
                '/var/log/nginx/access.log',
            ]],
            ['key' => 'php_fpm', 'label' => 'PHP-FPM', 'paths' => self::phpFpmLogCandidates()],
            ['key' => 'apache_error', 'label' => 'Apache error', 'paths' => [
                '/var/log/apache2/error.log',
                '/var/log/httpd/error_log',
            ]],
            ['key' => 'syslog', 'label' => 'Syslog (php/nginx)', 'paths' => [
                '/var/log/syslog',
            ]],
        ];

        $out = [];
        foreach ($candidates as $group) {
            foreach ($group['paths'] as $path) {
                if ($path !== '' && is_readable($path)) {
                    $out[] = [
                        'key' => $group['key'],
                        'label' => $group['label'],
                        'path' => $path,
                    ];
                    break;
                }
            }
        }

        return $out;
    }

    public static function tailFile(string $path, int $lines = 80): string
    {
        if (!is_readable($path)) {
            return "Okunamıyor: {$path}";
        }

        $lines = max(10, min(500, $lines));
        $escaped = escapeshellarg($path);
        $cmd = "tail -n {$lines} {$escaped} 2>/dev/null";
        $out = shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            return rtrim($out);
        }

        $content = @file_get_contents($path);
        if ($content === false || $content === '') {
            return '(boş)';
        }

        $all = explode("\n", $content);

        return implode("\n", array_slice($all, -$lines));
    }

    /** @return list<string> */
    public static function filterErrorLines(string $text): array
    {
        $needles = [
            'error', 'fatal', 'exception', 'crit', 'alert', 'emerg',
            'upstream timed out', '504', '502', '413', 'primary script unknown',
            'allowed memory', 'maximum execution', 'post_max_size', 'client intended',
        ];
        $hits = [];
        foreach (explode("\n", $text) as $line) {
            $l = trim($line);
            if ($l === '') {
                continue;
            }
            $lower = strtolower($l);
            foreach ($needles as $n) {
                if (str_contains($lower, $n)) {
                    $hits[] = $l;
                    break;
                }
            }
        }

        return $hits;
    }

    public static function phpRuntimeSummary(): string
    {
        $keys = [
            'PHP Version' => PHP_VERSION,
            'SAPI' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'max_input_time' => ini_get('max_input_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'display_errors' => ini_get('display_errors'),
            'error_log' => ini_get('error_log') ?: '(default/syslog)',
            'APP_DEBUG' => $_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: '-',
        ];
        $lines = [];
        foreach ($keys as $k => $v) {
            $lines[] = sprintf('  %-22s %s', $k . ':', (string) $v);
        }

        if (filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN)) {
            $lines[] = '  ⚠ UYARI: APP_DEBUG=true — production\'da .env içinde false yapın';
        }

        return implode(PHP_EOL, $lines);
    }

    public static function nginxSiteHints(): string
    {
        $dirs = ['/etc/nginx/sites-enabled', '/etc/nginx/sites-available', '/etc/nginx/conf.d'];
        $lines = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (!is_file($file)) {
                    continue;
                }
                if (str_contains(basename($file), '.bak')) {
                    continue;
                }
                $content = (string) @file_get_contents($file);
                if (preg_match_all('/client_max_body_size\s+([^;]+);/i', $content, $bodyMatches)) {
                    foreach ($bodyMatches[1] as $val) {
                        $lines[] = basename($file) . ' → client_max_body_size ' . trim($val);
                    }
                }
                if (preg_match('/fastcgi_read_timeout\s+([^;]+);/i', $content, $m)) {
                    $lines[] = basename($file) . ' → fastcgi_read_timeout ' . trim($m[1]);
                }
            }
        }

        return $lines === [] ? '  (nginx site config bulunamadı veya okunamadı)' : implode(PHP_EOL, $lines);
    }

    /** @return list<string> */
    private static function appLogCandidates(): array
    {
        $base = dirname(__DIR__, 2);
        $today = $base . '/storage/logs/app-' . date('Y-m-d') . '.log';
        $paths = [$today, $base . '/storage/logs/app.log'];
        $raw = trim((string) ($_ENV['LOG_PATH'] ?? getenv('LOG_PATH') ?: ''));
        if ($raw !== '') {
            $paths[] = str_starts_with($raw, '/') ? $raw : $base . '/' . ltrim($raw, '/');
        }

        return $paths;
    }

    /** @return list<string> */
    private static function phpFpmLogCandidates(): array
    {
        $paths = [];
        foreach (glob('/var/log/php*-fpm.log') ?: [] as $f) {
            $paths[] = $f;
        }
        foreach (glob('/var/log/php*/fpm.log') ?: [] as $f) {
            $paths[] = $f;
        }
        $paths[] = '/var/log/php8.3-fpm.log';
        $paths[] = '/var/log/php8.2-fpm.log';

        return array_values(array_unique($paths));
    }
}
