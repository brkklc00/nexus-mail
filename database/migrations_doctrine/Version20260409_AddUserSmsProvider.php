<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409_AddUserSmsProvider extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Kullanıcı bazlı SMS sağlayıcı seçimi (null = .env SMS_PROVIDER, boşsa uipapp)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD sms_provider VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN sms_provider');
    }
}
