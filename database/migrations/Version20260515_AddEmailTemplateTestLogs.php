<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515_AddEmailTemplateTestLogs extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin email template test gönderim log tablosunu ekler.';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = $conn->getDatabase();

        $tableExists = static function (string $table) use ($conn, $dbName): bool {
            try {
                return (int) $conn->fetchOne(
                    'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$dbName, $table]
                ) > 0;
            } catch (\Throwable) {
                return false;
            }
        };

        if (!$tableExists('email_template_test_logs')) {
            $this->addSql('CREATE TABLE email_template_test_logs (
                id INT AUTO_INCREMENT NOT NULL,
                admin_user_id INT NOT NULL,
                template_id INT NOT NULL,
                to_email VARCHAR(255) NOT NULL,
                status VARCHAR(20) NOT NULL,
                error_message TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_email_tpl_test_admin_created (admin_user_id, created_at),
                INDEX idx_email_tpl_test_template_created (template_id, created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE email_template_test_logs');
    }
}

