<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260101_AddHourlyMinuteLimitsToEmailSmtp extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email SMTP hesaplarına saatlik ve dakikalık limit alanları eklendi';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        
        try {
            // Saatlik limit alanları
            $connection->executeStatement("
                ALTER TABLE email_smtp_accounts 
                ADD COLUMN hourly_limit INT DEFAULT 100 AFTER daily_sent,
                ADD COLUMN hourly_sent INT DEFAULT 0 AFTER hourly_limit,
                ADD COLUMN last_reset_hour DATETIME NULL AFTER hourly_sent
            ");
            
            // Dakikalık limit alanları
            $connection->executeStatement("
                ALTER TABLE email_smtp_accounts 
                ADD COLUMN minute_limit INT DEFAULT 10 AFTER hourly_sent,
                ADD COLUMN minute_sent INT DEFAULT 0 AFTER minute_limit,
                ADD COLUMN last_reset_minute DATETIME NULL AFTER minute_sent
            ");
            
        } catch (\Exception $e) {
            // Kolon zaten varsa hata verme
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }

    public function down(Schema $schema): void
    {
        $connection = $this->connection;
        
        try {
            $connection->executeStatement("
                ALTER TABLE email_smtp_accounts 
                DROP COLUMN hourly_limit,
                DROP COLUMN hourly_sent,
                DROP COLUMN last_reset_hour,
                DROP COLUMN minute_limit,
                DROP COLUMN minute_sent,
                DROP COLUMN last_reset_minute
            ");
        } catch (\Exception $e) {
            // Kolon yoksa hata verme
        }
    }
}

