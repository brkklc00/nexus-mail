<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260601_EmailDataPoolGlobalDedupColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_data_pool tablosuna global dedup kolon/indexlerini idempotent olarak ekler';
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

        if (!$tableExists('email_data_pool')) {
            return;
        }

        if (!$columnExists('email_data_pool', 'normalized_email')) {
            $this->addSql('ALTER TABLE email_data_pool ADD normalized_email VARCHAR(255) DEFAULT NULL');
            $this->addSql('UPDATE email_data_pool SET normalized_email = LOWER(TRIM(email)) WHERE normalized_email IS NULL');
        }
        if (!$columnExists('email_data_pool', 'domain')) {
            $this->addSql('ALTER TABLE email_data_pool ADD domain VARCHAR(191) DEFAULT NULL');
            $this->addSql("UPDATE email_data_pool SET domain = LOWER(SUBSTRING_INDEX(TRIM(email), '@', -1)) WHERE domain IS NULL");
        }
        if (!$columnExists('email_data_pool', 'is_duplicate')) {
            $this->addSql('ALTER TABLE email_data_pool ADD is_duplicate TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool', 'is_invalid')) {
            $this->addSql('ALTER TABLE email_data_pool ADD is_invalid TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!$columnExists('email_data_pool', 'is_gmail')) {
            $this->addSql('ALTER TABLE email_data_pool ADD is_gmail TINYINT(1) NOT NULL DEFAULT 0');
            $this->addSql("UPDATE email_data_pool SET is_gmail = CASE WHEN LOWER(SUBSTRING_INDEX(TRIM(email), '@', -1)) = 'gmail.com' THEN 1 ELSE 0 END");
        }
        if (!$columnExists('email_data_pool', 'status')) {
            $this->addSql("ALTER TABLE email_data_pool ADD status VARCHAR(30) NOT NULL DEFAULT 'active'");
            $this->addSql("UPDATE email_data_pool SET status = CASE WHEN is_active = 1 THEN 'active' ELSE 'passive' END");
        }

        if (!$indexExists('email_data_pool', 'idx_email_pool_items_normalized_email')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_normalized_email ON email_data_pool (normalized_email)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_normalized_email')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_pool_normalized_email ON email_data_pool (pool_list_id, normalized_email)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_status')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_pool_status ON email_data_pool (pool_list_id, status)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_is_duplicate')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_pool_is_duplicate ON email_data_pool (pool_list_id, is_duplicate)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_pool_is_gmail')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_pool_is_gmail ON email_data_pool (pool_list_id, is_gmail)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_domain')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_domain ON email_data_pool (domain)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_status')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_status ON email_data_pool (status)');
        }
        if (!$indexExists('email_data_pool', 'idx_email_pool_items_is_duplicate')) {
            $this->addSql('CREATE INDEX idx_email_pool_items_is_duplicate ON email_data_pool (is_duplicate)');
        }
    }

    public function down(Schema $schema): void
    {
        // Bu migration için geri alma üretim ortamında önerilmez.
    }
}
