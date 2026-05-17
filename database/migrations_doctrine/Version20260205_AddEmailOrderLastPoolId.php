<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260205_AddEmailOrderLastPoolId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_orders tablosuna last_pool_id kolonu ekler (cursor-based pagination için - 2.5M kayıt optimizasyonu)';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        // Kolon kontrolü
        $columnExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND COLUMN_NAME = 'last_pool_id'",
            [$dbName]
        );

        if ((int) $columnExists === 0) {
            $this->addSql("ALTER TABLE email_orders 
                ADD COLUMN last_pool_id INT NULL DEFAULT NULL 
                COMMENT 'Son çekilen email_data_pool kaydının ID''si (cursor-based pagination için)'");
        }

        // Index kontrolü
        $indexExists = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_data_pool' AND INDEX_NAME = 'idx_id_active'",
            [$dbName]
        );

        if ((int) $indexExists === 0) {
            $this->addSql("ALTER TABLE email_data_pool 
                ADD INDEX idx_id_active (id, is_active)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_orders DROP COLUMN last_pool_id');
        $this->addSql('ALTER TABLE email_data_pool DROP INDEX idx_id_active');
    }
}
