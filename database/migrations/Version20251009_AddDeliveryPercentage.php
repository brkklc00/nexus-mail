<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add delivery_percentage column to orders table
 * This allows admin to control fake/real SMS ratio without user knowing
 */
final class Version20251009_AddDeliveryPercentage extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add delivery_percentage column to orders table (default: 100)';
    }

    public function up(Schema $schema): void
    {
        // delivery_percentage kolonu ekle (kontrol ederek)
        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        
        try {
            $columnCheck = $connection->fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 'orders' 
                AND COLUMN_NAME = 'delivery_percentage'",
                [$dbName]
            );
            
            if ((int)$columnCheck === 0) {
                // Kolon yok, ekle (AFTER message_text kolonu yoksa sona ekle)
                // Önce message_text kolonunu kontrol et
                $messageTextCheck = $connection->fetchOne(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                    WHERE TABLE_SCHEMA = ? 
                    AND TABLE_NAME = 'orders' 
                    AND COLUMN_NAME = 'message_text'",
                    [$dbName]
                );
                
                if ((int)$messageTextCheck > 0) {
                    // message_text varsa ondan sonra ekle
                    $this->addSql('ALTER TABLE orders ADD COLUMN delivery_percentage INT NOT NULL DEFAULT 100 AFTER message_text');
                } else {
                    // message_text yoksa sona ekle
                    $this->addSql('ALTER TABLE orders ADD COLUMN delivery_percentage INT NOT NULL DEFAULT 100');
                }
            }
            // Kolon varsa hiçbir şey yapma
        } catch (\Exception $e) {
            // Hata olursa, yine de deneyeceğiz (AFTER olmadan)
            $this->addSql('ALTER TABLE orders ADD COLUMN delivery_percentage INT NOT NULL DEFAULT 100');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP COLUMN delivery_percentage');
    }
}

