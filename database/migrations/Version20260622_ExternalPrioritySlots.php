<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260622_ExternalPrioritySlots extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'External priority slots: hub-nexus tarafından mail sunucularına gönderilen öncelik slotları';
    }

    public function up(Schema $schema): void
    {
        $dbName = (string) $this->connection->getDatabase();

        $tableExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, 'external_priority_slots']
        ) > 0;

        if (!$tableExists) {
            $this->addSql("
                CREATE TABLE external_priority_slots (
                    id              INT AUTO_INCREMENT PRIMARY KEY,
                    hub_api_url     VARCHAR(500) NOT NULL,
                    hub_api_token   VARCHAR(500) NOT NULL,
                    hub_campaign_id INT NOT NULL,
                    hub_slot_id     INT NOT NULL,
                    worker_slot     TINYINT NOT NULL,
                    email_id_min    BIGINT NULL,
                    email_id_max    BIGINT NULL,
                    total_emails    INT NOT NULL DEFAULT 0,
                    status          ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
                    worker_id       VARCHAR(255) NULL,
                    campaign_meta   LONGTEXT NULL COMMENT 'JSON: subject, body, from_name, from_email, smtp_rotation_limit...',
                    sent_count      INT NOT NULL DEFAULT 0,
                    failed_count    INT NOT NULL DEFAULT 0,
                    claimed_at      DATETIME NULL,
                    completed_at    DATETIME NULL,
                    created_at      DATETIME NOT NULL,
                    updated_at      DATETIME NOT NULL,
                    INDEX idx_worker_status (worker_slot, status),
                    INDEX idx_hub_slot      (hub_slot_id),
                    INDEX idx_status        (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS external_priority_slots');
    }
}
