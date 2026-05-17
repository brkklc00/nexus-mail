<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * EmailOrderEmail entity: claim / hata alanları (API updateEmailCampaignEmails DQL).
 * Prod’da doctrine_migration_versions boşken kısmi şema olabiliyor — idempotent.
 */
final class Version20260512_EmailOrderEmailsWorkerClaimColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_order_emails: locked_at, locked_by, attempt_count, last_error_*, failed_at + index';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        $hasCol = static function ($c, string $table, string $col): bool {
            return (int) $c->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $col]
            ) > 0;
        };

        $hasIdx = static function ($c, string $table, string $idx): bool {
            return (int) $c->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $idx]
            ) > 0;
        };

        if (!(int) $conn->fetchOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            ['email_order_emails']
        )) {
            return;
        }

        if (!$hasCol($conn, 'email_order_emails', 'locked_at')) {
            $this->addSql('ALTER TABLE email_order_emails ADD COLUMN locked_at DATETIME NULL DEFAULT NULL');
        }
        if (!$hasCol($conn, 'email_order_emails', 'locked_by')) {
            $this->addSql('ALTER TABLE email_order_emails ADD COLUMN locked_by VARCHAR(120) NULL DEFAULT NULL');
        }
        if (!$hasCol($conn, 'email_order_emails', 'attempt_count')) {
            $this->addSql('ALTER TABLE email_order_emails ADD COLUMN attempt_count INT NOT NULL DEFAULT 0');
        }
        if (!$hasCol($conn, 'email_order_emails', 'last_error_code')) {
            $this->addSql('ALTER TABLE email_order_emails ADD COLUMN last_error_code VARCHAR(64) NULL DEFAULT NULL');
        }
        if (!$hasCol($conn, 'email_order_emails', 'last_error_category')) {
            $this->addSql('ALTER TABLE email_order_emails ADD COLUMN last_error_category VARCHAR(32) NULL DEFAULT NULL');
        }
        if (!$hasCol($conn, 'email_order_emails', 'failed_at')) {
            $this->addSql('ALTER TABLE email_order_emails ADD COLUMN failed_at DATETIME NULL DEFAULT NULL');
        }
        if (!$hasIdx($conn, 'email_order_emails', 'idx_email_order_email_order_status')) {
            $this->addSql('CREATE INDEX idx_email_order_email_order_status ON email_order_emails (order_id, status)');
        }
    }

    public function down(Schema $schema): void
    {
        // Bilinçli no-op: prod kolon düşürme riskli
    }
}
