<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add refund_enabled column to users table
 * Allows admin to control whether failed SMS credits should be refunded to user
 */
final class Version20251009_AddRefundEnabled extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add refund_enabled column to users table (default: true)';
    }

    public function up(Schema $schema): void
    {
        // refundEnabled kolonu ekle (kontrol ederek)
        $connection = $this->connection;
        $dbName = $connection->getDatabase();
        
        try {
            $columnCheck = $connection->fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_NAME = 'users' 
                AND COLUMN_NAME = 'refundEnabled'",
                [$dbName]
            );
            
            if ((int)$columnCheck === 0) {
                // Kolon yok, ekle
                $this->addSql('ALTER TABLE users ADD COLUMN refundEnabled TINYINT(1) NOT NULL DEFAULT 1 AFTER smsDeliveryPercentage');
            }
            // Kolon varsa hiçbir şey yapma
        } catch (\Exception $e) {
            // Hata olursa, yine de deneyeceğiz
            $this->addSql('ALTER TABLE users ADD COLUMN refundEnabled TINYINT(1) NOT NULL DEFAULT 1 AFTER smsDeliveryPercentage');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN refundEnabled');
    }
}

