<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606_AddTelegramNotificationSettings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Telegram bildirim ayarlari ve gonderim log tablolari';
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
        $indexExists = static function (string $table, string $index) use ($conn, $dbName): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$dbName, $table, $index]
            ) > 0;
        };

        if (!$tableExists('telegram_notification_settings')) {
            $this->addSql(
                "CREATE TABLE telegram_notification_settings (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 0,
                    bot_token TEXT DEFAULT NULL,
                    chat_id VARCHAR(100) DEFAULT NULL,
                    events JSON DEFAULT NULL,
                    templates JSON DEFAULT NULL,
                    last_test_at DATETIME DEFAULT NULL,
                    last_error TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$tableExists('telegram_notification_logs')) {
            $this->addSql(
                "CREATE TABLE telegram_notification_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    event_type VARCHAR(100) NOT NULL,
                    order_id BIGINT UNSIGNED DEFAULT NULL,
                    status VARCHAR(50) DEFAULT NULL,
                    sent TINYINT(1) NOT NULL DEFAULT 0,
                    error_message TEXT DEFAULT NULL,
                    telegram_message_id VARCHAR(100) DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY(id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if ($tableExists('telegram_notification_logs') && !$indexExists('telegram_notification_logs', 'idx_event_order')) {
            $this->addSql('CREATE INDEX idx_event_order ON telegram_notification_logs (event_type, order_id, status)');
        }
        if ($tableExists('telegram_notification_logs') && !$indexExists('telegram_notification_logs', 'idx_created_at')) {
            $this->addSql('CREATE INDEX idx_created_at ON telegram_notification_logs (created_at)');
        }
    }

    public function down(Schema $schema): void
    {
        // no-op: production safety
    }
}

