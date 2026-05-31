<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260602_AlibabaInvalidJobs extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alibaba invalid entegrasyonu için tablolar, kolonlar ve indexler';
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

        if (!$tableExists('alibaba_invalid_addresses')) {
            $this->addSql("CREATE TABLE alibaba_invalid_addresses (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                email VARCHAR(320) NOT NULL,
                normalized_email VARCHAR(320) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'alibaba',
                raw_payload JSON DEFAULT NULL,
                first_seen_at DATETIME DEFAULT NULL,
                last_seen_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_normalized_email (normalized_email),
                INDEX idx_email (email),
                INDEX idx_normalized_email (normalized_email),
                INDEX idx_last_seen_at (last_seen_at),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$tableExists('alibaba_invalid_fetch_logs')) {
            $this->addSql("CREATE TABLE alibaba_invalid_fetch_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                job_id BIGINT DEFAULT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                page_size INT NOT NULL DEFAULT 500,
                next_start VARCHAR(255) DEFAULT NULL,
                fetched_count INT NOT NULL DEFAULT 0,
                saved_count INT NOT NULL DEFAULT 0,
                matched_count INT NOT NULL DEFAULT 0,
                cleaned_count INT NOT NULL DEFAULT 0,
                retry_count INT NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                error_message TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_job_id (job_id),
                INDEX idx_status (status),
                INDEX idx_date_range (start_date, end_date),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if ($tableExists('email_data_pool')) {
            if (!$columnExists('email_data_pool', 'is_invalid')) {
                $this->addSql('ALTER TABLE email_data_pool ADD is_invalid TINYINT(1) NOT NULL DEFAULT 0');
            }
            if (!$columnExists('email_data_pool', 'invalid_source')) {
                $this->addSql('ALTER TABLE email_data_pool ADD invalid_source VARCHAR(50) DEFAULT NULL');
            }
            if (!$columnExists('email_data_pool', 'invalid_reason')) {
                $this->addSql('ALTER TABLE email_data_pool ADD invalid_reason VARCHAR(255) DEFAULT NULL');
            }
            if (!$columnExists('email_data_pool', 'invalid_marked_at')) {
                $this->addSql('ALTER TABLE email_data_pool ADD invalid_marked_at DATETIME DEFAULT NULL');
            }

            if (!$indexExists('email_data_pool', 'idx_pool_invalid')) {
                $this->addSql('CREATE INDEX idx_pool_invalid ON email_data_pool (pool_list_id, is_invalid)');
            }
            if ($columnExists('email_data_pool', 'normalized_email') && !$indexExists('email_data_pool', 'idx_normalized_invalid')) {
                $this->addSql('CREATE INDEX idx_normalized_invalid ON email_data_pool (normalized_email, is_invalid)');
            }
            if ($columnExists('email_data_pool', 'status') && !$indexExists('email_data_pool', 'idx_status_invalid')) {
                $this->addSql('CREATE INDEX idx_status_invalid ON email_data_pool (status, is_invalid)');
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Üretimde geri alma önerilmez.
    }
}
