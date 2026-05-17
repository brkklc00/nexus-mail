#!/usr/bin/env php
<?php

/**
 * email_order_emails: locked_at, locked_by, attempt_count, last_error_*, failed_at + index.
 * Panel API updateEmailCampaignEmails / Doctrine EmailOrderEmail ile uyum (1054 locked_at).
 *
 *   cd /var/www/main && php bin/apply-email-order-emails-claim-columns.php
 *
 * Idempotent. Deploy sonrası otomatik çağrılır (.github/workflows/deploy-workers.yml).
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);

if (!is_file($baseDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php bulunamadı. Proje kökünden çalıştırın.\n");
    exit(1);
}

require $baseDir . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable($baseDir);
$dotenv->safeLoad();

$db = require $baseDir . '/config/db-config.php';
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['dbname'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO($dsn, $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, 'Veritabanı bağlantısı başarısız: ' . $e->getMessage() . "\n");
    exit(1);
}

$schema = $db['dbname'];

$tableExists = static function (PDO $pdo, string $schema, string $table): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([$schema, $table]);

    return (int) $st->fetchColumn() > 0;
};

$columnExists = static function (PDO $pdo, string $schema, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$schema, $table, $col]);

    return (int) $st->fetchColumn() > 0;
};

$indexExists = static function (PDO $pdo, string $schema, string $table, string $idx): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$schema, $table, $idx]);

    return (int) $st->fetchColumn() > 0;
};

if (!$tableExists($pdo, $schema, 'email_order_emails')) {
    fwrite(STDERR, "Tablo email_order_emails yok; atlanıyor.\n");
    exit(0);
}

$alters = [
    ['locked_at', 'ALTER TABLE `email_order_emails` ADD COLUMN `locked_at` DATETIME NULL DEFAULT NULL'],
    ['locked_by', 'ALTER TABLE `email_order_emails` ADD COLUMN `locked_by` VARCHAR(120) NULL DEFAULT NULL'],
    ['attempt_count', 'ALTER TABLE `email_order_emails` ADD COLUMN `attempt_count` INT NOT NULL DEFAULT 0'],
    ['last_error_code', 'ALTER TABLE `email_order_emails` ADD COLUMN `last_error_code` VARCHAR(64) NULL DEFAULT NULL'],
    ['last_error_category', 'ALTER TABLE `email_order_emails` ADD COLUMN `last_error_category` VARCHAR(32) NULL DEFAULT NULL'],
    ['failed_at', 'ALTER TABLE `email_order_emails` ADD COLUMN `failed_at` DATETIME NULL DEFAULT NULL'],
];

foreach ($alters as [$col, $sql]) {
    if ($columnExists($pdo, $schema, 'email_order_emails', $col)) {
        fwrite(STDOUT, "OK: email_order_emails.$col zaten var.\n");
        continue;
    }
    try {
        $pdo->exec($sql);
        fwrite(STDOUT, "Tamam: email_order_emails.$col eklendi.\n");
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '1060') || str_contains($msg, 'Duplicate column')) {
            fwrite(STDOUT, "OK: email_order_emails.$col yarışta eklendi.\n");
        } else {
            fwrite(STDERR, "ALTER ($col) başarısız: $msg\n");
            exit(1);
        }
    }
}

if (!$indexExists($pdo, $schema, 'email_order_emails', 'idx_email_order_email_order_status')) {
    try {
        $pdo->exec('CREATE INDEX idx_email_order_email_order_status ON email_order_emails (order_id, status)');
        fwrite(STDOUT, "Tamam: idx_email_order_email_order_status oluşturuldu.\n");
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '1061') || str_contains($msg, 'Duplicate key')) {
            fwrite(STDOUT, "OK: indeks zaten vardı.\n");
        } else {
            fwrite(STDERR, "CREATE INDEX başarısız: $msg\n");
            exit(1);
        }
    }
} else {
    fwrite(STDOUT, "OK: idx_email_order_email_order_status zaten var.\n");
}

fwrite(STDOUT, "email_order_emails claim kolonları tamam.\n");
exit(0);
