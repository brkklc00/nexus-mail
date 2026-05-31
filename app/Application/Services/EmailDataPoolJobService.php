<?php

declare(strict_types=1);

namespace App\Application\Services;

use Doctrine\ORM\EntityManagerInterface;

class EmailDataPoolJobService
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(int $poolId, string $type, array $payload = [], int $totalCount = 0): int
    {
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'INSERT INTO data_pool_jobs
                (pool_id, type, status, payload, total_count, processed_count, success_count, failed_count, progress_percent, result, error_message, started_at, finished_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, NULL, NULL, NULL, NULL, ?, ?)',
            [
                $poolId > 0 ? $poolId : null,
                $type,
                'queued',
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                max(0, $totalCount),
                $now,
                $now,
            ]
        );

        return (int) $conn->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJob(int $jobId): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM data_pool_jobs WHERE id = ?', [$jobId]);
        if (!$row) {
            return null;
        }

        return $this->normalizeJobRow($row);
    }

    /**
     * @param array<int, string>|null $types
     * @return array<string, mixed>|null
     */
    public function getRunningJobForPool(int $poolId, ?array $types = null): ?array
    {
        $conn = $this->em->getConnection();
        $params = [$poolId, 'running'];
        $sql = 'SELECT * FROM data_pool_jobs WHERE pool_id = ? AND status = ?';
        if (is_array($types) && $types !== []) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND type IN ($in)";
            foreach ($types as $type) {
                $params[] = (string) $type;
            }
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = $conn->fetchAssociative($sql, $params);

        return $row ? $this->normalizeJobRow($row) : null;
    }

    public function markRunning(int $jobId): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'running', started_at = COALESCE(started_at, ?), updated_at = ?
              WHERE id = ?",
            [$now, $now, $jobId]
        );
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function markCompleted(int $jobId, int $processed, int $success, int $failed, ?array $result = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'completed',
                    processed_count = ?,
                    success_count = ?,
                    failed_count = ?,
                    progress_percent = 100,
                    result = ?,
                    error_message = NULL,
                    finished_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [
                max(0, $processed),
                max(0, $success),
                max(0, $failed),
                json_encode($result ?? [], JSON_UNESCAPED_UNICODE),
                $now,
                $now,
                $jobId,
            ]
        );
    }

    public function markFailed(int $jobId, string $message): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'failed',
                    error_message = ?,
                    finished_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [$message, $now, $now, $jobId]
        );
    }

    public function updateProgress(int $jobId, int $processed, int $total, int $success, int $failed): void
    {
        $total = max(0, $total);
        $processed = max(0, $processed);
        $percent = $total > 0 ? (int) min(100, floor(($processed / max(1, $total)) * 100)) : 0;
        $this->em->getConnection()->executeStatement(
            'UPDATE data_pool_jobs
                SET total_count = ?, processed_count = ?, success_count = ?, failed_count = ?, progress_percent = ?, updated_at = ?
              WHERE id = ?',
            [
                $total,
                $processed,
                max(0, $success),
                max(0, $failed),
                $percent,
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $jobId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeJobRow(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $result = json_decode((string) ($row['result'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'pool_id' => isset($row['pool_id']) ? (int) $row['pool_id'] : null,
            'type' => (string) ($row['type'] ?? ''),
            'status' => (string) ($row['status'] ?? 'queued'),
            'payload' => is_array($payload) ? $payload : [],
            'total_count' => (int) ($row['total_count'] ?? 0),
            'processed_count' => (int) ($row['processed_count'] ?? 0),
            'success_count' => (int) ($row['success_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'progress_percent' => (int) ($row['progress_percent'] ?? 0),
            'result' => is_array($result) ? $result : [],
            'error_message' => (string) ($row['error_message'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
