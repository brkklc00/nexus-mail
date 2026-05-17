<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260401_EmailSendingRuntimeSettings extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_sending_config: rate_source + worker batch/chunk/concurrency/pool kolonları';
    }

    private function columnExists(string $table, string $column): bool
    {
        $db = $this->connection->getDatabase();
        $r = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$db, $table, $column]
        );

        return (int) $r > 0;
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('email_sending_config', 'daily_limit')) {
            return;
        }
        if (!$this->columnExists('email_sending_config', 'rate_source')) {
            $this->addSql("ALTER TABLE email_sending_config ADD rate_source VARCHAR(32) NOT NULL DEFAULT 'manual'");
        }
        if (!$this->columnExists('email_sending_config', 'worker_batch_gap_ms')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_batch_gap_ms INT NOT NULL DEFAULT 100');
        }
        if (!$this->columnExists('email_sending_config', 'worker_chunk_gap_ms')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_chunk_gap_ms INT NOT NULL DEFAULT 50');
        }
        if (!$this->columnExists('email_sending_config', 'worker_send_concurrency')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_send_concurrency INT NOT NULL DEFAULT 1');
        }
        if (!$this->columnExists('email_sending_config', 'worker_smtp_pool_connections')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_smtp_pool_connections INT NOT NULL DEFAULT 0');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('email_sending_config', 'worker_smtp_pool_connections')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_smtp_pool_connections');
        }
        if ($this->columnExists('email_sending_config', 'worker_send_concurrency')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_send_concurrency');
        }
        if ($this->columnExists('email_sending_config', 'worker_chunk_gap_ms')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_chunk_gap_ms');
        }
        if ($this->columnExists('email_sending_config', 'worker_batch_gap_ms')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_batch_gap_ms');
        }
        if ($this->columnExists('email_sending_config', 'rate_source')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN rate_source');
        }
    }
}
