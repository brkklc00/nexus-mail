<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * İşlem geçmişi tablosu (kredi hareketleri)
 */
final class Version20250127_CreditTransactions extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'credit_transactions tablosu';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
CREATE TABLE IF NOT EXISTS `credit_transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` VARCHAR(50) NOT NULL COMMENT 'sms, otp, email, email_otp, transactional, whatsapp',
  `amount` DECIMAL(10,2) NOT NULL COMMENT 'Pozitif: ekleme, Negatif: çıkarma',
  `description` TEXT NULL,
  `balance_before` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `balance_after` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `created_by` VARCHAR(100) NULL DEFAULT 'system',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_type` (`type`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS credit_transactions');
    }
}
