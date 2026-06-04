<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260121_AddWasenderWebhookSecret extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wasender_webhook_secret column to whatsapp_accounts table';
    }

    public function up(Schema $schema): void
    {
        $dbName = $this->connection->getDatabase();

        $tableExists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$dbName, 'whatsapp_accounts']
        ) > 0;
        if (!$tableExists) {
            return;
        }

        $n = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$dbName, 'whatsapp_accounts', 'wasender_webhook_secret']
        );
        if ($n > 0) {
            return;
        }

        $this->addSql('ALTER TABLE whatsapp_accounts ADD COLUMN wasender_webhook_secret VARCHAR(255) NULL AFTER wasender_api_key');
    }

    public function down(Schema $schema): void
    {
        $dbName = $this->connection->getDatabase();
        $n = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$dbName, 'whatsapp_accounts', 'wasender_webhook_secret']
        );
        if ($n === 0) {
            return;
        }

        $this->addSql('ALTER TABLE whatsapp_accounts DROP COLUMN wasender_webhook_secret');
    }
}
