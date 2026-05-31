<?php

declare(strict_types=1);

namespace App\Application\Services;

use Doctrine\ORM\EntityManagerInterface;

class EmailDataPoolStatsService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<int, int> $poolIds
     * @return array<int, array<string, mixed>>
     */
    public function getPoolStatsMap(array $poolIds): array
    {
        $poolIds = array_values(array_filter(array_map('intval', $poolIds), static fn (int $id): bool => $id > 0));
        if ($poolIds === []) {
            return [];
        }

        $conn = $this->em->getConnection();
        $out = [];
        foreach (array_chunk($poolIds, 500) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT pool_id, total_count, active_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at, updated_at
                   FROM email_pool_stats
                  WHERE pool_id IN ($in)",
                $chunk
            );
            foreach ($rows as $row) {
                $poolId = (int) ($row['pool_id'] ?? 0);
                if ($poolId > 0) {
                    $out[$poolId] = $this->normalizeRow($row);
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPoolStats(int $poolId): array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT pool_id, total_count, active_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at, updated_at FROM email_pool_stats WHERE pool_id = ?',
            [$poolId]
        );

        if (!$row) {
            return [
                'pool_id' => $poolId,
                'total_count' => 0,
                'active_count' => 0,
                'gmail_count' => 0,
                'non_gmail_count' => 0,
                'invalid_gmail_count' => 0,
                'duplicate_count' => 0,
                'target_limit' => null,
                'last_analyzed_at' => null,
                'updated_at' => null,
            ];
        }

        return $this->normalizeRow($row);
    }

    public function refreshFromPoolCache(int $poolId): void
    {
        $conn = $this->em->getConnection();
        $list = $conn->fetchAssociative(
            'SELECT id, total_count, active_count FROM email_data_pool_lists WHERE id = ?',
            [$poolId]
        );
        if (!$list) {
            return;
        }

        $analysis = $conn->fetchAssociative(
            'SELECT gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at FROM email_data_pool_analysis_cache WHERE list_id = ?',
            [$poolId]
        ) ?: [];

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'INSERT INTO email_pool_stats
                (pool_id, total_count, active_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_count = VALUES(total_count),
                active_count = VALUES(active_count),
                gmail_count = VALUES(gmail_count),
                non_gmail_count = VALUES(non_gmail_count),
                invalid_gmail_count = VALUES(invalid_gmail_count),
                duplicate_count = VALUES(duplicate_count),
                target_limit = VALUES(target_limit),
                last_analyzed_at = VALUES(last_analyzed_at),
                updated_at = VALUES(updated_at)',
            [
                $poolId,
                (int) ($list['total_count'] ?? 0),
                (int) ($list['active_count'] ?? 0),
                (int) ($analysis['gmail_count'] ?? 0),
                (int) ($analysis['non_gmail_count'] ?? 0),
                (int) ($analysis['invalid_gmail_count'] ?? 0),
                (int) ($analysis['duplicate_count'] ?? 0),
                isset($analysis['target_limit']) ? (int) $analysis['target_limit'] : null,
                isset($analysis['last_analyzed_at']) ? (string) $analysis['last_analyzed_at'] : null,
                $now,
                $now,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'pool_id' => (int) ($row['pool_id'] ?? 0),
            'total_count' => (int) ($row['total_count'] ?? 0),
            'active_count' => (int) ($row['active_count'] ?? 0),
            'gmail_count' => (int) ($row['gmail_count'] ?? 0),
            'non_gmail_count' => (int) ($row['non_gmail_count'] ?? 0),
            'invalid_gmail_count' => (int) ($row['invalid_gmail_count'] ?? 0),
            'duplicate_count' => (int) ($row['duplicate_count'] ?? 0),
            'target_limit' => isset($row['target_limit']) ? (int) $row['target_limit'] : null,
            'last_analyzed_at' => isset($row['last_analyzed_at']) ? (string) $row['last_analyzed_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}
