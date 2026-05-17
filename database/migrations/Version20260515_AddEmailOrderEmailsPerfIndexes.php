<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515_AddEmailOrderEmailsPerfIndexes extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_order_emails performans indexleri: (order_id,email) ve (order_id,status,id)';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        $tableExists = static function ($c, string $table): bool {
            return (int) $c->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) > 0;
        };

        $indexExists = static function ($c, string $table, string $index): bool {
            return (int) $c->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $index]
            ) > 0;
        };

        if (!$tableExists($conn, 'email_order_emails')) {
            return;
        }

        if (!$indexExists($conn, 'email_order_emails', 'idx_email_order_email_order_email')) {
            $this->addSql('CREATE INDEX idx_email_order_email_order_email ON email_order_emails (order_id, email)');
        }

        if (!$indexExists($conn, 'email_order_emails', 'idx_email_order_email_order_status_id')) {
            $this->addSql('CREATE INDEX idx_email_order_email_order_status_id ON email_order_emails (order_id, status, id)');
        }
    }

    public function down(Schema $schema): void
    {
        $conn = $this->connection;

        $indexExists = static function ($c, string $table, string $index): bool {
            return (int) $c->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $index]
            ) > 0;
        };

        if ($indexExists($conn, 'email_order_emails', 'idx_email_order_email_order_email')) {
            $this->addSql('DROP INDEX idx_email_order_email_order_email ON email_order_emails');
        }
        if ($indexExists($conn, 'email_order_emails', 'idx_email_order_email_order_status_id')) {
            $this->addSql('DROP INDEX idx_email_order_email_order_status_id ON email_order_emails');
        }
    }
}

