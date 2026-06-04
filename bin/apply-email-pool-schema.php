#!/usr/bin/env php
<?php

/**
 * Admin sayfaları için eksik tablolar/kolonlar (idempotent):
 * - email_data_pool_lists (+ total_count vb.)
 * - email_sending_config (Hız & Limitler)
 * - email_smtp_accounts (SMTP)
 * - email_smtp_daily_reports (SMTP Alibaba raporu)
 *
 * Migration yarım kaldıysa veya Slim Application Error görüyorsanız:
 *   cd /var/www/nexus && php bin/apply-email-pool-schema.php
 *   bash bin/run-doctrine-migrations.sh
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

echo "=== Email admin şema yaması ({$dbName}) ===\n\n";

try {
    if (!$tableExists($pdo, $dbName, 'email_smtp_accounts')) {
        echo "→ email_smtp_accounts oluşturuluyor...\n";
        $pdo->exec('CREATE TABLE email_smtp_accounts (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            host VARCHAR(255) NOT NULL,
            port INT NOT NULL,
            username VARCHAR(255) NOT NULL,
            password LONGTEXT NOT NULL,
            encryption VARCHAR(10) DEFAULT NULL,
            from_email VARCHAR(255) NOT NULL,
            from_name VARCHAR(255) DEFAULT NULL,
            daily_limit INT NOT NULL DEFAULT 1000,
            daily_sent INT NOT NULL DEFAULT 0,
            last_reset_date DATE DEFAULT NULL,
            hourly_limit INT NOT NULL DEFAULT 100,
            hourly_sent INT NOT NULL DEFAULT 0,
            last_reset_hour DATETIME DEFAULT NULL,
            minute_limit INT NOT NULL DEFAULT 10,
            minute_sent INT NOT NULL DEFAULT 0,
            last_reset_minute DATETIME DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 1,
            total_sent INT NOT NULL DEFAULT 0,
            total_failed INT NOT NULL DEFAULT 0,
            success_rate DECIMAL(5,2) NOT NULL DEFAULT 100.00,
            last_used_at DATETIME DEFAULT NULL,
            last_error LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_smtp_active (is_active),
            INDEX idx_smtp_priority (priority),
            INDEX idx_smtp_reset_date (last_reset_date)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    } else {
        foreach ([
            'hourly_limit' => 'INT NOT NULL DEFAULT 100',
            'hourly_sent' => 'INT NOT NULL DEFAULT 0',
            'last_reset_hour' => 'DATETIME DEFAULT NULL',
            'minute_limit' => 'INT NOT NULL DEFAULT 10',
            'minute_sent' => 'INT NOT NULL DEFAULT 0',
            'last_reset_minute' => 'DATETIME DEFAULT NULL',
        ] as $col => $def) {
            if (!$columnExists($pdo, $dbName, 'email_smtp_accounts', $col)) {
                echo "→ email_smtp_accounts.{$col} ekleniyor...\n";
                $pdo->exec("ALTER TABLE email_smtp_accounts ADD {$col} {$def}");
            }
        }
    }

    if (!$tableExists($pdo, $dbName, 'email_smtp_daily_reports')) {
        echo "→ email_smtp_daily_reports oluşturuluyor...\n";
        $pdo->exec('CREATE TABLE email_smtp_daily_reports (
            id INT AUTO_INCREMENT NOT NULL,
            source VARCHAR(40) NOT NULL,
            report_date DATE NOT NULL,
            smtp_name VARCHAR(191) NOT NULL,
            domain VARCHAR(191) NOT NULL,
            total INT NOT NULL DEFAULT 0,
            successful INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            invalid_address INT NOT NULL DEFAULT 0,
            success_rate NUMERIC(5, 2) NOT NULL DEFAULT 0.00,
            invalid_rate NUMERIC(5, 2) NOT NULL DEFAULT 0.00,
            raw_payload LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_smtp_daily_report (source, report_date, domain, smtp_name),
            INDEX idx_smtp_daily_report_date (report_date),
            INDEX idx_smtp_daily_report_source (source),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    }

    if (!$tableExists($pdo, $dbName, 'email_sending_config')) {
        echo "→ email_sending_config oluşturuluyor...\n";
        $pdo->exec('CREATE TABLE email_sending_config (
            id INT PRIMARY KEY DEFAULT 1,
            daily_limit INT NOT NULL DEFAULT 20000,
            rate_per_second DECIMAL(10,2) NOT NULL DEFAULT 1.00,
            rate_source VARCHAR(32) NOT NULL DEFAULT \'manual\',
            alibaba_rate_cap DOUBLE PRECISION DEFAULT NULL,
            max_rate_per_second DOUBLE PRECISION DEFAULT NULL,
            worker_batch_gap_ms INT NOT NULL DEFAULT 100,
            worker_chunk_gap_ms INT NOT NULL DEFAULT 50,
            worker_send_concurrency INT NOT NULL DEFAULT 1,
            worker_smtp_pool_connections INT NOT NULL DEFAULT 0,
            worker_fetch_batch_size INT NOT NULL DEFAULT 10000,
            worker_send_batch_size INT NOT NULL DEFAULT 500,
            worker_max_smtp_lanes INT NOT NULL DEFAULT 10,
            worker_throttle_step_up DOUBLE PRECISION NOT NULL DEFAULT 0.5,
            worker_throttle_cooldown_ms INT NOT NULL DEFAULT 15000,
            worker_smtp_pool_max_messages INT NOT NULL DEFAULT 100,
            alibaba_warmup_max_rate_per_second DOUBLE PRECISION DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('INSERT IGNORE INTO email_sending_config (id, daily_limit, rate_per_second) VALUES (1, 20000, 1.00)');
    }

    if (!$tableExists($pdo, $dbName, 'email_data_pool_lists')) {
        echo "→ email_data_pool_lists oluşturuluyor...\n";
        $pdo->exec('CREATE TABLE email_data_pool_lists (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            total_count INT NOT NULL DEFAULT 0,
            active_count INT NOT NULL DEFAULT 0,
            passive_count INT NOT NULL DEFAULT 0,
            updated_count_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB');
    } else {
        foreach ([
            'total_count' => 'INT NOT NULL DEFAULT 0',
            'active_count' => 'INT NOT NULL DEFAULT 0',
            'passive_count' => 'INT NOT NULL DEFAULT 0',
            'updated_count_at' => 'DATETIME DEFAULT NULL',
        ] as $col => $def) {
            if (!$columnExists($pdo, $dbName, 'email_data_pool_lists', $col)) {
                echo "→ email_data_pool_lists.{$col} ekleniyor...\n";
                $pdo->exec("ALTER TABLE email_data_pool_lists ADD {$col} {$def}");
            }
        }
    }

    // Eski kurulumlarda total_count NOT NULL ama DEFAULT yoksa INSERT düşer — önce düzelt
    foreach ([
        'total_count' => 'INT NOT NULL DEFAULT 0',
        'active_count' => 'INT NOT NULL DEFAULT 0',
        'passive_count' => 'INT NOT NULL DEFAULT 0',
    ] as $col => $def) {
        if ($columnExists($pdo, $dbName, 'email_data_pool_lists', $col)) {
            $pdo->exec("ALTER TABLE email_data_pool_lists MODIFY {$col} {$def}");
        }
    }

    $insertCols = ['id', 'name', 'sort_order', 'created_at'];
    $insertVals = ['1', "'Liste 1'", '0', 'NOW()'];
    if ($columnExists($pdo, $dbName, 'email_data_pool_lists', 'total_count')) {
        $insertCols[] = 'total_count';
        $insertVals[] = '0';
    }
    if ($columnExists($pdo, $dbName, 'email_data_pool_lists', 'active_count')) {
        $insertCols[] = 'active_count';
        $insertVals[] = '0';
    }
    if ($columnExists($pdo, $dbName, 'email_data_pool_lists', 'passive_count')) {
        $insertCols[] = 'passive_count';
        $insertVals[] = '0';
    }
    $colList = implode(', ', $insertCols);
    $valList = implode(', ', $insertVals);
    $pdo->exec("INSERT INTO email_data_pool_lists ({$colList})
        SELECT {$valList} FROM DUAL
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
