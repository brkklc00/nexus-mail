<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251013_AddEmailBalanceColumns extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Users tablosuna email_balance ve email_otp_balance kolonlarını ekler';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $has = static function ($connection, string $dbName, string $col): bool {
            return (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'users', $col],
            ) > 0;
        };

        if (!$has($connection, $dbName, 'email_balance')) {
            $this->addSql(
                "ALTER TABLE users ADD COLUMN email_balance INT NOT NULL DEFAULT 0 COMMENT 'Email kampanya bakiyesi'",
            );
        }

        if (!$has($connection, $dbName, 'email_otp_balance')) {
            $this->addSql(
                "ALTER TABLE users ADD COLUMN email_otp_balance INT NOT NULL DEFAULT 0 COMMENT 'Email OTP bakiyesi'",
            );
        }
    }

    public function down(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $has = static function ($connection, string $dbName, string $col): bool {
            return (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'users', $col],
            ) > 0;
        };

        if ($has($connection, $dbName, 'email_balance')) {
            $this->addSql('ALTER TABLE users DROP COLUMN email_balance');
        }

        if ($has($connection, $dbName, 'email_otp_balance')) {
            $this->addSql('ALTER TABLE users DROP COLUMN email_otp_balance');
        }
    }
}
