#!/usr/bin/env php
<?php
/**
 * email_sending_config: worker_smtp_rotation_limit INT DEFAULT 500
 * Vendorsuz çalışır — config/load-env.php ve config/database-env.php okur.
 *
 *   php bin/apply-smtp-rotation-limit-config.php
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);

require_once $baseDir . '/config/load-env.php';
require_once $baseDir . '/config/database-env.php';

nexus_ensure_env_loaded();
$db = nexus_database_env();

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['dbname']};charset=utf8mb4",
        $db['user'],
        $db['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "DB bağlantısı başarısız: " . $e->getMessage() . "\n");
    exit(1);
}

$exists = (int) $pdo->query(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'email_sending_config'
       AND COLUMN_NAME  = 'worker_smtp_rotation_limit'"
)->fetchColumn();

if ($exists) {
    echo "worker_smtp_rotation_limit kolonu zaten mevcut — atlandı.\n";
    exit(0);
}

$pdo->exec(
    "ALTER TABLE email_sending_config
     ADD COLUMN worker_smtp_rotation_limit INT NOT NULL DEFAULT 500
     AFTER worker_smtp_pool_max_messages"
);

echo "✓ email_sending_config.worker_smtp_rotation_limit eklendi (DEFAULT 500).\n";
