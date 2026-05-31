<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260603_DataPoolWorkerReliability extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'data_pool_jobs reliability columns, dedup staging and worker heartbeat tables';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        $tableExists = static function (string $table) use ($conn): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) > 0;
        };

        $columnExists = static function (string $table, string $column) use ($conn): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ) > 0;
        };

        $indexExists = static function (string $table, string $index) use ($conn): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $index]
            ) > 0;
        };

        if ($tableExists('data_pool_jobs')) {
            if (!$columnExists('data_pool_jobs', 'locked_by')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN locked_by VARCHAR(100) DEFAULT NULL AFTER status');
            }
            if (!$columnExists('data_pool_jobs', 'locked_at')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN locked_at DATETIME DEFAULT NULL AFTER locked_by');
            }
            if (!$columnExists('data_pool_jobs', 'heartbeat_at')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN heartbeat_at DATETIME DEFAULT NULL AFTER locked_at');
            }
            if (!$columnExists('data_pool_jobs', 'attempts')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER heartbeat_at');
            }
            if (!$columnExists('data_pool_jobs', 'max_attempts')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN max_attempts INT NOT NULL DEFAULT 3 AFTER attempts');
            }
            if (!$columnExists('data_pool_jobs', 'resumable')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN resumable TINYINT(1) NOT NULL DEFAULT 1 AFTER max_attempts');
            }
            if (!$columnExists('data_pool_jobs', 'cancel_requested')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN cancel_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER resumable');
            }
            if (!$columnExists('data_pool_jobs', 'last_processed_id')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN last_processed_id BIGINT DEFAULT NULL AFTER cancel_requested');
            }
            if (!$columnExists('data_pool_jobs', 'cursor_payload')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN cursor_payload JSON DEFAULT NULL AFTER last_processed_id');
            }
            if (!$columnExists('data_pool_jobs', 'next_run_at')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN next_run_at DATETIME DEFAULT NULL AFTER cursor_payload');
            }
            if (!$columnExists('data_pool_jobs', 'current_step')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN current_step VARCHAR(120) DEFAULT NULL AFTER next_run_at');
            }
            if (!$columnExists('data_pool_jobs', 'status_message')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN status_message VARCHAR(255) DEFAULT NULL AFTER current_step');
            }
            if (!$columnExists('data_pool_jobs', 'error_code')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN error_code VARCHAR(64) DEFAULT NULL AFTER error_message');
            }
            if (!$columnExists('data_pool_jobs', 'exception_class')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN exception_class VARCHAR(190) DEFAULT NULL AFTER error_code');
            }
            if (!$columnExists('data_pool_jobs', 'failed_step')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN failed_step VARCHAR(120) DEFAULT NULL AFTER exception_class');
            }
            if (!$columnExists('data_pool_jobs', 'last_sql_name')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN last_sql_name VARCHAR(120) DEFAULT NULL AFTER failed_step');
            }
            if (!$columnExists('data_pool_jobs', 'worker_id')) {
                $this->addSql('ALTER TABLE data_pool_jobs ADD COLUMN worker_id VARCHAR(100) DEFAULT NULL AFTER last_sql_name');
            }

            if (!$indexExists('data_pool_jobs', 'idx_data_pool_jobs_status_next_run')) {
                $this->addSql('CREATE INDEX idx_data_pool_jobs_status_next_run ON data_pool_jobs (status, next_run_at, id)');
            }
            if (!$indexExists('data_pool_jobs', 'idx_data_pool_jobs_heartbeat')) {
                $this->addSql('CREATE INDEX idx_data_pool_jobs_heartbeat ON data_pool_jobs (status, heartbeat_at)');
            }
            if (!$indexExists('data_pool_jobs', 'idx_data_pool_jobs_locked_by')) {
                $this->addSql('CREATE INDEX idx_data_pool_jobs_locked_by ON data_pool_jobs (locked_by, locked_at)');
            }
        }

        if (!$tableExists('data_pool_worker_heartbeats')) {
            $this->addSql(
                "CREATE TABLE data_pool_worker_heartbeats (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    worker_id VARCHAR(100) NOT NULL,
                    hostname VARCHAR(255) DEFAULT NULL,
                    pid INT DEFAULT NULL,
                    current_job_id BIGINT DEFAULT NULL,
                    status VARCHAR(30) NOT NULL,
                    heartbeat_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    UNIQUE INDEX uq_worker_id (worker_id),
                    INDEX idx_heartbeat_at (heartbeat_at),
                    PRIMARY KEY(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$tableExists('data_pool_dedup_staging')) {
            $this->addSql(
                "CREATE TABLE data_pool_dedup_staging (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    job_id BIGINT UNSIGNED NOT NULL,
                    normalized_email VARCHAR(320) NOT NULL,
                    keep_id BIGINT UNSIGNED NOT NULL,
                    duplicate_count INT NOT NULL DEFAULT 0,
                    processed TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX idx_job_processed (job_id, processed, id),
                    INDEX idx_job_email (job_id, normalized_email),
                    INDEX idx_keep_id (keep_id),
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
