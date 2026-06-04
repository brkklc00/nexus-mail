<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 100M+ mail kredisi için DECIMAL(10,2) yetersiz; sütunları genişletir.
 */
final class Version20260608_ExpandEmailCreditPrecision extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users.email_credit ve email_transactions bakiye sütunlarını DECIMAL(14,2) yapar';
    }

    public function up(Schema $schema): void
    {
        $db = $this->connection;
        $dbName = (string) ($db->getDatabase() ?: $db->fetchOne('SELECT DATABASE()'));

        $columnExists = static function (string $table, string $column) use ($db, $dbName): bool {
            return (int) $db->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $column]
            ) > 0;
        };

        if ($columnExists('users', 'email_credit')) {
            $this->addSql('ALTER TABLE users MODIFY email_credit DECIMAL(14,2) NOT NULL DEFAULT 0');
        }

        if ($columnExists('email_transactions', 'amount')) {
            $this->addSql('ALTER TABLE email_transactions MODIFY amount DECIMAL(14,2) NOT NULL');
            $this->addSql('ALTER TABLE email_transactions MODIFY balance_before DECIMAL(14,2) NOT NULL');
            $this->addSql('ALTER TABLE email_transactions MODIFY balance_after DECIMAL(14,2) NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users MODIFY email_credit DECIMAL(10,2) NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE email_transactions MODIFY amount DECIMAL(10,2) NOT NULL');
        $this->addSql('ALTER TABLE email_transactions MODIFY balance_before DECIMAL(10,2) NOT NULL');
        $this->addSql('ALTER TABLE email_transactions MODIFY balance_after DECIMAL(10,2) NOT NULL');
    }
}
