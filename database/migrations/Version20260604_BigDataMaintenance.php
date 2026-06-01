<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604_BigDataMaintenance extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Büyük veri bakımı için order purge kolonları, archive/staging tabloları ve performans indexleri';
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
            if (!$columnExists('email_orders', 'details_purged_at')) {
                $this->addSql('ALTER TABLE email_orders ADD COLUMN details_purged_at DATETIME DEFAULT NULL');
            }
            if (!$columnExists('email_orders', 'purge_summary')) {
                $this->addSql('ALTER TABLE email_orders ADD COLUMN purge_summary JSON DEFAULT NULL');
            }
            if (!$indexExists('email_orders', 'idx_email_orders_status_created')) {
                $this->addSql('CREATE INDEX idx_email_orders_status_created ON email_orders (status, created_at)');
            }
            if (!$indexExists('email_orders', 'idx_email_orders_details_purged')) {
                $this->addSql('CREATE INDEX idx_email_orders_details_purged ON email_orders (details_purged_at)');
            }
        }

        if ($tableExists('email_order_emails')) {
            if (!$indexExists('email_order_emails', 'idx_recipients_created_id')) {
                $this->addSql('CREATE INDEX idx_recipients_created_id ON email_order_emails (created_at, id)');
            }
            if (!$indexExists('email_order_emails', 'idx_recipients_status_created_id')) {
                $this->addSql('CREATE INDEX idx_recipients_status_created_id ON email_order_emails (status, created_at, id)');
            }
            if (!$tableExists('email_order_emails_archive')) {
                $this->addSql('CREATE TABLE email_order_emails_archive LIKE email_order_emails');
                $this->addSql('ALTER TABLE email_order_emails_archive ADD COLUMN archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
                if (!$indexExists('email_order_emails_archive', 'idx_archive_order_id')) {
                    $this->addSql('CREATE INDEX idx_archive_order_id ON email_order_emails_archive (order_id, id)');
                }
            }
        }

        if ($tableExists('data_pool_jobs')) {
            if (!$columnExists('data_pool_jobs', 'pause_requested')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN pause_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER cancel_requested');
            }
            if (!$indexExists('data_pool_jobs', 'idx_jobs_status_created')) {
                $this->addSql('CREATE INDEX idx_jobs_status_created ON data_pool_jobs (status, created_at)');
            }
        }

        if (!$tableExists('maintenance_cleanup_targets')) {
            $this->addSql(
                "CREATE TABLE maintenance_cleanup_targets (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    job_id BIGINT UNSIGNED NOT NULL,
                    target_type VARCHAR(50) NOT NULL,
                    target_id BIGINT UNSIGNED NOT NULL,
                    processed TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_job_processed (job_id, processed),
                    INDEX idx_target (target_type, target_id),
                    PRIMARY KEY(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
    }

    public function down(Schema $schema): void
    {
        // no-op: production safety
    }
}

