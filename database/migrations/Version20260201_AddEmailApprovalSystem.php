<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260201_AddEmailApprovalSystem extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email sipariş ve şablon onay sistemi - pending_approval status, is_approved column';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        // email_templates tablosuna is_approved ekle (mevcut şablonlar onaylı kabul edilir)
        try {
            $conn->executeStatement("
                ALTER TABLE email_templates 
                ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 1
            ");
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
    }

    public function down(Schema $schema): void
    {
        $conn = $this->connection;
        try {
            $conn->executeStatement("ALTER TABLE email_templates DROP COLUMN is_approved");
        } catch (\Exception $e) {
            // Column might not exist
        }
    }
}
