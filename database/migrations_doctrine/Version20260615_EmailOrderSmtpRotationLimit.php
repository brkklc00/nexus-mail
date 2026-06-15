<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260615_EmailOrderSmtpRotationLimit extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_orders: smtp_rotation_limit — per-campaign SMTP rotation cap (default 500)';
    }

    public function up(Schema $schema): void
    {
        $conn   = $this->connection;
        $dbName = $conn->getDatabase();

        $columnExists = static function (string $table, string $col) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$dbName, $table, $col]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        if (!$columnExists('email_orders', 'smtp_rotation_limit')) {
            $conn->executeStatement(
                'ALTER TABLE email_orders ADD COLUMN smtp_rotation_limit INT UNSIGNED NOT NULL DEFAULT 500 AFTER worker_stop_requested'
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE email_orders DROP COLUMN IF EXISTS smtp_rotation_limit'
        );
    }
}
