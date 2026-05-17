<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260203_AddEmailOrderSourceType extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_orders tablosuna source_type kolonu ekler (pool: worker havuzdan çeker)';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $result = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND COLUMN_NAME = 'source_type'",
            [$dbName]
        );

        if ((int) $result === 0) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN source_type VARCHAR(20) NULL DEFAULT NULL COMMENT 'pool|phonebook|manual - pool: worker mail havuzundan çeker'");
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_orders DROP COLUMN source_type');
    }
}
