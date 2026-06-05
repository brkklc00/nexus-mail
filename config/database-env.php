<?php

declare(strict_types=1);

/**
 * Veritabanı bağlantı bilgisi: DB_* > EMAIL_DB_* > DATABASE_URL > varsayılan.
 */
function nexus_database_env(): array
{
    $config = [
        'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
        'host' => $_ENV['DB_HOST'] ?? $_ENV['EMAIL_DB_HOST'] ?? 'localhost',
        'port' => (int) ($_ENV['DB_PORT'] ?? $_ENV['EMAIL_DB_PORT'] ?? 3306),
        'dbname' => $_ENV['DB_NAME'] ?? $_ENV['EMAIL_DB_NAME'] ?? 'nexus_mail',
        'user' => $_ENV['DB_USER'] ?? $_ENV['EMAIL_DB_USER'] ?? 'root',
        'password' => array_key_exists('DB_PASSWORD', $_ENV)
            ? (string) $_ENV['DB_PASSWORD']
            : (string) ($_ENV['EMAIL_DB_PASSWORD'] ?? ''),
        'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
    ];

    $databaseUrl = trim((string) ($_ENV['DATABASE_URL'] ?? ''));
    if ($databaseUrl !== '' && str_starts_with($databaseUrl, 'mysql://')) {
        $parsed = parse_url($databaseUrl);
        if (is_array($parsed)) {
            if (empty($_ENV['DB_HOST']) && empty($_ENV['EMAIL_DB_HOST']) && !empty($parsed['host'])) {
                $config['host'] = $parsed['host'];
            }
            if (empty($_ENV['DB_PORT']) && empty($_ENV['EMAIL_DB_PORT']) && !empty($parsed['port'])) {
                $config['port'] = (int) $parsed['port'];
            }
            if (empty($_ENV['DB_NAME']) && empty($_ENV['EMAIL_DB_NAME']) && !empty($parsed['path'])) {
                $config['dbname'] = ltrim((string) $parsed['path'], '/');
            }
            if (empty($_ENV['DB_USER']) && empty($_ENV['EMAIL_DB_USER']) && isset($parsed['user'])) {
                $config['user'] = rawurldecode((string) $parsed['user']);
            }
            if (
                !array_key_exists('DB_PASSWORD', $_ENV)
                && !array_key_exists('EMAIL_DB_PASSWORD', $_ENV)
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
