<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260224_AddEmailWorkerSafety extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email worker guvenligi icin claim lock alanlari, pool cursor ve smtp usage event tablosu ekler';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $hasColumn = static function (string $table, string $column) use ($connection, $dbName): bool {
            $count = $connection->fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [$dbName, $table, $column]
            );

            return (int) $count > 0;
        };

        if (!$hasColumn('email_orders', 'locked_at')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN locked_at DATETIME NULL DEFAULT NULL COMMENT 'Worker claim lock zamani'");
        }

        if (!$hasColumn('email_orders', 'locked_by')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN locked_by VARCHAR(120) NULL DEFAULT NULL COMMENT 'Worker claim lock sahibi'");
        }

        if (!$hasColumn('email_orders', 'attempt_count')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN attempt_count INT NOT NULL DEFAULT 0 COMMENT 'Worker claim deneme sayisi'");
        }

        if (!$hasColumn('email_orders', 'last_pool_id')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN last_pool_id INT NULL DEFAULT NULL COMMENT 'Pool cursor son id'");
        }

        $lockIdx = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND INDEX_NAME = 'idx_email_orders_status_lock'",
            [$dbName]
        );
        if ((int) $lockIdx === 0) {
            $this->addSql('CREATE INDEX idx_email_orders_status_lock ON email_orders (status, locked_at)');
        }

        $poolIdx = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_data_pool' AND INDEX_NAME = 'idx_email_data_pool_active_id'",
            [$dbName]
        );
        if ((int) $poolIdx === 0) {
            $this->addSql('CREATE INDEX idx_email_data_pool_active_id ON email_data_pool (is_active, id)');
        }

        $usageTableExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_smtp_usage_events'",
            [$dbName]
        ) > 0;

        if (!$usageTableExists) {
            $this->addSql('CREATE TABLE email_smtp_usage_events (
                id INT AUTO_INCREMENT NOT NULL,
                smtp_id INT NOT NULL,
                event_key VARCHAR(191) NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                UNIQUE INDEX uniq_email_smtp_usage_event_key (event_key),
                INDEX idx_email_smtp_usage_smtp_id (smtp_id),
                CONSTRAINT fk_email_smtp_usage_event_smtp FOREIGN KEY (smtp_id) REFERENCES email_smtp_accounts (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS email_smtp_usage_events');
        $this->addSql('DROP INDEX idx_email_data_pool_active_id ON email_data_pool');
        $this->addSql('DROP INDEX idx_email_orders_status_lock ON email_orders');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN last_pool_id');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN attempt_count');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN locked_by');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN locked_at');
    }
}

