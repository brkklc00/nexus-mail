<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260328_EmailSendingWorkerThroughput extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_sending_config: fetch/send batch, SMTP lane sayısı, throttle, pool max messages, Alibaba warmup tavanı';
    }

    private function columnExists(string $table, string $column): bool
    {
        $db = $this->connection->getDatabase();
        $r = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$db, $table, $column]
        );

        return (int) $r > 0;
    }

    public function up(Schema $schema): void
    {
        if (!$this->columnExists('email_sending_config', 'daily_limit')) {
            return;
        }
        if (!$this->columnExists('email_sending_config', 'worker_fetch_batch_size')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_fetch_batch_size INT NOT NULL DEFAULT 10000');
        }
        if (!$this->columnExists('email_sending_config', 'worker_send_batch_size')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_send_batch_size INT NOT NULL DEFAULT 500');
        }
        if (!$this->columnExists('email_sending_config', 'worker_max_smtp_lanes')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_max_smtp_lanes INT NOT NULL DEFAULT 10');
        }
        if (!$this->columnExists('email_sending_config', 'worker_throttle_step_up')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_throttle_step_up DOUBLE PRECISION NOT NULL DEFAULT 0.5');
        }
        if (!$this->columnExists('email_sending_config', 'worker_throttle_cooldown_ms')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_throttle_cooldown_ms INT NOT NULL DEFAULT 15000');
        }
        if (!$this->columnExists('email_sending_config', 'worker_smtp_pool_max_messages')) {
            $this->addSql('ALTER TABLE email_sending_config ADD worker_smtp_pool_max_messages INT NOT NULL DEFAULT 100');
        }
        if (!$this->columnExists('email_sending_config', 'alibaba_warmup_max_rate_per_second')) {
            $this->addSql('ALTER TABLE email_sending_config ADD alibaba_warmup_max_rate_per_second DOUBLE PRECISION DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($this->columnExists('email_sending_config', 'alibaba_warmup_max_rate_per_second')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN alibaba_warmup_max_rate_per_second');
        }
        if ($this->columnExists('email_sending_config', 'worker_smtp_pool_max_messages')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_smtp_pool_max_messages');
        }
        if ($this->columnExists('email_sending_config', 'worker_throttle_cooldown_ms')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_throttle_cooldown_ms');
        }
        if ($this->columnExists('email_sending_config', 'worker_throttle_step_up')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_throttle_step_up');
        }
        if ($this->columnExists('email_sending_config', 'worker_max_smtp_lanes')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_max_smtp_lanes');
        }
        if ($this->columnExists('email_sending_config', 'worker_send_batch_size')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_send_batch_size');
        }
        if ($this->columnExists('email_sending_config', 'worker_fetch_batch_size')) {
            $this->addSql('ALTER TABLE email_sending_config DROP COLUMN worker_fetch_batch_size');
        }
    }
}
