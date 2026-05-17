<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * order_numbers: sağlayıcı MessageId (rapor backfill / snapshot için).
 * Entity OrderNumber::$providerMessageId → provider_message_id
 */
final class Version20260330_OrderNumbersProviderMessageId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'order_numbers.provider_message_id ekle (idempotent)';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = $conn->getDatabase();

        try {
            $exists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'order_numbers', 'provider_message_id']
            ) > 0;
        } catch (\Throwable) {
            return;
        }

        if ($exists) {
            return;
        }

        $this->addSql('ALTER TABLE order_numbers ADD provider_message_id VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = $conn->getDatabase();

        try {
            $exists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'order_numbers', 'provider_message_id']
            ) > 0;
        } catch (\Throwable) {
            return;
        }

        if (!$exists) {
            return;
        }

        $this->addSql('ALTER TABLE order_numbers DROP COLUMN provider_message_id');
    }
}
