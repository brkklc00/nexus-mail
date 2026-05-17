<?php

declare(strict_types=1);

namespace Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * URL Shortener için API ID ve Click Stats alanlarını ekler.
 *
 * Idempotent: INFORMATION_SCHEMA + DATABASE() (ALTER ile aynı oturum DB'si).
 * Ek güvenlik: MySQL 1060/1061 duplicate hataları yutulur (SHOW ... LIKE + prepared
 * statement bazı sürücülerde kolonu görmeyebiliyordu).
 */
final class Version20260118_AddUrlShortenerApiFields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_id and click_stats columns to shortened_urls table';
    }

    public function up(Schema $schema): void
    {
        $conn = $this->connection;

        if (
            $this->mysqlColumnExists($conn, 'shortened_urls', 'api_id')
            && $this->mysqlColumnExists($conn, 'shortened_urls', 'click_stats')
            && $this->mysqlIndexExists($conn, 'shortened_urls', 'idx_api_id')
        ) {
            $this->write('Version20260118: shortened_urls api_id / click_stats / idx_api_id zaten mevcut, atlandı.');

            return;
        }

        $this->safeExecute($conn, 'ALTER TABLE shortened_urls ADD COLUMN api_id VARCHAR(50) NULL AFTER short_code', [1060]);
        $this->safeExecute($conn, 'ALTER TABLE shortened_urls ADD COLUMN click_stats JSON NULL AFTER click_count', [1060]);
        $this->safeExecute($conn, 'CREATE INDEX idx_api_id ON shortened_urls(api_id)', [1061]);
    }

    public function down(Schema $schema): void
    {
        $conn = $this->connection;

        if ($this->mysqlIndexExists($conn, 'shortened_urls', 'idx_api_id')) {
            $conn->executeStatement('DROP INDEX idx_api_id ON shortened_urls');
        }

        if ($this->mysqlColumnExists($conn, 'shortened_urls', 'click_stats')) {
            $conn->executeStatement('ALTER TABLE shortened_urls DROP COLUMN click_stats');
        }

        if ($this->mysqlColumnExists($conn, 'shortened_urls', 'api_id')) {
            $conn->executeStatement('ALTER TABLE shortened_urls DROP COLUMN api_id');
        }
    }

    /**
     * @param int[] $ignoreMysqlCodes Örn. 1060 duplicate column, 1061 duplicate key name
     */
    private function safeExecute($conn, string $sql, array $ignoreMysqlCodes = []): void
    {
        try {
            $conn->executeStatement($sql);
        } catch (\Throwable $e) {
            $code = $this->mysqlErrorCode($e);
            if ($code !== null && in_array($code, $ignoreMysqlCodes, true)) {
                return;
            }
            if ($this->messageLooksLikeDuplicate($e->getMessage())) {
                return;
            }
            throw $e;
        }
    }

    private function messageLooksLikeDuplicate(string $message): bool
    {
        return str_contains($message, '1060')
            || str_contains($message, '1061')
            || stripos($message, 'Duplicate column') !== false
            || stripos($message, 'Duplicate key name') !== false;
    }

    private function mysqlErrorCode(\Throwable $e): ?int
    {
        for ($i = 0; $e !== null && $i < 8; $i++, $e = $e->getPrevious()) {
            if ($e instanceof \PDOException && isset($e->errorInfo[1])) {
                return (int) $e->errorInfo[1];
            }
        }

        return null;
    }

    private function mysqlColumnExists($conn, string $table, string $column): bool
    {
        try {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function mysqlIndexExists($conn, string $table, string $indexName): bool
    {
        try {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                [$table, $indexName]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
