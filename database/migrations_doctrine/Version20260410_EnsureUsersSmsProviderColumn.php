<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Bazı ortamlarda Version20260409 kaydı oluşup ALTER uygulanmamış olabiliyor;
 * sütun yoksa ekler (idempotent).
 */
final class Version20260410_EnsureUsersSmsProviderColumn extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.sms_provider sütunu yoksa ekle (senkron / kısmi deploy düzeltmesi)';
    }

    public function up(Schema $schema): void
    {
        $count = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME = 'sms_provider'"
        );

        if ($count === 0) {
            $this->addSql('ALTER TABLE users ADD sms_provider VARCHAR(32) DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        // Bilinçli no-op: kaldırma Version20260409 down ile yapılır
    }
}
