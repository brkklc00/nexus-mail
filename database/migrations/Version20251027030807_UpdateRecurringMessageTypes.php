<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration: Telegram recurring message types güncelleme
 * - interval: Her X saat Y dakikada bir
 * - every_hour_at: Her saat X dakikada
 */
final class Version20251027030807_UpdateRecurringMessageTypes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Updates telegram_recurring_messages.recurrence_type ENUM to support interval and every_hour_at types';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        
        // Tablo var mı kontrol et
        $tableExists = function($tableName) use ($connection, $dbName) {
            try {
                $result = $connection->fetchOne(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?",
                    [$dbName, $tableName]
                );
                return (int)$result > 0;
            } catch (\Exception $e) {
                return false;
            }
        };
        
        // ENUM tipini güncelle (sadece tablo varsa)
        if ($tableExists('telegram_recurring_messages')) {
            $this->addSql("
                ALTER TABLE telegram_recurring_messages 
                MODIFY COLUMN recurrence_type ENUM('hourly', 'interval', 'every_hour_at', 'daily', 'weekly') NOT NULL
            ");
        }
    }

    public function down(Schema $schema): void
    {
        // Geri al - eski ENUM
        $this->addSql("
            ALTER TABLE telegram_recurring_messages 
            MODIFY COLUMN recurrence_type ENUM('hourly', 'daily', 'weekly') NOT NULL
        ");
    }
}


