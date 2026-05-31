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
     * @param array<string, mixed> $options
     */
    public function enqueue(int $poolId, string $type, array $payload = [], int $totalCount = 0, array $options = []): int
    {
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $maxAttempts = max(1, (int) ($options['max_attempts'] ?? ($_ENV['DATA_POOL_JOB_MAX_ATTEMPTS'] ?? 3)));
        $resumable = ((int) ($options['resumable'] ?? 1)) === 1 ? 1 : 0;
        $conn->executeStatement(
            'INSERT INTO data_pool_jobs
                (pool_id, type, status, payload, total_count, processed_count, success_count, failed_count, progress_percent, result, error_message, started_at, finished_at, created_at, updated_at, attempts, max_attempts, resumable, cancel_requested)
             VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, NULL, NULL, NULL, NULL, ?, ?, 0, ?, ?, 0)',
            [
                $poolId > 0 ? $poolId : null,
                $type,
                'queued',
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                max(0, $totalCount),
                $now,
                $now,
                $maxAttempts,
                $resumable,
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

    public function markRunning(int $jobId, ?string $workerId = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $workerId = $workerId !== null && trim($workerId) !== '' ? trim($workerId) : null;
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'running',
                    started_at = COALESCE(started_at, ?),
                    locked_by = COALESCE(?, locked_by),
                    worker_id = COALESCE(?, worker_id),
                    locked_at = COALESCE(locked_at, ?),
                    heartbeat_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [$now, $workerId, $workerId, $now, $now, $now, $jobId]
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
                    error_code = NULL,
                    exception_class = NULL,
                    failed_step = NULL,
                    last_sql_name = NULL,
                    cancel_requested = 0,
                    locked_by = NULL,
                    locked_at = NULL,
                    heartbeat_at = ?,
                    status_message = 'İşlem tamamlandı',
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
                $now,
                $jobId,
            ]
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    public function markFailed(int $jobId, string $message, array $context = []): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'failed',
                    error_message = ?,
                    error_code = ?,
                    exception_class = ?,
                    failed_step = ?,
                    last_sql_name = ?,
                    worker_id = COALESCE(?, worker_id),
                    status_message = ?,
                    locked_by = NULL,
                    locked_at = NULL,
                    heartbeat_at = ?,
                    finished_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [
                $message,
                $context['error_code'] ?? null,
                $context['exception_class'] ?? null,
                $context['failed_step'] ?? null,
                $context['last_sql_name'] ?? null,
                $context['worker_id'] ?? null,
                $context['status_message'] ?? 'İşlem başarısız oldu.',
                $now,
                $now,
                $now,
                $jobId,
            ]
        );
    }

    public function markCancelled(int $jobId, string $message = 'İşlem kullanıcı tarafından iptal edildi.'): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'cancelled',
                    error_message = ?,
                    status_message = ?,
                    cancel_requested = 0,
                    locked_by = NULL,
                    locked_at = NULL,
                    finished_at = ?,
                    heartbeat_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [$message, 'İşlem iptal edildi.', $now, $now, $now, $jobId]
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function updateProgress(int $jobId, int $processed, int $total, int $success, int $failed, array $meta = []): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $total = max(0, $total);
        $processed = max(0, $processed);
        if ($total > 0) {
            $processed = min($processed, $total);
        }
        $percent = $total > 0 ? (int) min(100, floor(($processed / max(1, $total)) * 100)) : 0;
        $this->em->getConnection()->executeStatement(
            'UPDATE data_pool_jobs
                SET total_count = ?,
                    processed_count = ?,
                    success_count = ?,
                    failed_count = ?,
                    progress_percent = ?,
                    current_step = COALESCE(?, current_step),
                    status_message = COALESCE(?, status_message),
                    last_processed_id = COALESCE(?, last_processed_id),
                    cursor_payload = COALESCE(?, cursor_payload),
                    heartbeat_at = ?,
                    updated_at = ?
              WHERE id = ?',
            [
                $total,
                $processed,
                max(0, $success),
                max(0, $failed),
                $percent,
                isset($meta['current_step']) ? (string) $meta['current_step'] : null,
                isset($meta['message']) ? (string) $meta['message'] : null,
                isset($meta['last_processed_id']) ? (int) $meta['last_processed_id'] : null,
                isset($meta['cursor_payload']) ? json_encode($meta['cursor_payload'], JSON_UNESCAPED_UNICODE) : null,
                $now,
                $now,
                $jobId,
            ]
        );
    }

    public function updateHeartbeat(int $jobId, ?string $workerId = null, ?int $lastProcessedId = null, ?array $cursor = null): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET heartbeat_at = ?,
                    worker_id = COALESCE(?, worker_id),
                    last_processed_id = COALESCE(?, last_processed_id),
                    cursor_payload = COALESCE(?, cursor_payload),
                    updated_at = ?
              WHERE id = ?",
            [
                $now,
                $workerId,
                $lastProcessedId,
                $cursor !== null ? json_encode($cursor, JSON_UNESCAPED_UNICODE) : null,
                $now,
                $jobId,
            ]
        );
    }

    public function requestCancel(int $jobId): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE data_pool_jobs SET cancel_requested = 1, updated_at = ? WHERE id = ? AND status IN (\'queued\', \'running\')',
            [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $jobId]
        );
    }

    public function isCancelRequested(int $jobId): bool
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT cancel_requested FROM data_pool_jobs WHERE id = ?', [$jobId]) === 1;
    }

    public function requeueForRetry(int $jobId, int $delaySeconds, string $message): void
    {
        $delaySeconds = max(1, $delaySeconds);
        $this->em->getConnection()->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'queued',
                    status_message = ?,
                    error_message = NULL,
                    locked_by = NULL,
                    locked_at = NULL,
                    heartbeat_at = NULL,
                    next_run_at = DATE_ADD(NOW(), INTERVAL ? SECOND),
                    updated_at = NOW()
              WHERE id = ?",
            [$message, $delaySeconds, $jobId]
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
            'error_code' => (string) ($row['error_code'] ?? ''),
            'exception_class' => (string) ($row['exception_class'] ?? ''),
            'failed_step' => (string) ($row['failed_step'] ?? ''),
            'last_sql_name' => (string) ($row['last_sql_name'] ?? ''),
            'current_step' => (string) ($row['current_step'] ?? ''),
            'status_message' => (string) ($row['status_message'] ?? ''),
            'locked_by' => $row['locked_by'] ?? null,
            'locked_at' => $row['locked_at'] ?? null,
            'heartbeat_at' => $row['heartbeat_at'] ?? null,
            'attempts' => (int) ($row['attempts'] ?? 0),
            'max_attempts' => (int) ($row['max_attempts'] ?? 3),
            'resumable' => (int) ($row['resumable'] ?? 1),
            'cancel_requested' => (int) ($row['cancel_requested'] ?? 0),
            'last_processed_id' => isset($row['last_processed_id']) ? (int) $row['last_processed_id'] : null,
            'worker_id' => $row['worker_id'] ?? null,
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
