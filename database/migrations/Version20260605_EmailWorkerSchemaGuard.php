<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260605_EmailWorkerSchemaGuard extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email worker için eksik worker_paused kolonlari ve campaign metrics tablolarini idempotent tamamlar';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) ($conn->getDatabase() ?: $conn->fetchOne('SELECT DATABASE()'));

        $tableExists = static function (string $table) use ($conn, $dbName): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbName, $table]
            ) > 0;
        };
        $columnExists = static function (string $table, string $column) use ($conn, $dbName): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $column]
            ) > 0;
        };
        $indexExists = static function (string $table, string $index) use ($conn, $dbName): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$dbName, $table, $index]
            ) > 0;
        };

        if ($tableExists('email_orders')) {
            if (!$columnExists('email_orders', 'worker_paused')) {
                $this->addSql('ALTER TABLE email_orders ADD COLUMN worker_paused TINYINT(1) NOT NULL DEFAULT 0 AFTER attempt_count');
            }
            if (!$columnExists('email_orders', 'worker_stop_requested')) {
                $this->addSql('ALTER TABLE email_orders ADD COLUMN worker_stop_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER worker_paused');
            }
            if (!$indexExists('email_orders', 'idx_email_orders_worker_paused')) {
                $this->addSql('CREATE INDEX idx_email_orders_worker_paused ON email_orders (worker_paused, status)');
            }
        }

        if ($tableExists('email_order_emails')) {
            if (!$columnExists('email_order_emails', 'locked_at')) {
                $this->addSql('ALTER TABLE email_order_emails ADD COLUMN locked_at DATETIME NULL DEFAULT NULL AFTER delivered_at');
            }
            if (!$columnExists('email_order_emails', 'locked_by')) {
                $this->addSql('ALTER TABLE email_order_emails ADD COLUMN locked_by VARCHAR(120) NULL DEFAULT NULL AFTER locked_at');
            }
            if (!$columnExists('email_order_emails', 'attempt_count')) {
                $this->addSql('ALTER TABLE email_order_emails ADD COLUMN attempt_count INT NOT NULL DEFAULT 0 AFTER locked_by');
            }
            if (!$columnExists('email_order_emails', 'last_error_code')) {
                $this->addSql('ALTER TABLE email_order_emails ADD COLUMN last_error_code VARCHAR(64) NULL DEFAULT NULL AFTER attempt_count');
            }
            if (!$columnExists('email_order_emails', 'last_error_category')) {
                $this->addSql('ALTER TABLE email_order_emails ADD COLUMN last_error_category VARCHAR(32) NULL DEFAULT NULL AFTER last_error_code');
            }
            if (!$columnExists('email_order_emails', 'failed_at')) {
                $this->addSql('ALTER TABLE email_order_emails ADD COLUMN failed_at DATETIME NULL DEFAULT NULL AFTER last_error_category');
            }
            if (!$indexExists('email_order_emails', 'idx_email_order_email_order_status')) {
                $this->addSql('CREATE INDEX idx_email_order_email_order_status ON email_order_emails (order_id, status)');
            }
        }

        if (!$tableExists('campaign_batch_metrics')) {
            $this->addSql(
                "CREATE TABLE campaign_batch_metrics (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$tableExists('campaign_runtime_summary')) {
            $this->addSql(
                "CREATE TABLE campaign_runtime_summary (
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
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    public function down(Schema $schema): void
    {
        // no-op: production safety
    }
}
