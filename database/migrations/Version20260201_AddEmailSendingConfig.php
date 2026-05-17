<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260201_AddEmailSendingConfig extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email gönderim planı config tablosu - tüm SMTP\'ler için tek limit ayarı';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;

        $connection->executeStatement("
            CREATE TABLE IF NOT EXISTS email_sending_config (
                id INT PRIMARY KEY DEFAULT 1,
                daily_limit INT NOT NULL DEFAULT 20000,
                rate_per_second DECIMAL(10,2) NOT NULL DEFAULT 1.00,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // Varsayılan plan: 20K/gün, 1 email/saniye
        $connection->executeStatement("
            INSERT IGNORE INTO email_sending_config (id, daily_limit, rate_per_second) 
            VALUES (1, 20000, 1.00)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement("DROP TABLE IF EXISTS email_sending_config");
    }
}
