<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531_AddEmailOrderDispatchApprovalBatch extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_orders tablosuna toplu dispatch onayi icin batch alanlari ve status degeri ekler';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;
        $dbName = (string) $conn->getDatabase();

        $hasColumn = static function (string $column) use ($conn, $dbName): bool {
            $count = $conn->fetchOne(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND COLUMN_NAME = ?",
                [$dbName, $column]
            );

            return (int) $count > 0;
        };

        if (!$hasColumn('dispatch_batch_id')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN dispatch_batch_id VARCHAR(64) DEFAULT NULL COMMENT 'Toplu dispatch batch id'");
        }

        if (!$hasColumn('dispatch_approved_at')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN dispatch_approved_at DATETIME DEFAULT NULL COMMENT 'Toplu dispatch onay zamani'");
        }

        if (!$hasColumn('dispatch_approved_by')) {
            $this->addSql("ALTER TABLE email_orders ADD COLUMN dispatch_approved_by INT DEFAULT NULL COMMENT 'Toplu dispatch onaylayan admin id'");
        }

        $statusExists = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders'
               AND COLUMN_NAME = 'status' AND COLUMN_TYPE LIKE '%approved_for_dispatch%'",
            [$dbName]
        ) > 0;

        if (!$statusExists) {
            $this->addSql("ALTER TABLE email_orders MODIFY status ENUM('pending_approval','approved_for_dispatch','pending','processing','sent','completed','failed','cancelled') NOT NULL DEFAULT 'pending'");
        }

        $batchIdxExists = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND INDEX_NAME = 'idx_email_orders_dispatch_batch_status'",
            [$dbName]
        ) > 0;

        if (!$batchIdxExists) {
            $this->addSql('CREATE INDEX idx_email_orders_dispatch_batch_status ON email_orders (status, dispatch_batch_id)');
        }

        $approvedAtIdxExists = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'email_orders' AND INDEX_NAME = 'idx_email_orders_dispatch_approved_at'",
            [$dbName]
        ) > 0;

        if (!$approvedAtIdxExists) {
            $this->addSql('CREATE INDEX idx_email_orders_dispatch_approved_at ON email_orders (dispatch_approved_at)');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_email_orders_dispatch_approved_at ON email_orders');
        $this->addSql('DROP INDEX idx_email_orders_dispatch_batch_status ON email_orders');
        $this->addSql("ALTER TABLE email_orders MODIFY status ENUM('pending_approval','pending','processing','sent','completed','failed','cancelled') NOT NULL DEFAULT 'pending'");
        $this->addSql('ALTER TABLE email_orders DROP COLUMN dispatch_approved_by');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN dispatch_approved_at');
        $this->addSql('ALTER TABLE email_orders DROP COLUMN dispatch_batch_id');
    }
}
