<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260330_EmailSendingCapsAndPoolLists extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_sending_config ek tavan kolonları; email_data_pool_lists ve havuz/sipariş pool_list_id (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = $conn->getDatabase();

        $columnExists = static function (string $table, string $col) use ($conn, $dbName): bool {
            try {
                $n = (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$dbName, $table, $col]
                );

                return $n > 0;
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

        $fkExists = static function (string $table, string $fkName) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
                    [$dbName, $table, $fkName, 'FOREIGN KEY']
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

        $columnNullable = static function (string $table, string $col) use ($conn, $dbName): bool {
            try {
                $v = $conn->fetchOne(
                    'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$dbName, $table, $col]
                );

                return $v === 'YES';
            } catch (\Throwable) {
                return true;
            }
        };

        if (!$tableExists('email_data_pool_lists')) {
            $this->addSql('CREATE TABLE email_data_pool_lists (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        $this->addSql("INSERT INTO email_data_pool_lists (id, name, sort_order, created_at)
            SELECT 1, 'Liste 1', 0, NOW() FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM email_data_pool_lists WHERE id = 1 LIMIT 1)");

        if ($columnExists('email_sending_config', 'daily_limit')) {
            if (!$columnExists('email_sending_config', 'alibaba_rate_cap')) {
                $this->addSql('ALTER TABLE email_sending_config ADD alibaba_rate_cap DOUBLE PRECISION DEFAULT NULL');
            }
            if (!$columnExists('email_sending_config', 'max_rate_per_second')) {
                $this->addSql('ALTER TABLE email_sending_config ADD max_rate_per_second DOUBLE PRECISION DEFAULT NULL');
            }
        }

        if ($tableExists('email_data_pool') && !$columnExists('email_data_pool', 'pool_list_id')) {
            $this->addSql('ALTER TABLE email_data_pool ADD pool_list_id INT DEFAULT NULL');
        }
        if ($tableExists('email_data_pool') && $columnExists('email_data_pool', 'pool_list_id')) {
            $this->addSql('UPDATE email_data_pool SET pool_list_id = 1 WHERE pool_list_id IS NULL');
            if ($columnNullable('email_data_pool', 'pool_list_id')) {
                $this->addSql('ALTER TABLE email_data_pool MODIFY pool_list_id INT NOT NULL');
            }
            if (!$fkExists('email_data_pool', 'FK_edp_pool_list')) {
                $this->addSql('ALTER TABLE email_data_pool ADD CONSTRAINT FK_edp_pool_list FOREIGN KEY (pool_list_id) REFERENCES email_data_pool_lists (id)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_active_id')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_active_id ON email_data_pool (pool_list_id, is_active, id)');
            }
        }

        if ($tableExists('email_orders') && !$columnExists('email_orders', 'pool_list_id')) {
            $this->addSql('ALTER TABLE email_orders ADD pool_list_id INT DEFAULT NULL');
        }
        if ($tableExists('email_orders') && $columnExists('email_orders', 'pool_list_id') && !$fkExists('email_orders', 'FK_eo_pool_list')) {
            $this->addSql('ALTER TABLE email_orders ADD CONSTRAINT FK_eo_pool_list FOREIGN KEY (pool_list_id) REFERENCES email_data_pool_lists (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_orders DROP FOREIGN KEY FK_eo_pool_list');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN pool_list_id');

        $this->addSql('DROP INDEX idx_email_data_pool_list_active_id ON email_data_pool');
        $this->addSql('ALTER TABLE email_data_pool DROP FOREIGN KEY FK_edp_pool_list');
        $this->addSql('ALTER TABLE email_data_pool DROP COLUMN pool_list_id');

        $this->addSql('DROP TABLE email_data_pool_lists');

        $this->addSql('ALTER TABLE email_sending_config DROP COLUMN alibaba_rate_cap, DROP COLUMN max_rate_per_second');
    }
}
