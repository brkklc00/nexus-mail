<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531_EmailPoolStatsAndJobs extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_pool_stats ve data_pool_jobs tablolarini olusturur; buyuk veri indexlerini tamamlar';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) $conn->getDatabase();

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

        if (!$tableExists('email_pool_stats')) {
            $this->addSql("CREATE TABLE email_pool_stats (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                pool_id INT NOT NULL,
                total_count BIGINT NOT NULL DEFAULT 0,
                active_count BIGINT NOT NULL DEFAULT 0,
                gmail_count BIGINT NOT NULL DEFAULT 0,
                non_gmail_count BIGINT NOT NULL DEFAULT 0,
                invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                duplicate_count BIGINT NOT NULL DEFAULT 0,
                target_limit BIGINT DEFAULT NULL,
                last_analyzed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uq_email_pool_stats_pool_id (pool_id),
                INDEX idx_email_pool_stats_pool_id (pool_id),
                INDEX idx_email_pool_stats_last_analyzed_at (last_analyzed_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_email_pool_stats_pool FOREIGN KEY (pool_id) REFERENCES email_data_pool_lists (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$tableExists('data_pool_jobs')) {
            $this->addSql("CREATE TABLE data_pool_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                pool_id INT DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                payload JSON DEFAULT NULL,
                total_count BIGINT NOT NULL DEFAULT 0,
                processed_count BIGINT NOT NULL DEFAULT 0,
                success_count BIGINT NOT NULL DEFAULT 0,
                failed_count BIGINT NOT NULL DEFAULT 0,
                progress_percent INT NOT NULL DEFAULT 0,
                result JSON DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_data_pool_jobs_pool_status (pool_id, status),
                INDEX idx_data_pool_jobs_type_status (type, status),
                INDEX idx_data_pool_jobs_status_created (status, created_at),
                PRIMARY KEY(id),
                CONSTRAINT fk_data_pool_jobs_pool FOREIGN KEY (pool_id) REFERENCES email_data_pool_lists (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if ($tableExists('email_data_pool')) {
            if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_status')) {
                $statusColumn = $columnExists('email_data_pool', 'status') ? 'status' : 'is_active';
                $this->addSql("CREATE INDEX idx_email_pool_items_pool_status ON email_data_pool (pool_list_id, {$statusColumn})");
            }

            if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_domain') && $columnExists('email_data_pool', 'domain')) {
                $this->addSql('CREATE INDEX idx_email_pool_items_pool_domain ON email_data_pool (pool_list_id, domain)');
            }

            if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_normalized_email') && $columnExists('email_data_pool', 'normalized_email')) {
                $this->addSql('CREATE INDEX idx_email_pool_items_pool_normalized_email ON email_data_pool (pool_list_id, normalized_email)');
            }

            if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_email')) {
                $this->addSql('CREATE INDEX idx_email_pool_items_pool_email ON email_data_pool (pool_list_id, email)');
            }

            if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_created_at')) {
                $this->addSql('CREATE INDEX idx_email_pool_items_pool_created_at ON email_data_pool (pool_list_id, created_at)');
            }
        }

        if ($tableExists('email_data_pool_lists')) {
            $this->addSql("INSERT INTO email_pool_stats (pool_id, total_count, active_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at, created_at, updated_at)
                SELECT
                    l.id,
                    COALESCE(l.total_count, 0),
                    COALESCE(l.active_count, 0),
                    COALESCE(c.gmail_count, 0),
                    COALESCE(c.non_gmail_count, 0),
                    COALESCE(c.invalid_gmail_count, 0),
                    COALESCE(c.duplicate_count, 0),
                    COALESCE(c.target_limit, NULL),
                    c.last_analyzed_at,
                    NOW(),
                    NOW()
                FROM email_data_pool_lists l
                LEFT JOIN email_data_pool_analysis_cache c ON c.list_id = l.id
                ON DUPLICATE KEY UPDATE
                    total_count = VALUES(total_count),
                    active_count = VALUES(active_count),
                    gmail_count = VALUES(gmail_count),
                    non_gmail_count = VALUES(non_gmail_count),
                    invalid_gmail_count = VALUES(invalid_gmail_count),
                    duplicate_count = VALUES(duplicate_count),
                    target_limit = VALUES(target_limit),
                    last_analyzed_at = VALUES(last_analyzed_at),
                    updated_at = VALUES(updated_at)");
        }
    }

    public function down(Schema $schema): void
    {
        // Riskli geri alma operasyonu: üretimde bu tabloların düşürülmesi önerilmez.
    }
}
