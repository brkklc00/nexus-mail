<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530_EmailDataPoolAnalysisCacheAndNormalization extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email data pool analiz cache tablolari ve buyuk liste icin normalize/index iyilestirmeleri';
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

        if ($tableExists('email_data_pool')) {
            if (!$columnExists('email_data_pool', 'normalized_email')) {
                $this->addSql('ALTER TABLE email_data_pool ADD normalized_email VARCHAR(255) DEFAULT NULL');
            }
            if (!$columnExists('email_data_pool', 'domain')) {
                $this->addSql('ALTER TABLE email_data_pool ADD domain VARCHAR(191) DEFAULT NULL');
            }

            $this->addSql("UPDATE email_data_pool
                SET normalized_email = LOWER(TRIM(email))
                WHERE normalized_email IS NULL OR normalized_email = ''");
            $this->addSql("UPDATE email_data_pool
                SET domain = SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1)
                WHERE domain IS NULL OR domain = ''");

            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_id_id')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_id_id ON email_data_pool (pool_list_id, id)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_normalized')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_normalized ON email_data_pool (pool_list_id, normalized_email)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_domain')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_domain ON email_data_pool (pool_list_id, domain)');
            }
        }

        if (!$tableExists('email_data_pool_analysis_cache')) {
            $this->addSql("CREATE TABLE email_data_pool_analysis_cache (
                list_id INT NOT NULL,
                total_count BIGINT NOT NULL DEFAULT 0,
                gmail_count BIGINT NOT NULL DEFAULT 0,
                non_gmail_count BIGINT NOT NULL DEFAULT 0,
                invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                duplicate_count BIGINT NOT NULL DEFAULT 0,
                deletable_count BIGINT NOT NULL DEFAULT 0,
                gmail_ratio DECIMAL(6,2) NOT NULL DEFAULT 0,
                target_limit BIGINT DEFAULT NULL,
                over_limit_count BIGINT NOT NULL DEFAULT 0,
                missing_count BIGINT NOT NULL DEFAULT 0,
                normalized_preview JSON DEFAULT NULL,
                non_gmail_preview JSON DEFAULT NULL,
                last_analyzed_at DATETIME DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'idle',
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (list_id),
                CONSTRAINT fk_email_data_pool_analysis_cache_list FOREIGN KEY (list_id) REFERENCES email_data_pool_lists (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }

        if (!$tableExists('email_data_pool_analysis_jobs')) {
            $this->addSql("CREATE TABLE email_data_pool_analysis_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                list_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'idle',
                total_count BIGINT NOT NULL DEFAULT 0,
                processed_count BIGINT NOT NULL DEFAULT 0,
                percent INT NOT NULL DEFAULT 0,
                chunk_size INT NOT NULL DEFAULT 25000,
                last_id BIGINT NOT NULL DEFAULT 0,
                gmail_count BIGINT NOT NULL DEFAULT 0,
                non_gmail_count BIGINT NOT NULL DEFAULT 0,
                invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                duplicate_count BIGINT NOT NULL DEFAULT 0,
                deletable_count BIGINT NOT NULL DEFAULT 0,
                gmail_ratio DECIMAL(6,2) NOT NULL DEFAULT 0,
                normalized_preview JSON DEFAULT NULL,
                non_gmail_preview JSON DEFAULT NULL,
                message VARCHAR(255) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                completed_at DATETIME DEFAULT NULL,
                PRIMARY KEY(id),
                INDEX idx_email_pool_analysis_jobs_list_status (list_id, status),
                INDEX idx_email_pool_analysis_jobs_status (status),
                CONSTRAINT fk_email_pool_analysis_jobs_list FOREIGN KEY (list_id) REFERENCES email_data_pool_lists (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    public function down(Schema $schema): void
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

        if ($tableExists('email_data_pool_analysis_jobs')) {
            $this->addSql('DROP TABLE email_data_pool_analysis_jobs');
        }
        if ($tableExists('email_data_pool_analysis_cache')) {
            $this->addSql('DROP TABLE email_data_pool_analysis_cache');
        }

        if ($tableExists('email_data_pool')) {
            if ($indexExists('email_data_pool', 'idx_email_data_pool_list_domain')) {
                $this->addSql('DROP INDEX idx_email_data_pool_list_domain ON email_data_pool');
            }
            if ($indexExists('email_data_pool', 'idx_email_data_pool_list_normalized')) {
                $this->addSql('DROP INDEX idx_email_data_pool_list_normalized ON email_data_pool');
            }
            if ($indexExists('email_data_pool', 'idx_email_data_pool_list_id_id')) {
                $this->addSql('DROP INDEX idx_email_data_pool_list_id_id ON email_data_pool');
            }
            if ($columnExists('email_data_pool', 'domain')) {
                $this->addSql('ALTER TABLE email_data_pool DROP COLUMN domain');
            }
            if ($columnExists('email_data_pool', 'normalized_email')) {
                $this->addSql('ALTER TABLE email_data_pool DROP COLUMN normalized_email');
            }
        }
    }
}

