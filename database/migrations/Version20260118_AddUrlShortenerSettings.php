<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * URL Shortener ayarları için kullanıcı tablosuna kolonlar ekler
 */
final class Version20260118_AddUrlShortenerSettings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add URL Shortener settings columns to users table';
    }

    public function up(Schema $schema): void
    {
        // Prod’da kolonlar manuel / kısmi migrate edilmiş olabiliyor — idempotent
        $specs = [
            ['url_shortener_enabled', 'ALTER TABLE users ADD COLUMN url_shortener_enabled BOOLEAN DEFAULT 1 NOT NULL'],
            ['url_shortener_max_urls', 'ALTER TABLE users ADD COLUMN url_shortener_max_urls INT NULL'],
            ['url_shortener_allowed_domains', 'ALTER TABLE users ADD COLUMN url_shortener_allowed_domains JSON NULL'],
        ];

        foreach ($specs as [$column, $sql]) {
            $n = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['users', $column]
            );
            if ($n === 0) {
                $this->addSql($sql);
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['url_shortener_allowed_domains', 'url_shortener_max_urls', 'url_shortener_enabled'] as $column) {
            $n = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['users', $column]
            );
            if ($n > 0) {
                $this->addSql(sprintf('ALTER TABLE users DROP COLUMN `%s`', $column));
            }
        }
    }
}
