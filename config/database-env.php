<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';

/**
 * Veritabanı bağlantı bilgisi: DB_* > EMAIL_DB_* > DATABASE_URL > varsayılan.
 */
function nexus_database_env(): array
{
    nexus_ensure_env_loaded();

    $config = [
        'driver' => nexus_env('DB_DRIVER', 'pdo_mysql') ?? 'pdo_mysql',
        'host' => nexus_env('DB_HOST') ?? nexus_env('EMAIL_DB_HOST', 'localhost') ?? 'localhost',
        'port' => (int) (nexus_env('DB_PORT') ?? nexus_env('EMAIL_DB_PORT', '3306') ?? '3306'),
        'dbname' => nexus_env('DB_NAME') ?? nexus_env('EMAIL_DB_NAME', 'nexus_mail') ?? 'nexus_mail',
        'user' => nexus_env('DB_USER') ?? nexus_env('EMAIL_DB_USER', 'root') ?? 'root',
        'password' => nexus_env('DB_PASSWORD') ?? nexus_env('EMAIL_DB_PASSWORD', '') ?? '',
        'charset' => nexus_env('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4',
    ];

    $databaseUrl = trim((string) (nexus_env('DATABASE_URL') ?? ''));
    if ($databaseUrl !== '' && str_starts_with($databaseUrl, 'mysql://')) {
        $parsed = parse_url($databaseUrl);
        if (is_array($parsed)) {
            if (nexus_env('DB_HOST') === null && nexus_env('EMAIL_DB_HOST') === null && !empty($parsed['host'])) {
                $config['host'] = $parsed['host'];
            }
            if (nexus_env('DB_PORT') === null && nexus_env('EMAIL_DB_PORT') === null && !empty($parsed['port'])) {
                $config['port'] = (int) $parsed['port'];
            }
            if (nexus_env('DB_NAME') === null && nexus_env('EMAIL_DB_NAME') === null && !empty($parsed['path'])) {
                $config['dbname'] = ltrim((string) $parsed['path'], '/');
            }
            if (nexus_env('DB_USER') === null && nexus_env('EMAIL_DB_USER') === null && isset($parsed['user'])) {
                $config['user'] = rawurldecode((string) $parsed['user']);
            }
            if (
                nexus_env('DB_PASSWORD') === null
                && nexus_env('EMAIL_DB_PASSWORD') === null
                && isset($parsed['pass'])
            ) {
                $config['password'] = rawurldecode((string) $parsed['pass']);
            }
        }
    }

    if ($config['host'] === 'localhost') {
        $config['host'] = '127.0.0.1';
    }

    return $config;
}
