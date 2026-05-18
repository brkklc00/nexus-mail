<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518_AddNotificationsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bildirim sistemi için notifications tablosunu ekler';
    }

    public function up(Schema $schema): void
    {
        $dbName = (string) $this->connection->getDatabase();

        $tableExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications'",
            [$dbName]
        ) > 0;

        if ($tableExists) {
            return;
        }

        $this->addSql('CREATE TABLE notifications (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT DEFAULT NULL,
            title VARCHAR(200) NOT NULL,
            message LONGTEXT NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT \'info\',
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            read_at DATETIME DEFAULT NULL,
            INDEX idx_notifications_user (user_id),
            INDEX idx_notifications_read (is_read),
            INDEX idx_notifications_created (created_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE notifications ADD CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $dbName = (string) $this->connection->getDatabase();

        $tableExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications'",
            [$dbName]
        ) > 0;

        if (!$tableExists) {
            return;
        }

        $fkExists = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notifications' AND CONSTRAINT_NAME = 'fk_notifications_user'",
            [$dbName]
        ) > 0;
        if ($fkExists) {
            $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY fk_notifications_user');
        }

        $this->addSql('DROP TABLE notifications');
    }
}
