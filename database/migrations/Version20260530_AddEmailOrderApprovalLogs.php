<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530_AddEmailOrderApprovalLogs extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email order approval log tablosu (external customer + balance action)';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) $conn->getDatabase();

        $tableExists = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_order_approval_logs'",
            [$dbName]
        ) > 0;

        if (!$tableExists) {
            $this->addSql("CREATE TABLE email_order_approval_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                order_id INT NOT NULL,
                admin_user_id INT DEFAULT NULL,
                external_customer_id INT NOT NULL,
                external_customer_name VARCHAR(191) DEFAULT NULL,
                external_customer_email VARCHAR(191) DEFAULT NULL,
                selected_data_list_id INT DEFAULT NULL,
                selected_data_list_name VARCHAR(191) DEFAULT NULL,
                order_total BIGINT NOT NULL DEFAULT 0,
                balance_old_amount BIGINT DEFAULT NULL,
                balance_amount BIGINT NOT NULL DEFAULT 0,
                balance_new_amount BIGINT DEFAULT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'failed',
                error_message TEXT DEFAULT NULL,
                api_response JSON DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_email_order_approval_logs_order (order_id),
                INDEX idx_email_order_approval_logs_status (status),
                INDEX idx_email_order_approval_logs_external_customer (external_customer_id),
                CONSTRAINT fk_email_order_approval_logs_order FOREIGN KEY (order_id) REFERENCES email_orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_email_order_approval_logs_admin FOREIGN KEY (admin_user_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        }
    }

    public function down(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) $conn->getDatabase();
        $tableExists = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_order_approval_logs'",
            [$dbName]
        ) > 0;
        if ($tableExists) {
            $this->addSql('DROP TABLE email_order_approval_logs');
        }
    }
}

