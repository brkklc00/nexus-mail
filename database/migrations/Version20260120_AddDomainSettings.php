<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Domain Settings Migration
 * Veritabanı tabanlı domain yönetimi
 */
final class Version20260120_AddDomainSettings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Domain ayarları için veritabanı tablosu oluşturur';
    }

    public function up(Schema $schema): void
    {
        // Dump / önceki kurulumda tablo zaten varsa CREATE + seed atlanır (TableAlreadyExists önlenir)
        $dbName = $this->connection->getDatabase();
        $exists = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND LOWER(TABLE_NAME) = ?',
            [$dbName, 'domain_settings']
        ) > 0;
        if ($exists) {
            return;
        }

        // domain_settings tablosu
        $table = $schema->createTable('domain_settings');
        $table->addColumn('id', 'integer', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('domain', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('siteTitle', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('siteLogo', 'string', ['length' => 500, 'notnull' => false]);
        $table->addColumn('siteFavicon', 'string', ['length' => 500, 'notnull' => false]);
        $table->addColumn('siteDefaultAvatar', 'string', ['length' => 500, 'notnull' => false]);
        $table->addColumn('siteDescription', 'text', ['notnull' => false]);
        $table->addColumn('isActive', 'boolean', ['default' => 1, 'notnull' => true]);
        $table->addColumn('createdAt', 'datetime', ['notnull' => true]);
        $table->addColumn('updatedAt', 'datetime', ['notnull' => true]);
        
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['domain'], 'uniq_domain');
        $table->addIndex(['isActive'], 'idx_is_active');
        $table->addIndex(['domain'], 'idx_domain');

        // Mevcut domain'leri import et
        $this->addSql("
            INSERT INTO domain_settings (domain, siteTitle, siteLogo, siteFavicon, siteDefaultAvatar, siteDescription, isActive, createdAt, updatedAt) VALUES
            ('hub-nexus.com', 'Hub Nexus', 'https://i.ibb.co/gLk7x7JD/nexus-logo-1.png', 'https://i.ibb.co/nN2TdWjf/default-avatar.png', 'https://i.ibb.co/nN2TdWjf/default-avatar.png', 'Hub Nexus - SMS, Email, WhatsApp & Telegram Yönetim Platformu', 1, NOW(), NOW()),
            ('numbpanel.com', 'Numb | Digital Marketing', 'https://i.ibb.co/F9KK2F1/nexus-logo-1.png', 'https://i.ibb.co/9m9QTdcx/numb.png', 'https://i.ibb.co/9m9QTdcx/numb.png', 'Numb | Digital Marketing - SMS, Email, WhatsApp & Telegram Yönetim Platformu', 1, NOW(), NOW()),
            ('prime-medya.com', 'Prime Medya', 'https://i.ibb.co/gLk7x7JD/nexus-logo-1.png', 'https://i.ibb.co/nN2TdWjf/default-avatar.png', 'https://i.ibb.co/nN2TdWjf/default-avatar.png', 'Prime Medya - Dijital Pazarlama Platformu', 1, NOW(), NOW()),
            ('x-solutions.app', 'X Solutions', 'https://i.ibb.co/gLk7x7JD/nexus-logo-1.png', 'https://i.ibb.co/nN2TdWjf/default-avatar.png', 'https://i.ibb.co/nN2TdWjf/default-avatar.png', 'X Solutions - Digital Marketing Platform', 1, NOW(), NOW())
        ");
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('domain_settings');
    }
}
