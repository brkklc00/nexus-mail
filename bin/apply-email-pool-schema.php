#!/usr/bin/env php
<?php

/**
 * Email havuz listeleri + email_orders.pool_list_id + email_sending_config tavan kolonları.
 * Doctrine migration dosyası sunucuda yoksa veya migrate "latest" yanlış görünüyorsa çalıştırın:
 *
 *   cd /var/www/main && php bin/apply-email-pool-schema.php
 *
 * Idempotent: birden fazla kez güvenle çalıştırılabilir.
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

$dbName = $db['dbname'];

$columnExists = static function (PDO $pdo, string $schema, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$schema, $table, $col]);

    return (int) $st->fetchColumn() > 0;
};

$tableExists = static function (PDO $pdo, string $schema, string $table): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $st->execute([$schema, $table]);

    return (int) $st->fetchColumn() > 0;
};

$fkExists = static function (PDO $pdo, string $schema, string $table, string $fkName): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?'
    );
    $st->execute([$schema, $table, $fkName, 'FOREIGN KEY']);

    return (int) $st->fetchColumn() > 0;
};

$indexExists = static function (PDO $pdo, string $schema, string $table, string $indexName): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$schema, $table, $indexName]);

    return (int) $st->fetchColumn() > 0;
};

$columnNullable = static function (PDO $pdo, string $schema, string $table, string $col): bool {
    $st = $pdo->prepare(
        'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$schema, $table, $col]);
    $v = $st->fetchColumn();

    return $v === 'YES';
};

echo "=== Email pool / gönderim şema yaması ({$dbName}) ===\n\n";

try {
    if (!$tableExists($pdo, $dbName, 'email_data_pool_lists')) {
        echo "→ email_data_pool_lists oluşturuluyor...\n";
        $pdo->exec('CREATE TABLE email_data_pool_lists (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    $pdo->exec("INSERT INTO email_data_pool_lists (id, name, sort_order, created_at)
        SELECT 1, 'Liste 1', 0, NOW() FROM DUAL
        WHERE NOT EXISTS (SELECT 1 FROM email_data_pool_lists WHERE id = 1 LIMIT 1)");

    if ($tableExists($pdo, $dbName, 'email_sending_config')) {
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'alibaba_rate_cap')) {
            echo "→ email_sending_config.alibaba_rate_cap ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD alibaba_rate_cap DOUBLE PRECISION DEFAULT NULL');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'max_rate_per_second')) {
            echo "→ email_sending_config.max_rate_per_second ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD max_rate_per_second DOUBLE PRECISION DEFAULT NULL');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'rate_source')) {
            echo "→ email_sending_config.rate_source ekleniyor...\n";
            $pdo->exec("ALTER TABLE email_sending_config ADD rate_source VARCHAR(32) NOT NULL DEFAULT 'manual'");
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_batch_gap_ms')) {
            echo "→ email_sending_config.worker_batch_gap_ms ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_batch_gap_ms INT NOT NULL DEFAULT 100');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_chunk_gap_ms')) {
            echo "→ email_sending_config.worker_chunk_gap_ms ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_chunk_gap_ms INT NOT NULL DEFAULT 50');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_send_concurrency')) {
            echo "→ email_sending_config.worker_send_concurrency ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_send_concurrency INT NOT NULL DEFAULT 1');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_smtp_pool_connections')) {
            echo "→ email_sending_config.worker_smtp_pool_connections ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_smtp_pool_connections INT NOT NULL DEFAULT 0');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_fetch_batch_size')) {
            echo "→ email_sending_config.worker_fetch_batch_size ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_fetch_batch_size INT NOT NULL DEFAULT 10000');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_send_batch_size')) {
            echo "→ email_sending_config.worker_send_batch_size ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_send_batch_size INT NOT NULL DEFAULT 500');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_max_smtp_lanes')) {
            echo "→ email_sending_config.worker_max_smtp_lanes ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_max_smtp_lanes INT NOT NULL DEFAULT 10');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_throttle_step_up')) {
            echo "→ email_sending_config.worker_throttle_step_up ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_throttle_step_up DOUBLE PRECISION NOT NULL DEFAULT 0.5');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_throttle_cooldown_ms')) {
            echo "→ email_sending_config.worker_throttle_cooldown_ms ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_throttle_cooldown_ms INT NOT NULL DEFAULT 15000');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'worker_smtp_pool_max_messages')) {
            echo "→ email_sending_config.worker_smtp_pool_max_messages ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD worker_smtp_pool_max_messages INT NOT NULL DEFAULT 100');
        }
        if (!$columnExists($pdo, $dbName, 'email_sending_config', 'alibaba_warmup_max_rate_per_second')) {
            echo "→ email_sending_config.alibaba_warmup_max_rate_per_second ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_sending_config ADD alibaba_warmup_max_rate_per_second DOUBLE PRECISION DEFAULT NULL');
        }
    }

    if ($tableExists($pdo, $dbName, 'email_data_pool')) {
        if (!$columnExists($pdo, $dbName, 'email_data_pool', 'pool_list_id')) {
            echo "→ email_data_pool.pool_list_id ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_data_pool ADD pool_list_id INT DEFAULT NULL');
        }
        $pdo->exec('UPDATE email_data_pool SET pool_list_id = 1 WHERE pool_list_id IS NULL');
        if ($columnNullable($pdo, $dbName, 'email_data_pool', 'pool_list_id')) {
            echo "→ email_data_pool.pool_list_id NOT NULL yapılıyor...\n";
            $pdo->exec('ALTER TABLE email_data_pool MODIFY pool_list_id INT NOT NULL');
        }
        if (!$fkExists($pdo, $dbName, 'email_data_pool', 'FK_edp_pool_list')) {
            echo "→ FK_edp_pool_list ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_data_pool ADD CONSTRAINT FK_edp_pool_list FOREIGN KEY (pool_list_id) REFERENCES email_data_pool_lists (id)');
        }
        if (!$indexExists($pdo, $dbName, 'email_data_pool', 'idx_email_data_pool_list_active_id')) {
            echo "→ idx_email_data_pool_list_active_id ekleniyor...\n";
            $pdo->exec('CREATE INDEX idx_email_data_pool_list_active_id ON email_data_pool (pool_list_id, is_active, id)');
        }
    }

    if ($tableExists($pdo, $dbName, 'email_orders')) {
        if (!$columnExists($pdo, $dbName, 'email_orders', 'pool_list_id')) {
            echo "→ email_orders.pool_list_id ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_orders ADD pool_list_id INT DEFAULT NULL');
        }
        if (!$fkExists($pdo, $dbName, 'email_orders', 'FK_eo_pool_list')) {
            echo "→ FK_eo_pool_list ekleniyor...\n";
            $pdo->exec('ALTER TABLE email_orders ADD CONSTRAINT FK_eo_pool_list FOREIGN KEY (pool_list_id) REFERENCES email_data_pool_lists (id) ON DELETE SET NULL');
        }
    }

    $migrationVersion = 'Database\\Migrations\\Version20260330_EmailSendingCapsAndPoolLists';
    if ($tableExists($pdo, $dbName, 'doctrine_migration_versions')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM doctrine_migration_versions WHERE version = ?');
        $st->execute([$migrationVersion]);
        if ((int) $st->fetchColumn() === 0) {
            echo "→ doctrine_migration_versions kaydı ekleniyor...\n";
            try {
                $pdo->prepare(
                    'INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES (?, NOW(), NULL)'
                )->execute([$migrationVersion]);
            } catch (PDOException $e) {
                try {
                    $pdo->prepare('INSERT INTO doctrine_migration_versions (version, executed_at) VALUES (?, NOW())')->execute([$migrationVersion]);
                } catch (PDOException $e2) {
                    echo "⚠️  doctrine_migration_versions eklenemedi (elle migrate de çalıştırabilirsiniz): {$e2->getMessage()}\n";
                }
            }
        }
    }

    echo "\n✅ Tamamlandı. Sayfayı yenileyin.\n";
} catch (PDOException $e) {
    fwrite(STDERR, "\n❌ Hata: " . $e->getMessage() . "\n");
    exit(1);
}
