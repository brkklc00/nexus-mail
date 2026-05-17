<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260201_AddEmailApprovalSystem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_templates tablosuna is_approved kolonu ekler';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $result = $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_templates' AND COLUMN_NAME = 'is_approved'",
            [$dbName]
        );

        if ((int) $result === 0) {
            $this->addSql('ALTER TABLE email_templates ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_templates DROP COLUMN is_approved');
    }
}
