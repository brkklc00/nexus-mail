#!/usr/bin/env php
<?php

/**
 * Kampanya #456 silinir; #457 status=completed, metrikler ve created_at (#456 ile aynı).
 *
 *   php bin/fix-email-orders-remove-456-finalize-457.php
 *
 * Veritabanı: config/db-config.php + .env (apply-email-order-emails-claim-columns ile aynı).
 */

declare(strict_types=1);

$baseDir = dirname(__DIR__);

if (!is_file($baseDir . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php bulunamadı.\n");
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

const REMOVE_ORDER_ID = 456;
const UPDATE_ORDER_ID = 457;
const NEW_TOTAL = 973000;
const NEW_SENT = 962889;
const NEW_DELIVERED = 962889;
const NEW_FAILED = 3115;

$st = $pdo->prepare('SELECT id, status, created_at FROM email_orders WHERE id IN (?, ?)');
$st->execute([REMOVE_ORDER_ID, UPDATE_ORDER_ID]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($rows as $r) {
    $byId[(int) $r['id']] = $r;
}

if (!isset($byId[UPDATE_ORDER_ID])) {
    fwrite(STDERR, "email_orders id=" . UPDATE_ORDER_ID . " bulunamadı; işlem yapılmadı.\n");
    exit(1);
}

if (!isset($byId[REMOVE_ORDER_ID])) {
    fwrite(STDERR, "Uyarı: id=" . REMOVE_ORDER_ID . " yok; sadece #" . UPDATE_ORDER_ID . " güncellenecek.\n");
    $createdFrom456 = null;
} else {
    $createdFrom456 = $byId[REMOVE_ORDER_ID]['created_at'];
}

$deliveryPct = NEW_TOTAL > 0 ? (int) round((NEW_DELIVERED / NEW_TOTAL) * 100) : 0;
$deliveryPct = max(0, min(100, $deliveryPct));

$pdo->beginTransaction();

try {
    if ($createdFrom456 !== null) {
        $del = $pdo->prepare('DELETE FROM email_orders WHERE id = ?');
        $del->execute([REMOVE_ORDER_ID]);
        echo 'Silindi: email_orders #' . REMOVE_ORDER_ID . " (ilişkili email_order_emails CASCADE ile silinir).\n";
    }

    $sql = <<<'SQL'
UPDATE email_orders SET
    status = 'completed',
    total = ?,
    sent = ?,
    delivered = ?,
    failed = ?,
    bounced = 0,
    delivery_percentage = ?,
    created_at = COALESCE(?, created_at),
    updated_at = NOW(),
    completed_at = NOW(),
    worker_paused = 0,
    worker_stop_requested = 0,
    locked_at = NULL,
    locked_by = NULL
WHERE id = ?
SQL;

    $upd = $pdo->prepare($sql);
    $upd->execute([
        NEW_TOTAL,
        NEW_SENT,
        NEW_DELIVERED,
        NEW_FAILED,
        $deliveryPct,
        $createdFrom456,
        UPDATE_ORDER_ID,
    ]);

    if ($upd->rowCount() !== 1) {
        throw new RuntimeException('UPDATE beklenen satır sayısı 1 değil.');
    }

    $pdo->commit();
    echo 'Güncellendi: email_orders #' . UPDATE_ORDER_ID . " → completed, total/sent/delivered/failed = "
        . NEW_TOTAL . '/' . NEW_SENT . '/' . NEW_DELIVERED . '/' . NEW_FAILED . ", delivery_percentage={$deliveryPct}.\n";
    if ($createdFrom456 !== null) {
        echo 'created_at: #' . REMOVE_ORDER_ID . " kaydındaki tarih ile ayarlandı.\n";
    }
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Hata: ' . $e->getMessage() . "\n");
    exit(1);
}
