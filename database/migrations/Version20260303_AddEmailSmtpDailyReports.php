<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303_AddEmailSmtpDailyReports extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds daily SMTP delivery report table for Alibaba DirectMail sync';
    }

    public function up(Schema $schema): void
    {
        $connection = $this->connection;
        $dbName = $connection->getDatabase();

        $tableExists = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_smtp_daily_reports'",
            [$dbName]
        ) > 0;

        if (!$tableExists) {
            $this->addSql('CREATE TABLE email_smtp_daily_reports (
                id INT AUTO_INCREMENT NOT NULL,
                source VARCHAR(40) NOT NULL,
                report_date DATE NOT NULL,
                smtp_name VARCHAR(191) NOT NULL,
                domain VARCHAR(191) NOT NULL,
                total INT NOT NULL DEFAULT 0,
                successful INT NOT NULL DEFAULT 0,
                failed INT NOT NULL DEFAULT 0,
                invalid_address INT NOT NULL DEFAULT 0,
                success_rate NUMERIC(5, 2) NOT NULL DEFAULT 0.00,
                invalid_rate NUMERIC(5, 2) NOT NULL DEFAULT 0.00,
                raw_payload LONGTEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_smtp_daily_report (source, report_date, domain, smtp_name),
                INDEX idx_smtp_daily_report_date (report_date),
                INDEX idx_smtp_daily_report_source (source),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS email_smtp_daily_reports');
    }
}

