<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530_FixEmailDataPoolAnalysisJobsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_data_pool_analysis_jobs tablosunu güvenli şekilde oluşturur/eksik kolon-indexleri tamamlar';
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

        if (!$tableExists('email_data_pool_analysis_jobs')) {
            $this->addSql("CREATE TABLE email_data_pool_analysis_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                list_id INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                total_count BIGINT NOT NULL DEFAULT 0,
                processed_count BIGINT NOT NULL DEFAULT 0,
                percent INT NOT NULL DEFAULT 0,
                progress DECIMAL(6,2) NOT NULL DEFAULT 0,
                chunk_size INT NOT NULL DEFAULT 25000,
                last_id BIGINT NOT NULL DEFAULT 0,
                gmail_count BIGINT NOT NULL DEFAULT 0,
                non_gmail_count BIGINT NOT NULL DEFAULT 0,
                duplicate_count BIGINT NOT NULL DEFAULT 0,
                invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                deletable_count BIGINT NOT NULL DEFAULT 0,
                gmail_ratio DECIMAL(6,2) NOT NULL DEFAULT 0,
                normalized_preview JSON DEFAULT NULL,
                non_gmail_preview JSON DEFAULT NULL,
                message VARCHAR(255) DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                completed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_email_pool_analysis_jobs_list (list_id),
                INDEX idx_email_pool_analysis_jobs_status (status),
                INDEX idx_email_pool_analysis_jobs_created_at (created_at),
                INDEX idx_email_pool_analysis_jobs_list_status (list_id, status),
                CONSTRAINT fk_email_pool_analysis_jobs_list FOREIGN KEY (list_id) REFERENCES email_data_pool_lists (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            return;
        }

        if (!$columnExists('email_data_pool_analysis_jobs', 'processed_count')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD processed_count BIGINT NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'percent')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD percent INT NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'progress')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD progress DECIMAL(6,2) NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'chunk_size')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD chunk_size INT NOT NULL DEFAULT 25000');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'last_id')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD last_id BIGINT NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'deletable_count')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD deletable_count BIGINT NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'message')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD message VARCHAR(255) DEFAULT NULL');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'started_at')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD started_at DATETIME DEFAULT NULL');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'finished_at')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD finished_at DATETIME DEFAULT NULL');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'completed_at')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD completed_at DATETIME DEFAULT NULL');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'created_at')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
        }
        if (!$columnExists('email_data_pool_analysis_jobs', 'updated_at')) {
            $this->addSql('ALTER TABLE email_data_pool_analysis_jobs ADD updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
        }

        if (!$indexExists('email_data_pool_analysis_jobs', 'idx_email_pool_analysis_jobs_list')) {
            $this->addSql('CREATE INDEX idx_email_pool_analysis_jobs_list ON email_data_pool_analysis_jobs (list_id)');
        }
        if (!$indexExists('email_data_pool_analysis_jobs', 'idx_email_pool_analysis_jobs_status')) {
            $this->addSql('CREATE INDEX idx_email_pool_analysis_jobs_status ON email_data_pool_analysis_jobs (status)');
        }
        if (!$indexExists('email_data_pool_analysis_jobs', 'idx_email_pool_analysis_jobs_created_at')) {
            $this->addSql('CREATE INDEX idx_email_pool_analysis_jobs_created_at ON email_data_pool_analysis_jobs (created_at)');
        }
        if (!$indexExists('email_data_pool_analysis_jobs', 'idx_email_pool_analysis_jobs_list_status')) {
            $this->addSql('CREATE INDEX idx_email_pool_analysis_jobs_list_status ON email_data_pool_analysis_jobs (list_id, status)');
        }
    }

    public function down(Schema $schema): void
    {
        // Bu migration güvenlik/fix amaçlıdır; down'da tabloyu düşürmek risklidir.
    }
}

