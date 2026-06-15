#!/usr/bin/env php
<?php
/**
 * email_orders: smtp_rotation_limit INT UNSIGNED DEFAULT 500
 * Vendorsuz çalışır — config/load-env.php ve config/database-env.php okur.
 *
 *   php bin/apply-smtp-rotation-limit.php
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
       AND TABLE_NAME   = 'email_orders'
       AND COLUMN_NAME  = 'smtp_rotation_limit'"
)->fetchColumn();

if ($exists) {
    echo "smtp_rotation_limit kolonu zaten mevcut — migration atlandı.\n";
    exit(0);
}

$pdo->exec(
    "ALTER TABLE email_orders
     ADD COLUMN smtp_rotation_limit INT UNSIGNED NOT NULL DEFAULT 500
     AFTER worker_stop_requested"
);

echo "✓ email_orders.smtp_rotation_limit eklendi (DEFAULT 500).\n";
