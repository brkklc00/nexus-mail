<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nexus Mail Engine: worker doğrudan DB bulk yazımı, claim alanları, metrik tabloları, pause/stop bayrakları.
 */
final class Version20260511_EmailWorkerDirectDbMetrics extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_order_emails claim/hata alanları; email_orders worker pause/stop; campaign_batch_metrics; campaign_runtime_summary';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = $conn->getDatabase();

        $columnExists = static function (string $table, string $col) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$dbName, $table, $col]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        $tableExists = static function (string $table) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, $table]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        $indexExists = static function (string $table, string $indexName) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$dbName, $table, $indexName]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        if ($tableExists('email_order_emails')) {
            if (!$columnExists('email_order_emails', 'locked_at')) {
                $conn->executeStatement('ALTER TABLE email_order_emails ADD COLUMN locked_at DATETIME NULL DEFAULT NULL AFTER delivered_at');
            }
            if (!$columnExists('email_order_emails', 'locked_by')) {
                $conn->executeStatement('ALTER TABLE email_order_emails ADD COLUMN locked_by VARCHAR(120) NULL DEFAULT NULL AFTER locked_at');
            }
            if (!$columnExists('email_order_emails', 'attempt_count')) {
                $conn->executeStatement('ALTER TABLE email_order_emails ADD COLUMN attempt_count INT NOT NULL DEFAULT 0 AFTER locked_by');
            }
            if (!$columnExists('email_order_emails', 'last_error_code')) {
                $conn->executeStatement('ALTER TABLE email_order_emails ADD COLUMN last_error_code VARCHAR(64) NULL DEFAULT NULL AFTER attempt_count');
            }
            if (!$columnExists('email_order_emails', 'last_error_category')) {
                $conn->executeStatement('ALTER TABLE email_order_emails ADD COLUMN last_error_category VARCHAR(32) NULL DEFAULT NULL AFTER last_error_code');
            }
            if (!$columnExists('email_order_emails', 'failed_at')) {
                $conn->executeStatement('ALTER TABLE email_order_emails ADD COLUMN failed_at DATETIME NULL DEFAULT NULL AFTER last_error_category');
            }
            if (!$indexExists('email_order_emails', 'idx_email_order_email_order_status')) {
                $conn->executeStatement('CREATE INDEX idx_email_order_email_order_status ON email_order_emails (order_id, status)');
            }
        }

        if ($tableExists('email_orders')) {
            if (!$columnExists('email_orders', 'worker_paused')) {
                $conn->executeStatement('ALTER TABLE email_orders ADD COLUMN worker_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER attempt_count');
            }
            if (!$columnExists('email_orders', 'worker_stop_requested')) {
                $conn->executeStatement('ALTER TABLE email_orders ADD COLUMN worker_stop_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER worker_paused');
            }
        }

        if (!$tableExists('campaign_batch_metrics')) {
            $conn->executeStatement(
                'CREATE TABLE campaign_batch_metrics (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    campaign_id INT NOT NULL,
                    worker_id VARCHAR(120) NOT NULL,
                    batch_no INT NOT NULL DEFAULT 0,
                    batch_size INT NOT NULL DEFAULT 0,
                    success_count INT NOT NULL DEFAULT 0,
                    failed_count INT NOT NULL DEFAULT 0,
                    retry_count INT NOT NULL DEFAULT 0,
                    recipient_rejected_count INT NOT NULL DEFAULT 0,
                    connection_error_count INT NOT NULL DEFAULT 0,
                    provider_error_count INT NOT NULL DEFAULT 0,
                    internal_error_count INT NOT NULL DEFAULT 0,
                    smtp_duration_ms INT NOT NULL DEFAULT 0,
                    db_flush_duration_ms INT NOT NULL DEFAULT 0,
                    total_duration_ms INT NOT NULL DEFAULT 0,
                    queue_wait_ms INT NOT NULL DEFAULT 0,
                    lane_count INT NOT NULL DEFAULT 0,
                    smtp_account_count INT NOT NULL DEFAULT 0,
                    top_error_code VARCHAR(64) NULL,
                    top_error_message VARCHAR(512) NULL,
                    started_at DATETIME NULL,
                    finished_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cbm_campaign (campaign_id),
                    INDEX idx_cbm_created (created_at),
                    CONSTRAINT fk_cbm_campaign FOREIGN KEY (campaign_id) REFERENCES email_orders (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        if (!$tableExists('campaign_runtime_summary')) {
            $conn->executeStatement(
                'CREATE TABLE campaign_runtime_summary (
                    campaign_id INT NOT NULL PRIMARY KEY,
                    total_processed BIGINT NOT NULL DEFAULT 0,
                    total_success BIGINT NOT NULL DEFAULT 0,
                    total_failed BIGINT NOT NULL DEFAULT 0,
                    total_retry BIGINT NOT NULL DEFAULT 0,
                    total_rejected BIGINT NOT NULL DEFAULT 0,
                    overall_speed_per_sec DECIMAL(12,4) NOT NULL DEFAULT 0,
                    smtp_speed_per_sec DECIMAL(12,4) NOT NULL DEFAULT 0,
                    avg_db_flush_ms DECIMAL(12,2) NOT NULL DEFAULT 0,
                    total_db_flush_ms BIGINT NOT NULL DEFAULT 0,
                    total_queue_wait_ms BIGINT NOT NULL DEFAULT 0,
                    reject_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
                    retry_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
                    connection_error_rate DECIMAL(7,4) NOT NULL DEFAULT 0,
                    bottleneck_type VARCHAR(32) NULL,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_crs_campaign FOREIGN KEY (campaign_id) REFERENCES email_orders (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = $conn->getDatabase();

        $tableExists = static function (string $table) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, $table]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        if ($tableExists('campaign_runtime_summary')) {
            $conn->executeStatement('DROP TABLE campaign_runtime_summary');
        }
        if ($tableExists('campaign_batch_metrics')) {
            $conn->executeStatement('DROP TABLE campaign_batch_metrics');
        }
        // Kolon düşürme — prod için riskli; migration geri alma sadece tabloları siler
    }
}
