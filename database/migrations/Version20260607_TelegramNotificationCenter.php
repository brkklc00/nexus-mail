<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260607_TelegramNotificationCenter extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Telegram bildirim merkezi için events/templates/logs yapısını ekler';
    }

    public function up(Schema $schema): void
    {
        $db = $this->connection;
        $dbName = (string) ($db->getDatabase() ?: $db->fetchOne('SELECT DATABASE()'));

        $tableExists = static function (string $table) use ($db, $dbName): bool {
            return (int) $db->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$dbName, $table]
            ) > 0;
        };
        $columnExists = static function (string $table, string $column) use ($db, $dbName): bool {
            return (int) $db->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $column]
            ) > 0;
        };
        $indexExists = static function (string $table, string $index) use ($db, $dbName): bool {
            return (int) $db->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$dbName, $table, $index]
            ) > 0;
        };

        if ($tableExists('telegram_notification_settings') && !$columnExists('telegram_notification_settings', 'parse_mode')) {
            $this->addSql("ALTER TABLE telegram_notification_settings ADD parse_mode VARCHAR(20) NOT NULL DEFAULT 'HTML' AFTER chat_id");
        }

        if (!$tableExists('telegram_notification_events')) {
            $this->addSql(
                "CREATE TABLE telegram_notification_events (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    event_key VARCHAR(100) NOT NULL,
                    label VARCHAR(190) NOT NULL,
                    description TEXT DEFAULT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 0,
                    template_key VARCHAR(120) DEFAULT NULL,
                    throttle_minutes INT NOT NULL DEFAULT 0,
                    only_on_error TINYINT(1) NOT NULL DEFAULT 0,
                    last_sent_at DATETIME DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY(id),
                    UNIQUE KEY uniq_tn_events_event_key (event_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if (!$tableExists('telegram_notification_templates')) {
            $this->addSql(
                "CREATE TABLE telegram_notification_templates (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    template_key VARCHAR(120) NOT NULL,
                    event_key VARCHAR(100) DEFAULT NULL,
                    title VARCHAR(190) NOT NULL,
                    body LONGTEXT NOT NULL,
                    parse_mode VARCHAR(20) NOT NULL DEFAULT 'HTML',
                    enabled TINYINT(1) NOT NULL DEFAULT 1,
                    is_default TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY(id),
                    UNIQUE KEY uniq_tn_templates_template_key (template_key)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if ($tableExists('telegram_notification_logs')) {
            if (!$columnExists('telegram_notification_logs', 'event_key')) {
                $this->addSql("ALTER TABLE telegram_notification_logs ADD event_key VARCHAR(100) DEFAULT NULL AFTER id");
            }
            if (!$columnExists('telegram_notification_logs', 'template_key')) {
                $this->addSql("ALTER TABLE telegram_notification_logs ADD template_key VARCHAR(120) DEFAULT NULL AFTER status");
            }
            if (!$columnExists('telegram_notification_logs', 'chat_id')) {
                $this->addSql("ALTER TABLE telegram_notification_logs ADD chat_id VARCHAR(120) DEFAULT NULL AFTER template_key");
            }
            if (!$columnExists('telegram_notification_logs', 'payload_json')) {
                $this->addSql("ALTER TABLE telegram_notification_logs ADD payload_json LONGTEXT DEFAULT NULL AFTER chat_id");
            }
            if (!$columnExists('telegram_notification_logs', 'rendered_message')) {
                $this->addSql("ALTER TABLE telegram_notification_logs ADD rendered_message LONGTEXT DEFAULT NULL AFTER payload_json");
            }
            if (!$columnExists('telegram_notification_logs', 'status_code')) {
                $this->addSql("ALTER TABLE telegram_notification_logs ADD status_code TINYINT(1) NOT NULL DEFAULT 0 AFTER rendered_message");
            }
            if (!$indexExists('telegram_notification_logs', 'idx_tn_logs_event_created')) {
                $this->addSql("CREATE INDEX idx_tn_logs_event_created ON telegram_notification_logs (event_key, created_at)");
            }
            if (!$indexExists('telegram_notification_logs', 'idx_tn_logs_order_status')) {
                $this->addSql("CREATE INDEX idx_tn_logs_order_status ON telegram_notification_logs (order_id, status)");
            }
        }
    }

    public function down(Schema $schema): void
    {
        // no-op
    }
}

