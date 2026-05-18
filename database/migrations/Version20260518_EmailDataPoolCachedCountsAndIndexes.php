<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518_EmailDataPoolCachedCountsAndIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_data_pool listelerinde cache count alanlari ve performans indexleri';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) $conn->getDatabase();

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

        $columnExists = static function (string $table, string $column) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$dbName, $table, $column]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        $indexExists = static function (string $table, string $index) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                    [$dbName, $table, $index]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        if ($tableExists('email_data_pool_lists')) {
            if (!$columnExists('email_data_pool_lists', 'total_count')) {
                $this->addSql('ALTER TABLE email_data_pool_lists ADD total_count INT NOT NULL DEFAULT 0');
            }
            if (!$columnExists('email_data_pool_lists', 'active_count')) {
                $this->addSql('ALTER TABLE email_data_pool_lists ADD active_count INT NOT NULL DEFAULT 0');
            }
            if (!$columnExists('email_data_pool_lists', 'passive_count')) {
                $this->addSql('ALTER TABLE email_data_pool_lists ADD passive_count INT NOT NULL DEFAULT 0');
            }
            if (!$columnExists('email_data_pool_lists', 'updated_count_at')) {
                $this->addSql('ALTER TABLE email_data_pool_lists ADD updated_count_at DATETIME DEFAULT NULL');
            }
        }

        if ($tableExists('email_data_pool')) {
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_id')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_id ON email_data_pool (pool_list_id)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_status')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_status ON email_data_pool (pool_list_id, is_active)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_list_email')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_list_email ON email_data_pool (pool_list_id, email)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_email')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_email ON email_data_pool (email)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_created_at')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_created_at ON email_data_pool (created_at)');
            }
            if (!$indexExists('email_data_pool', 'idx_email_data_pool_updated_at')) {
                $this->addSql('CREATE INDEX idx_email_data_pool_updated_at ON email_data_pool (updated_at)');
            }
        }

        if ($tableExists('email_data_pool_lists') && $tableExists('email_data_pool')) {
            $this->addSql('UPDATE email_data_pool_lists l
                LEFT JOIN (
                    SELECT
                        pool_list_id,
                        COUNT(*) AS total_count,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
                    FROM email_data_pool
                    GROUP BY pool_list_id
                ) x ON x.pool_list_id = l.id
                SET
                    l.total_count = COALESCE(x.total_count, 0),
                    l.active_count = COALESCE(x.active_count, 0),
                    l.passive_count = COALESCE(x.total_count, 0) - COALESCE(x.active_count, 0),
                    l.updated_count_at = NOW()');
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

        if ($tableExists('email_data_pool')) {
            if ($indexExists('email_data_pool', 'idx_email_data_pool_updated_at')) {
                $this->addSql('DROP INDEX idx_email_data_pool_updated_at ON email_data_pool');
            }
            if ($indexExists('email_data_pool', 'idx_email_data_pool_created_at')) {
                $this->addSql('DROP INDEX idx_email_data_pool_created_at ON email_data_pool');
            }
            if ($indexExists('email_data_pool', 'idx_email_data_pool_list_email')) {
                $this->addSql('DROP INDEX idx_email_data_pool_list_email ON email_data_pool');
            }
            if ($indexExists('email_data_pool', 'idx_email_data_pool_list_status')) {
                $this->addSql('DROP INDEX idx_email_data_pool_list_status ON email_data_pool');
            }
            if ($indexExists('email_data_pool', 'idx_email_data_pool_list_id')) {
                $this->addSql('DROP INDEX idx_email_data_pool_list_id ON email_data_pool');
            }
        }

        if ($tableExists('email_data_pool_lists')) {
            if ($columnExists('email_data_pool_lists', 'updated_count_at')) {
                $this->addSql('ALTER TABLE email_data_pool_lists DROP COLUMN updated_count_at');
            }
            if ($columnExists('email_data_pool_lists', 'passive_count')) {
                $this->addSql('ALTER TABLE email_data_pool_lists DROP COLUMN passive_count');
            }
            if ($columnExists('email_data_pool_lists', 'active_count')) {
                $this->addSql('ALTER TABLE email_data_pool_lists DROP COLUMN active_count');
            }
            if ($columnExists('email_data_pool_lists', 'total_count')) {
                $this->addSql('ALTER TABLE email_data_pool_lists DROP COLUMN total_count');
            }
        }
    }
}

