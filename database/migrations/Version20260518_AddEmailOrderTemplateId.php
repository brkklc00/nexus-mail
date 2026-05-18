<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260518_AddEmailOrderTemplateId extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_orders tablosuna template_id kolonu ekler ve email_templates ile ilişkilendirir';
    }

    public function up(Schema $schema): void
    {
        $database = (string) $this->connection->getDatabase();

        $hasTemplateId = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND COLUMN_NAME = 'template_id'",
            [$database]
        ) > 0;

        if (!$hasTemplateId) {
            $this->addSql('ALTER TABLE email_orders ADD template_id INT DEFAULT NULL');
        }

        $hasTemplateIdx = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND INDEX_NAME = 'idx_email_orders_template_id'",
            [$database]
        ) > 0;

        if (!$hasTemplateIdx) {
            $this->addSql('CREATE INDEX idx_email_orders_template_id ON email_orders (template_id)');
        }

        $hasFk = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND CONSTRAINT_NAME = 'fk_email_orders_template'",
            [$database]
        ) > 0;

        if (!$hasFk) {
            $this->addSql('ALTER TABLE email_orders ADD CONSTRAINT fk_email_orders_template FOREIGN KEY (template_id) REFERENCES email_templates (id) ON DELETE SET NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $database = (string) $this->connection->getDatabase();

        $hasFk = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND CONSTRAINT_NAME = 'fk_email_orders_template'",
            [$database]
        ) > 0;
        if ($hasFk) {
            $this->addSql('ALTER TABLE email_orders DROP FOREIGN KEY fk_email_orders_template');
        }

        $hasTemplateIdx = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND INDEX_NAME = 'idx_email_orders_template_id'",
            [$database]
        ) > 0;
        if ($hasTemplateIdx) {
            $this->addSql('DROP INDEX idx_email_orders_template_id ON email_orders');
        }

        $hasTemplateId = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND COLUMN_NAME = 'template_id'",
            [$database]
        ) > 0;
        if ($hasTemplateId) {
            $this->addSql('ALTER TABLE email_orders DROP COLUMN template_id');
        }
    }
}
