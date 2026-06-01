<?php

declare(strict_types=1);

namespace App\Support\Console;

use App\Application\Services\EmailDataPoolJobService;
use App\Application\Services\EmailDataPoolStatsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class DataPoolJobsWorkCommand extends Command
{
    private const CANCELLED_EXCEPTION_PREFIX = 'JOB_CANCELLED:';
    private const PAUSED_EXCEPTION_PREFIX = 'JOB_PAUSED:';

    protected static $defaultName = 'data-pool:jobs:work';

    public function __construct(private ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Data pool kuyrugundaki bekleyen isleri batch olarak calistirir.')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Tek bir job calistirip cikar')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Maksimum calisma suresi (sn)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        /** @var EntityManagerInterface $em */
        $em = $this->container->get(\Doctrine\ORM\EntityManager::class);
        /** @var EmailDataPoolJobService $jobService */
        $jobService = $this->container->get(EmailDataPoolJobService::class);
        /** @var EmailDataPoolStatsService $statsService */
        $statsService = $this->container->get(EmailDataPoolStatsService::class);

        $batchSize = max(1000, (int) ($_ENV['DATA_POOL_JOB_BATCH_SIZE'] ?? 10000));
        $sleepMs = max(10, (int) ($_ENV['DATA_POOL_JOB_SLEEP_MS'] ?? 100));
        $maxRuntime = max(30, (int) ($input->getOption('max-runtime') ?? ($_ENV['DATA_POOL_JOB_MAX_RUNTIME'] ?? 300)));
        $staleMinutes = max(5, (int) ($_ENV['DATA_POOL_JOB_STALE_MINUTES'] ?? 30));
        $heartbeatSeconds = max(5, (int) ($_ENV['DATA_POOL_HEARTBEAT_SECONDS'] ?? 10));
        $maintenanceMaxRuntime = max(60, (int) ($_ENV['MAINTENANCE_MAX_RUNTIME_SECONDS'] ?? 600));
        $workerId = trim((string) ($_ENV['DATA_POOL_WORKER_ID'] ?? ('data-pool-worker-' . gethostname() . '-' . getmypid())));
        if ($workerId === '') {
            $workerId = 'data-pool-worker-' . getmypid();
        }
        $once = (bool) $input->getOption('once');
        $startedAt = time();
        $this->ensureWorkerInfrastructure($em->getConnection());
        $this->ensureWorkerJobColumns($em->getConnection());

        $io->text(sprintf('Data pool worker basladi (id=%s, batch=%d, sleep=%dms, max_runtime=%ds)', $workerId, $batchSize, $sleepMs, $maxRuntime));
        $recovered = $this->recoverStaleRunningJobs($em, $staleMinutes, $workerId);
        if (($recovered['requeued'] ?? 0) > 0 || ($recovered['failed'] ?? 0) > 0) {
            $io->warning(sprintf(
                'Stale jobs: %d yeniden kuyruğa alındı, %d failed işaretlendi.',
                (int) ($recovered['requeued'] ?? 0),
                (int) ($recovered['failed'] ?? 0)
            ));
        }
        $lastWorkerHeartbeatAt = 0;

        while (true) {
            if ((time() - $startedAt) >= $maxRuntime) {
                $io->text('Max runtime doldu, worker sonlaniyor.');
                break;
            }

            if ((time() - $lastWorkerHeartbeatAt) >= $heartbeatSeconds) {
                $this->touchWorkerHeartbeat($em->getConnection(), $workerId, null, 'idle');
                $lastWorkerHeartbeatAt = time();
            }

            $job = $this->claimNextQueuedJob($em, $workerId);
            if (!$job) {
                if ($once) {
                    break;
                }
                usleep($sleepMs * 1000);
                continue;
            }

            $jobId = (int) ($job['id'] ?? 0);
            $poolId = (int) ($job['pool_id'] ?? 0);
            $type = (string) ($job['type'] ?? '');
            $io->text(sprintf('#%d isleniyor: %s (pool: %d)', $jobId, $type, $poolId));
            $jobService->markRunning($jobId, $workerId);
            $this->touchWorkerHeartbeat($em->getConnection(), $workerId, $jobId, 'running');

            try {
                $result = match ($type) {
                    'analyze_pool' => $this->processAnalyzePool($em, $jobService, $jobId, $poolId, $batchSize),
                    'remove_non_gmail' => $this->processRemoveNonGmail($em, $jobService, $jobId, $poolId, $batchSize),
                    'remove_duplicates' => $this->processRemoveDuplicates($em, $jobService, $jobId, $poolId, $batchSize),
                    'fix_gmail_typos' => $this->processFixGmailTypos($em, $jobService, $jobId, $poolId, $batchSize),
                    'export_pool' => $this->processExportPool($em, $jobService, $jobId, $job, $poolId, $batchSize),
                    'complete_to_target' => $this->processCompleteToTarget($em, $jobService, $jobId, $job, $batchSize),
                    'copy_to_target' => $this->processCopyToTarget($em, $jobService, $jobId, $job, $batchSize),
                    'move_overflow' => $this->processMoveOverflowBalance($em, $jobService, $jobId, $job, $batchSize),
                    'split_pool' => $this->processSplitPool($em, $jobService, $jobId, $job, $batchSize),
                    'balance_pools' => $this->processBalancePools($em, $jobService, $jobId, $job, $batchSize),
                    'fill_new_pool' => $this->processFillNewPool($em, $jobService, $jobId, $job, $batchSize),
                    'global_analyze_all_pools' => $this->processGlobalAnalyzeAllPools($em, $jobService, $jobId, $batchSize),
                    'refresh_all_pool_stats' => $this->processRefreshAllPoolStats($em, $jobService, $jobId),
                    'alibaba_invalid_fetch' => $this->processAlibabaInvalidFetch($em, $jobService, $jobId, $job),
                    'alibaba_invalid_match_preview' => $this->processAlibabaInvalidMatchPreview($em, $jobService, $jobId, $job),
                    'alibaba_invalid_clean_apply' => $this->processAlibabaInvalidCleanApply($em, $jobService, $jobId, $job),
                    'alibaba_invalid_fetch_and_clean' => $this->processAlibabaInvalidFetchAndClean($em, $jobService, $jobId, $job),
                    'global_deduplicate_preview' => $this->processGlobalDeduplicatePreview($em, $jobService, $jobId, $batchSize),
                    'global_deduplicate_apply' => $this->processGlobalDeduplicateApply($em, $jobService, $jobId, $job, $batchSize),
                    'maintenance_preview' => $this->processMaintenancePreview($em, $jobService, $jobId, $job),
                    'cleanup_email_orders' => $this->processCleanupEmailOrders($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_email_order_details' => $this->processCleanupEmailOrderDetails($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_email_recipients' => $this->processCleanupEmailRecipients($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_email_send_results' => $this->processCleanupEmailSendResults($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'archive_email_recipients' => $this->processArchiveEmailRecipients($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'archive_email_send_results' => $this->processArchiveEmailSendResults($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_worker_batch_results' => $this->processCleanupWorkerBatchResults($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_data_pool_jobs' => $this->processCleanupDataPoolJobs($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_system_logs' => $this->processCleanupSystemLogs($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_export_files' => $this->processCleanupExportFiles($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'cleanup_temp_files' => $this->processCleanupTempFiles($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    'database_optimize_tables' => $this->processDatabaseOptimizeTables($em, $jobService, $jobId, $job, $maintenanceMaxRuntime),
                    default => throw new \RuntimeException('Desteklenmeyen job tipi: ' . $type),
                };

                if ((bool) ($result['defer'] ?? false)) {
                    $this->requeueContinuation($em->getConnection(), $jobId, (string) ($result['defer_message'] ?? 'Batch süresi doldu, job devam edecek.'), $result);
                    $io->note(sprintf('#%d batch tamamlandı, devam için kuyruğa alındı.', $jobId));
                    continue;
                }

                if ($poolId > 0) {
                    $statsService->refreshFromPoolCache($poolId);
                }
                $jobService->markCompleted(
                    $jobId,
                    (int) ($result['processed_count'] ?? 0),
                    (int) ($result['success_count'] ?? 0),
                    (int) ($result['failed_count'] ?? 0),
                    $result
                );
                $this->touchWorkerHeartbeat($em->getConnection(), $workerId, $jobId, 'idle');
                $io->success(sprintf('#%d tamamlandi', $jobId));
            } catch (\Throwable $e) {
                $this->handleJobFailure($jobService, $job, $e, $workerId, $io);
                $this->touchWorkerHeartbeat($em->getConnection(), $workerId, $jobId, 'idle');
            } finally {
                $em->clear();
            }

            if ($once) {
                break;
            }
        }

        return Command::SUCCESS;
    }

    private function ensureWorkerInfrastructure(\Doctrine\DBAL\Connection $conn): void
    {
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS data_pool_worker_heartbeats (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                worker_id VARCHAR(100) NOT NULL,
                hostname VARCHAR(255) DEFAULT NULL,
                pid INT DEFAULT NULL,
                current_job_id BIGINT DEFAULT NULL,
                status VARCHAR(30) NOT NULL,
                heartbeat_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uq_worker_id (worker_id),
                INDEX idx_heartbeat_at (heartbeat_at),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureWorkerJobColumns(\Doctrine\DBAL\Connection $conn): void
    {
        $columnExists = static function (string $column) use ($conn): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['data_pool_jobs', $column]
            ) > 0;
        };
        $columns = [
            'locked_by' => 'ALTER TABLE data_pool_jobs ADD COLUMN locked_by VARCHAR(100) DEFAULT NULL AFTER status',
            'locked_at' => 'ALTER TABLE data_pool_jobs ADD COLUMN locked_at DATETIME DEFAULT NULL AFTER locked_by',
            'heartbeat_at' => 'ALTER TABLE data_pool_jobs ADD COLUMN heartbeat_at DATETIME DEFAULT NULL AFTER locked_at',
            'attempts' => 'ALTER TABLE data_pool_jobs ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER heartbeat_at',
            'max_attempts' => 'ALTER TABLE data_pool_jobs ADD COLUMN max_attempts INT NOT NULL DEFAULT 3 AFTER attempts',
            'resumable' => 'ALTER TABLE data_pool_jobs ADD COLUMN resumable TINYINT(1) NOT NULL DEFAULT 1 AFTER max_attempts',
            'cancel_requested' => 'ALTER TABLE data_pool_jobs ADD COLUMN cancel_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER resumable',
            'pause_requested' => 'ALTER TABLE data_pool_jobs ADD COLUMN pause_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER cancel_requested',
            'last_processed_id' => 'ALTER TABLE data_pool_jobs ADD COLUMN last_processed_id BIGINT DEFAULT NULL AFTER pause_requested',
            'cursor_payload' => 'ALTER TABLE data_pool_jobs ADD COLUMN cursor_payload JSON DEFAULT NULL AFTER last_processed_id',
            'next_run_at' => 'ALTER TABLE data_pool_jobs ADD COLUMN next_run_at DATETIME DEFAULT NULL AFTER cursor_payload',
            'current_step' => 'ALTER TABLE data_pool_jobs ADD COLUMN current_step VARCHAR(120) DEFAULT NULL AFTER next_run_at',
            'status_message' => 'ALTER TABLE data_pool_jobs ADD COLUMN status_message VARCHAR(255) DEFAULT NULL AFTER current_step',
            'error_code' => 'ALTER TABLE data_pool_jobs ADD COLUMN error_code VARCHAR(64) DEFAULT NULL AFTER error_message',
            'exception_class' => 'ALTER TABLE data_pool_jobs ADD COLUMN exception_class VARCHAR(190) DEFAULT NULL AFTER error_code',
            'failed_step' => 'ALTER TABLE data_pool_jobs ADD COLUMN failed_step VARCHAR(120) DEFAULT NULL AFTER exception_class',
            'last_sql_name' => 'ALTER TABLE data_pool_jobs ADD COLUMN last_sql_name VARCHAR(120) DEFAULT NULL AFTER failed_step',
            'worker_id' => 'ALTER TABLE data_pool_jobs ADD COLUMN worker_id VARCHAR(100) DEFAULT NULL AFTER last_sql_name',
        ];
        foreach ($columns as $column => $sql) {
            if (!$columnExists($column)) {
                $conn->executeStatement($sql);
            }
        }
    }

    /**
     * @return array{requeued:int,failed:int}
     */
    private function recoverStaleRunningJobs(EntityManagerInterface $em, int $staleMinutes, string $workerId): array
    {
        $conn = $em->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT id, attempts, max_attempts, resumable
               FROM data_pool_jobs
              WHERE status = 'running'
                AND COALESCE(heartbeat_at, updated_at, started_at, created_at) < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$staleMinutes]
        );
        $requeued = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $jobId = (int) ($row['id'] ?? 0);
            if ($jobId < 1) {
                continue;
            }
            $attempts = (int) ($row['attempts'] ?? 0);
            $maxAttempts = max(1, (int) ($row['max_attempts'] ?? 3));
            $resumable = ((int) ($row['resumable'] ?? 1)) === 1;

            if ($resumable && $attempts < $maxAttempts) {
                $conn->executeStatement(
                    "UPDATE data_pool_jobs
                        SET status = 'queued',
                            status_message = 'Stale job tekrar kuyruğa alındı.',
                            locked_by = NULL,
                            locked_at = NULL,
                            heartbeat_at = NULL,
                            pause_requested = 0,
                            next_run_at = NOW(),
                            updated_at = NOW()
                      WHERE id = ?",
                    [$jobId]
                );
                $requeued++;
                continue;
            }

            $conn->executeStatement(
                "UPDATE data_pool_jobs
                    SET status = 'failed',
                        error_message = COALESCE(NULLIF(error_message, ''), 'Worker durduğu için stale job otomatik sonlandırıldı.'),
                        error_code = COALESCE(error_code, 'STALE_JOB'),
                        exception_class = COALESCE(exception_class, 'RuntimeException'),
                        failed_step = COALESCE(failed_step, 'recover_stale_running_jobs'),
                        worker_id = COALESCE(worker_id, ?),
                        pause_requested = 0,
                        locked_by = NULL,
                        locked_at = NULL,
                        finished_at = NOW(),
                        updated_at = NOW()
                  WHERE id = ?",
                [$workerId, $jobId]
            );
            $failed++;
        }

        return ['requeued' => $requeued, 'failed' => $failed];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimNextQueuedJob(EntityManagerInterface $em, string $workerId): ?array
    {
        $conn = $em->getConnection();
        $lock = (int) $conn->fetchOne("SELECT GET_LOCK('data_pool_jobs_worker', 1)");
        if ($lock !== 1) {
            return null;
        }

        try {
            $conn->beginTransaction();
            $job = null;
            try {
                $job = $conn->fetchAssociative(
                    "SELECT *
                       FROM data_pool_jobs
                      WHERE status = 'queued'
                        AND (next_run_at IS NULL OR next_run_at <= NOW())
                   ORDER BY id ASC
                      LIMIT 1
                      FOR UPDATE SKIP LOCKED"
                );
            } catch (\Throwable) {
                $job = $conn->fetchAssociative(
                    "SELECT *
                       FROM data_pool_jobs
                      WHERE status = 'queued'
                        AND (next_run_at IS NULL OR next_run_at <= NOW())
                   ORDER BY id ASC
                      LIMIT 1"
                );
            }

            if (!$job) {
                $conn->commit();
                return null;
            }

            $jobId = (int) ($job['id'] ?? 0);
            $updated = $conn->executeStatement(
                "UPDATE data_pool_jobs
                    SET status = 'running',
                        locked_by = ?,
                        worker_id = ?,
                        locked_at = NOW(),
                        heartbeat_at = NOW(),
                        started_at = COALESCE(started_at, NOW()),
                        next_run_at = NULL,
                        pause_requested = 0,
                        attempts = attempts + 1,
                        status_message = 'Worker tarafından claim edildi.',
                        updated_at = NOW()
                  WHERE id = ?
                    AND status = 'queued'",
                [$workerId, $workerId, $jobId]
            );
            if ($updated < 1) {
                $conn->rollBack();
                return null;
            }
            $fresh = $conn->fetchAssociative('SELECT * FROM data_pool_jobs WHERE id = ?', [$jobId]);
            $conn->commit();

            return $fresh ?: null;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        } finally {
            $conn->executeQuery("SELECT RELEASE_LOCK('data_pool_jobs_worker')");
        }
    }

    /**
     * @return array<string, int|float|string>
     */
    private function processAnalyzePool(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $total = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$poolId]);
        if ($total < 1) {
            $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?', [$poolId]);
        }
        $processed = 0;
        $lastId = 0;
        $gmail = 0;
        $nonGmail = 0;
        $invalid = 0;

        $typos = [
            'gmial.com','gamil.com','gmai.com','gmail.co','gmail.con','gmal.com',
            'gmaill.com','gml.com','gnail.com','gmaiil.com','gmail.cm','gmail.om','gmail.com.tr','gmail.coom','gmail.comm',
        ];

        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email, domain
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                  ORDER BY id ASC
                  LIMIT $batchSize",
                [$poolId, $lastId]
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) ($row['id'] ?? $lastId);
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));
                if ($domain === '' && str_contains($email, '@')) {
                    [, $domain] = explode('@', $email, 2);
                    $domain = strtolower(trim($domain));
                }
                if ($domain === 'gmail.com') {
                    $gmail++;
                } else {
                    $nonGmail++;
                }
                if (in_array($domain, $typos, true)) {
                    $invalid++;
                }
                $processed++;
            }

            $jobService->updateProgress($jobId, $processed, $total, $processed, 0, [
                'current_step' => 'analyze_batch',
                'message' => sprintf('%d / %d kayıt analiz edildi', $processed, max(1, $total)),
                'last_processed_id' => $lastId,
                'cursor_payload' => ['last_id' => $lastId],
            ]);
        }

        $duplicateCount = (int) $conn->fetchOne(
            'SELECT COALESCE(SUM(t.cnt - 1), 0)
               FROM (
                    SELECT COUNT(*) AS cnt
                      FROM email_data_pool
                     WHERE pool_list_id = ?
                     GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                    HAVING COUNT(*) > 1
               ) t',
            [$poolId]
        );

        $ratio = $total > 0 ? round(($gmail / max(1, $total)) * 100, 2) : 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'INSERT INTO email_data_pool_analysis_cache
                (list_id, total_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, deletable_count, gmail_ratio, target_limit, over_limit_count, missing_count, normalized_preview, non_gmail_preview, last_analyzed_at, status, error_message, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NULL, ?, ?)
             ON DUPLICATE KEY UPDATE
                total_count = VALUES(total_count),
                gmail_count = VALUES(gmail_count),
                non_gmail_count = VALUES(non_gmail_count),
                invalid_gmail_count = VALUES(invalid_gmail_count),
                duplicate_count = VALUES(duplicate_count),
                deletable_count = VALUES(deletable_count),
                gmail_ratio = VALUES(gmail_ratio),
                target_limit = VALUES(target_limit),
                last_analyzed_at = VALUES(last_analyzed_at),
                status = VALUES(status),
                error_message = NULL,
                updated_at = VALUES(updated_at)',
            [$poolId, $total, $gmail, $nonGmail, $invalid, $duplicateCount, $nonGmail + $duplicateCount, $ratio, (int) ($_ENV['EMAIL_POOL_DEFAULT_TARGET_LIMIT'] ?? 250000), '[]', '[]', $now, 'completed', $now, $now]
        );

        return [
            'processed_count' => $processed,
            'success_count' => $processed,
            'failed_count' => 0,
            'gmail_count' => $gmail,
            'non_gmail_count' => $nonGmail,
            'invalid_gmail_count' => $invalid,
            'duplicate_count' => $duplicateCount,
            'gmail_ratio' => $ratio,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processRemoveNonGmail(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $totalToDelete = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ? AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'",
            [$poolId]
        );

        $deleted = 0;
        while (true) {
            $ids = $conn->fetchFirstColumn(
                "SELECT id
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
                  ORDER BY id ASC
                  LIMIT $batchSize",
                [$poolId]
            );
            if ($ids === []) {
                break;
            }
            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $deleted += $conn->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)",
                array_merge([$poolId], $ids)
            );
            $jobService->updateProgress($jobId, $deleted, $totalToDelete, $deleted, 0);
        }

        $this->refreshListCounts($conn, $poolId);

        return [
            'processed_count' => $deleted,
            'success_count' => $deleted,
            'failed_count' => 0,
            'deleted_count' => $deleted,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processRemoveDuplicates(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $totalToDelete = (int) $conn->fetchOne(
            'SELECT COALESCE(SUM(t.cnt - 1), 0)
               FROM (
                    SELECT COUNT(*) AS cnt
                      FROM email_data_pool
                     WHERE pool_list_id = ?
                     GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                    HAVING COUNT(*) > 1
               ) t',
            [$poolId]
        );

        if ($totalToDelete < 1) {
            return [
                'processed_count' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'deleted_count' => 0,
            ];
        }

        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_pool_dup_ids');
        $conn->executeStatement('CREATE TEMPORARY TABLE tmp_pool_dup_ids (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $conn->executeStatement(
            "INSERT INTO tmp_pool_dup_ids (id)
             SELECT p.id
               FROM email_data_pool p
               JOIN (
                    SELECT MIN(id) AS keep_id, COALESCE(normalized_email, LOWER(TRIM(email))) AS norm
                      FROM email_data_pool
                     WHERE pool_list_id = ?
                     GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                    HAVING COUNT(*) > 1
               ) k ON k.norm = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
              WHERE p.pool_list_id = ?
                AND p.id <> k.keep_id",
            [$poolId, $poolId]
        );

        $deleted = 0;
        $safeBatch = max(1000, min(50000, $batchSize));
        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            $ids = $conn->fetchFirstColumn("SELECT id FROM tmp_pool_dup_ids ORDER BY id ASC LIMIT {$safeBatch}");
            if ($ids === []) {
                break;
            }

            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $deleted += $this->executeNamedSql(
                $conn,
                'remove_duplicates_delete_source_batch',
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)",
                array_merge([$poolId], $ids)
            );
            $this->executeNamedSql($conn, 'remove_duplicates_delete_temp_batch', "DELETE FROM tmp_pool_dup_ids WHERE id IN ($in)", $ids);
            $jobService->updateProgress($jobId, $deleted, $totalToDelete, $deleted, 0, [
                'current_step' => 'remove_duplicates_batch',
                'message' => sprintf('%d / %d duplicate kayıt temizlendi', $deleted, max(1, $totalToDelete)),
                'last_processed_id' => (int) ($ids[count($ids) - 1] ?? 0),
            ]);
        }

        $this->refreshListCounts($conn, $poolId);

        return [
            'processed_count' => $deleted,
            'success_count' => $deleted,
            'failed_count' => 0,
            'deleted_count' => $deleted,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function processFixGmailTypos(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $poolId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $hasNormCols = $this->hasNormalizationColumns($conn);
        $typos = [
            'gmial.com','gamil.com','gmai.com','gmail.co','gmail.con','gmal.com',
            'gmaill.com','gml.com','gnail.com','gmaiil.com','gmail.cm','gmail.om','gmail.com.tr','gmail.coom','gmail.comm',
        ];
        $inTypos = implode(',', array_fill(0, count($typos), '?'));
        $totalToFix = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool
              WHERE pool_list_id = ?
                AND INSTR(email, '@') > 1
                AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($inTypos)",
            array_merge([$poolId], $typos)
        );

        $processed = 0;
        $fixed = 0;
        $lastId = 0;
        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                    AND INSTR(email, '@') > 1
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($inTypos)
                  ORDER BY id ASC
                  LIMIT $batchSize",
                array_merge([$poolId, $lastId], $typos)
            );
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $lastId = max($lastId, $id);
                if ($id < 1 || !str_contains($email, '@')) {
                    continue;
                }
                [$local] = explode('@', $email, 2);
                if ($local === '') {
                    continue;
                }
                $normalized = $local . '@gmail.com';
                if ($hasNormCols) {
                    $conn->executeStatement(
                        "UPDATE email_data_pool
                            SET email = ?, normalized_email = LOWER(TRIM(?)), domain = 'gmail.com', updated_at = NOW()
                          WHERE id = ? AND pool_list_id = ?",
                        [$normalized, $normalized, $id, $poolId]
                    );
                } else {
                    $conn->executeStatement(
                        "UPDATE email_data_pool
                            SET email = ?, updated_at = NOW()
                          WHERE id = ? AND pool_list_id = ?",
                        [$normalized, $id, $poolId]
                    );
                }
                $fixed++;
                $processed++;
            }
            $jobService->updateProgress($jobId, $processed, $totalToFix, $fixed, 0, [
                'current_step' => 'fix_gmail_typos_batch',
                'message' => sprintf('%d / %d typo düzeltildi', $fixed, max(1, $totalToFix)),
                'last_processed_id' => $lastId,
            ]);
        }

        $this->refreshListCounts($conn, $poolId);

        return [
            'processed_count' => $processed,
            'success_count' => $fixed,
            'failed_count' => 0,
            'fixed_count' => $fixed,
        ];
    }

    private function hasNormalizationColumns(\Doctrine\DBAL\Connection $conn): bool
    {
        try {
            $dbName = (string) $conn->getDatabase();
            $norm = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'email_data_pool', 'normalized_email']
            ) > 0;
            $domain = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, 'email_data_pool', 'domain']
            ) > 0;

            return $norm && $domain;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string>
     */
    private function processExportPool(
        EntityManagerInterface $em,
        EmailDataPoolJobService $jobService,
        int $jobId,
        array $job,
        int $poolId,
        int $batchSize
    ): array {
        $conn = $em->getConnection();
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $scope = (string) ($payload['scope'] ?? 'full_csv');
        $listName = trim((string) ($payload['list_name'] ?? ('list-' . $poolId)));
        $safeList = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $listName) ?: ('list_' . $poolId);
        $timestamp = date('Ymd_His');
        $ext = $scope === 'full_csv' ? 'csv' : 'txt';
        $filename = sprintf('pool_%d_%s_%s.%s', $poolId, $scope, $timestamp, $ext);

        $exportPath = rtrim((string) ($_ENV['DATA_POOL_EXPORT_PATH'] ?? 'storage/exports'), '/');
        $baseDir = dirname(__DIR__, 3) . '/' . ltrim($exportPath, '/');
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('Export klasörü oluşturulamadı: ' . $baseDir);
        }
        $path = $baseDir . '/' . $filename;
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('Export dosyası oluşturulamadı.');
        }

        $total = max(0, (int) ($job['total_count'] ?? 0));
        $processed = 0;
        $written = 0;
        $lastId = 0;
        $limit = max(1000, min(50000, $batchSize));
        $typos = [
            'gmial.com','gamil.com','gmai.com','gmail.co','gmail.con','gmal.com',
            'gmaill.com','gml.com','gnail.com','gmaiil.com','gmail.cm','gmail.om','gmail.com.tr','gmail.coom','gmail.comm',
        ];
        $typoPlaceholders = implode(',', array_fill(0, count($typos), '?'));

        if ($scope === 'full_csv') {
            fputcsv($fp, ['id', 'email', 'name', 'status', 'created_at']);
        }

        try {
            while (true) {
                if ($scope === 'non_gmail') {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT id, email
                           FROM email_data_pool
                          WHERE pool_list_id = ?
                            AND id > ?
                            AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
                          ORDER BY id ASC
                          LIMIT $limit",
                        [$poolId, $lastId]
                    );
                } elseif ($scope === 'typo_gmail') {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT id, email
                           FROM email_data_pool
                          WHERE pool_list_id = ?
                            AND id > ?
                            AND INSTR(email, '@') > 1
                            AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoPlaceholders)
                          ORDER BY id ASC
                          LIMIT $limit",
                        array_merge([$poolId, $lastId], $typos)
                    );
                } else {
                    $rows = $conn->fetchAllAssociative(
                        "SELECT id, email, COALESCE(name, '') AS name, is_active, created_at
                           FROM email_data_pool
                          WHERE pool_list_id = ?
                            AND id > ?
                          ORDER BY id ASC
                          LIMIT $limit",
                        [$poolId, $lastId]
                    );
                }

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $lastId = (int) ($row['id'] ?? $lastId);
                    $email = trim((string) ($row['email'] ?? ''));
                    if ($email === '') {
                        continue;
                    }
                    if ($scope === 'full_csv') {
                        fputcsv($fp, [
                            (int) ($row['id'] ?? 0),
                            $email,
                            (string) ($row['name'] ?? ''),
                            ((int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'passive'),
                            (string) ($row['created_at'] ?? ''),
                        ]);
                    } else {
                        fwrite($fp, $email . PHP_EOL);
                    }
                    $written++;
                }

                $processed += count($rows);
                $jobService->updateProgress($jobId, $processed, max(1, $total), $written, 0);
            }
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($path);
            throw $e;
        }

        fclose($fp);
        $downloadUrl = '/admin/email-data-pool/exports/' . rawurlencode($filename);

        return [
            'processed_count' => $processed,
            'success_count' => $written,
            'failed_count' => 0,
            'download_url' => $downloadUrl,
            'filename' => $filename,
            'scope' => $scope,
            'label' => $safeList,
        ];
    }

    /**
     * @return array<string, int|string|array<mixed>>
     */
    private function processGlobalDeduplicatePreview(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $where = $this->globalActiveWhereClause($conn);
        $progressTotal = 100;
        $jobService->updateProgress($jobId, 5, $progressTotal, 0, 0);

        $totalRows = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool WHERE {$where} AND COALESCE(normalized_email, LOWER(TRIM(email))) <> ''"
        );
        $jobService->updateProgress($jobId, 15, $progressTotal, 0, 0);

        $uniqueEmails = (int) $conn->fetchOne(
            "SELECT COUNT(DISTINCT COALESCE(normalized_email, LOWER(TRIM(email)))) FROM email_data_pool WHERE {$where} AND COALESCE(normalized_email, LOWER(TRIM(email))) <> ''"
        );
        $duplicateRows = max(0, $totalRows - $uniqueEmails);
        $jobService->updateProgress($jobId, 30, $progressTotal, 0, 0);

        // Duplicate norm setini tek seferde üretip sonraki tüm sorgularda reuse ederek preview süresini düşür.
        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_global_dup_norms');
        $conn->executeStatement('CREATE TEMPORARY TABLE tmp_global_dup_norms (norm VARCHAR(320) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $conn->executeStatement(
            "INSERT INTO tmp_global_dup_norms (norm)
             SELECT COALESCE(normalized_email, LOWER(TRIM(email))) AS norm
               FROM email_data_pool
              WHERE {$where}
                AND COALESCE(normalized_email, LOWER(TRIM(email))) <> ''
              GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
             HAVING COUNT(*) > 1"
        );
        $duplicateGroups = (int) $conn->fetchOne('SELECT COUNT(*) FROM tmp_global_dup_norms');
        $jobService->updateProgress($jobId, 55, $progressTotal, 0, 0);

        $topDomains = $conn->fetchAllAssociative(
            "SELECT COALESCE(domain, SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1)) AS domain_name, COUNT(*) AS cnt
               FROM email_data_pool
              WHERE {$where}
                AND COALESCE(normalized_email, LOWER(TRIM(email))) IN (SELECT norm FROM tmp_global_dup_norms)
              GROUP BY domain_name
              ORDER BY cnt DESC
              LIMIT 10"
        );
        $jobService->updateProgress($jobId, 75, $progressTotal, 0, 0);

        $byList = $conn->fetchAllAssociative(
            "SELECT p.pool_list_id, l.name AS list_name, COUNT(*) AS duplicate_rows
               FROM email_data_pool p
               JOIN email_data_pool_lists l ON l.id = p.pool_list_id
              WHERE {$where}
                AND COALESCE(p.normalized_email, LOWER(TRIM(p.email))) IN (SELECT norm FROM tmp_global_dup_norms)
              GROUP BY p.pool_list_id, l.name
              ORDER BY duplicate_rows DESC
              LIMIT 100"
        );
        $jobService->updateProgress($jobId, 90, $progressTotal, 0, 0);

        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_global_dup_norms');
        $jobService->updateProgress($jobId, 100, $progressTotal, 100, 0);

        return [
            'processed_count' => $totalRows,
            'success_count' => $totalRows,
            'failed_count' => 0,
            'total_rows' => $totalRows,
            'unique_emails' => $uniqueEmails,
            'duplicate_groups' => $duplicateGroups,
            'affected_rows' => $duplicateRows,
            'estimated_after_cleanup' => $uniqueEmails,
            'top_domains' => $topDomains,
            'by_list' => $byList,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processGlobalDeduplicateApply(
        EntityManagerInterface $em,
        EmailDataPoolJobService $jobService,
        int $jobId,
        array $job,
        int $batchSize
    ): array {
        $conn = $em->getConnection();
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $strategy = (string) ($payload['strategy'] ?? 'keep_first');
        $mode = (string) ($payload['mode'] ?? 'mark_duplicate');
        $priorityListIds = array_values(array_filter(array_map('intval', (array) ($payload['priority_list_ids'] ?? [])), static fn (int $id): bool => $id > 0));
        $where = $this->globalActiveWhereClause($conn, 'p');
        $hasStatus = $this->hasColumn($conn, 'email_data_pool', 'status');
        $hasIsDuplicate = $this->hasColumn($conn, 'email_data_pool', 'is_duplicate');
        $globalBatchSize = max(5000, (int) ($_ENV['DATA_POOL_GLOBAL_DEDUP_BATCH_SIZE'] ?? $batchSize));
        $groupBatch = max(200, min(5000, (int) ($_ENV['DATA_POOL_GLOBAL_DEDUP_GROUP_BATCH_SIZE'] ?? 1000)));

        $cursorRaw = json_decode((string) ($job['cursor_payload'] ?? ''), true);
        $cursorRaw = is_array($cursorRaw) ? $cursorRaw : [];
        $phase = (string) ($cursorRaw['phase'] ?? 'prepare');
        $stageLastId = (int) ($cursorRaw['stage_last_id'] ?? 0);
        $processed = (int) ($cursorRaw['processed_count'] ?? 0);
        $affected = (int) ($cursorRaw['affected_count'] ?? 0);
        $totalToAffect = (int) ($cursorRaw['total_to_affect'] ?? 0);

        $this->ensureDedupStagingTable($conn);

        if ($phase === 'prepare') {
            $this->assertNotCancelled($jobService, $jobId);
            $jobService->updateProgress($jobId, 0, 100, 0, 0, [
                'current_step' => 'prepare_staging',
                'message' => 'Global duplicate staging hazırlanıyor',
                'cursor_payload' => ['phase' => 'prepare'],
            ]);

            $this->executeNamedSql($conn, 'dedup_staging_clear_for_job', 'DELETE FROM data_pool_dedup_staging WHERE job_id = ?', [$jobId]);
            $activeWhere = $this->globalActiveWhereClause($conn, 'p');
            $insertParams = [$jobId];
            if ($strategy === 'keep_newest') {
                $keepExpr = 'MAX(p.id)';
            } elseif ($strategy === 'keep_priority' && $priorityListIds !== []) {
                $inPriority = implode(',', array_fill(0, count($priorityListIds), '?'));
                $keepExpr = "COALESCE(MIN(CASE WHEN p.pool_list_id IN ($inPriority) THEN p.id END), MIN(p.id))";
                foreach ($priorityListIds as $priorityListId) {
                    $insertParams[] = $priorityListId;
                }
            } else {
                $keepExpr = 'MIN(p.id)';
            }

            $this->executeNamedSql(
                $conn,
                'dedup_staging_insert',
                "INSERT INTO data_pool_dedup_staging (job_id, normalized_email, keep_id, duplicate_count, processed, created_at, updated_at)
                 SELECT ?, d.norm, {$keepExpr} AS keep_id, COUNT(*) - 1 AS duplicate_count, 0, NOW(), NOW()
                   FROM email_data_pool p
                   JOIN (
                        SELECT COALESCE(normalized_email, LOWER(TRIM(email))) AS norm
                          FROM email_data_pool
                         WHERE " . $this->globalActiveWhereClause($conn) . "
                           AND COALESCE(normalized_email, LOWER(TRIM(email))) <> ''
                         GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                        HAVING COUNT(*) > 1
                   ) d ON d.norm = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
                  WHERE {$activeWhere}
                  GROUP BY d.norm",
                $insertParams
            );

            $totalToAffect = (int) ($conn->fetchOne('SELECT COALESCE(SUM(duplicate_count), 0) FROM data_pool_dedup_staging WHERE job_id = ?', [$jobId]) ?? 0);
            $phase = 'apply';
            $stageLastId = 0;
            $processed = 0;
            $affected = 0;

            $jobService->updateProgress($jobId, 0, max(1, $totalToAffect), 0, 0, [
                'current_step' => 'dedup_batch_apply',
                'message' => 'Duplicate işaretleme/silme başladı',
                'cursor_payload' => [
                    'phase' => $phase,
                    'stage_last_id' => $stageLastId,
                    'processed_count' => $processed,
                    'affected_count' => $affected,
                    'total_to_affect' => $totalToAffect,
                ],
            ]);
        }

        if ($phase === 'apply') {
            while (true) {
                $this->assertNotCancelled($jobService, $jobId);
                $stagingRows = $conn->fetchAllAssociative(
                    "SELECT id, normalized_email, keep_id
                       FROM data_pool_dedup_staging
                      WHERE job_id = ?
                        AND processed = 0
                        AND id > ?
                   ORDER BY id ASC
                      LIMIT {$groupBatch}",
                    [$jobId, $stageLastId]
                );
                if ($stagingRows === []) {
                    break;
                }

                foreach ($stagingRows as $stagingRow) {
                    $stageId = (int) ($stagingRow['id'] ?? 0);
                    $norm = (string) ($stagingRow['normalized_email'] ?? '');
                    $keepId = (int) ($stagingRow['keep_id'] ?? 0);
                    $stageLastId = max($stageLastId, $stageId);
                    if ($norm === '' || $keepId < 1) {
                        $this->executeNamedSql($conn, 'dedup_staging_mark_skipped', 'UPDATE data_pool_dedup_staging SET processed = 1, updated_at = NOW() WHERE id = ?', [$stageId]);
                        continue;
                    }

                    $dupIds = $conn->fetchFirstColumn(
                        "SELECT p.id
                           FROM email_data_pool p
                          WHERE {$where}
                            AND COALESCE(p.normalized_email, LOWER(TRIM(p.email))) = ?
                            AND p.id <> ?",
                        [$norm, $keepId]
                    );
                    $dupIds = array_values(array_map('intval', $dupIds));
                    if ($dupIds !== []) {
                        foreach (array_chunk($dupIds, $globalBatchSize) as $chunkIds) {
                            $in = implode(',', array_fill(0, count($chunkIds), '?'));
                            if ($mode === 'delete') {
                                $affected += $this->executeNamedSql($conn, 'global_dedup_delete_chunk', "DELETE FROM email_data_pool WHERE id IN ($in)", $chunkIds);
                            } else {
                                $setParts = ['updated_at = NOW()'];
                                if ($hasIsDuplicate) {
                                    $setParts[] = 'is_duplicate = 1';
                                }
                                if ($hasStatus) {
                                    $setParts[] = "status = 'duplicate'";
                                } else {
                                    $setParts[] = 'is_active = 0';
                                }
                                $setSql = implode(', ', $setParts);
                                $affected += $this->executeNamedSql($conn, 'global_dedup_mark_chunk', "UPDATE email_data_pool SET {$setSql} WHERE id IN ($in)", $chunkIds);
                            }
                            $processed += count($chunkIds);
                        }
                    }

                    $this->executeNamedSql($conn, 'dedup_staging_mark_processed', 'UPDATE data_pool_dedup_staging SET processed = 1, updated_at = NOW() WHERE id = ?', [$stageId]);
                    $jobService->updateProgress($jobId, $processed, max(1, $totalToAffect), $affected, 0, [
                        'current_step' => 'dedup_batch_apply',
                        'message' => sprintf('%d / %d duplicate kayıt işlendi', $processed, max(1, $totalToAffect)),
                        'last_processed_id' => $stageLastId,
                        'cursor_payload' => [
                            'phase' => 'apply',
                            'stage_last_id' => $stageLastId,
                            'processed_count' => $processed,
                            'affected_count' => $affected,
                            'total_to_affect' => $totalToAffect,
                        ],
                    ]);
                }
            }

            $phase = 'finalize';
        }

        $this->refreshAllListCounts($conn);
        $remainingGroups = (int) ($conn->fetchOne(
            "SELECT COUNT(*)
               FROM (
                    SELECT 1
                      FROM email_data_pool
                     WHERE " . $this->globalActiveWhereClause($conn) . "
                       AND COALESCE(normalized_email, LOWER(TRIM(email))) <> ''
                     GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                    HAVING COUNT(*) > 1
               ) t"
        ) ?? 0);
        $reportUrl = $this->writeGlobalDedupReport([
            'mode' => $mode,
            'strategy' => $strategy,
            'priority_list_ids' => $priorityListIds,
            'affected_rows' => $affected,
            'remaining_duplicate_groups' => $remainingGroups,
            'processed_count' => $processed,
            'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $this->executeNamedSql($conn, 'dedup_staging_cleanup_job_rows', 'DELETE FROM data_pool_dedup_staging WHERE job_id = ?', [$jobId]);

        return [
            'processed_count' => $processed,
            'success_count' => $affected,
            'failed_count' => 0,
            'affected_rows' => $affected,
            'removed_count' => $mode === 'delete' ? $affected : 0,
            'duplicate_groups' => $remainingGroups,
            'total_rows' => (int) ($job['total_count'] ?? 0),
            'unique_emails' => max(0, (int) ($job['total_count'] ?? 0) - $remainingGroups),
            'mode' => $mode,
            'strategy' => $strategy,
            'phase' => $phase,
            'report_url' => $reportUrl,
        ];
    }

    private function refreshAllListCounts(\Doctrine\DBAL\Connection $conn): void
    {
        $rows = $conn->fetchAllAssociative('SELECT id FROM email_data_pool_lists');
        foreach ($rows as $row) {
            $listId = (int) ($row['id'] ?? 0);
            if ($listId > 0) {
                $this->refreshListCounts($conn, $listId);
            }
        }
    }

    private function globalActiveWhereClause(\Doctrine\DBAL\Connection $conn, string $alias = ''): string
    {
        $prefix = $alias !== '' ? ($alias . '.') : '';
        if ($this->hasColumn($conn, 'email_data_pool', 'status')) {
            return "{$prefix}status = 'active'";
        }
        return "{$prefix}is_active = 1";
    }

    private function hasColumn(\Doctrine\DBAL\Connection $conn, string $table, string $column): bool
    {
        try {
            $dbName = (string) $conn->getDatabase();
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$dbName, $table, $column]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeGlobalDedupReport(array $payload): string
    {
        $exportPath = rtrim((string) ($_ENV['DATA_POOL_EXPORT_PATH'] ?? 'storage/exports'), '/');
        $baseDir = dirname(__DIR__, 3) . '/' . ltrim($exportPath, '/');
        if (!is_dir($baseDir)) {
            @mkdir($baseDir, 0775, true);
        }
        $filename = 'global_dedup_report_' . date('Ymd_His') . '.json';
        $path = $baseDir . '/' . $filename;
        @file_put_contents($path, (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return '/admin/email-data-pool/exports/' . rawurlencode($filename);
    }

    private function ensureDedupStagingTable(\Doctrine\DBAL\Connection $conn): void
    {
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS data_pool_dedup_staging (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                job_id BIGINT UNSIGNED NOT NULL,
                normalized_email VARCHAR(320) NOT NULL,
                keep_id BIGINT UNSIGNED NOT NULL,
                duplicate_count INT NOT NULL DEFAULT 0,
                processed TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_job_processed (job_id, processed, id),
                INDEX idx_job_email (job_id, normalized_email),
                INDEX idx_keep_id (keep_id),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processCompleteToTarget(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $batchSize): array
    {
        $payload = is_array(json_decode((string) ($job['payload'] ?? ''), true)) ? json_decode((string) ($job['payload'] ?? ''), true) : [];
        $payload = is_array($payload) ? $payload : [];
        $payload['mode'] = 'move';
        return $this->processCopyLikeToTarget($em, $jobService, $jobId, $payload, max(5000, $batchSize));
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processCopyToTarget(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $batchSize): array
    {
        $payload = is_array(json_decode((string) ($job['payload'] ?? ''), true)) ? json_decode((string) ($job['payload'] ?? ''), true) : [];
        $payload = is_array($payload) ? $payload : [];
        $payload['mode'] = 'copy';
        return $this->processCopyLikeToTarget($em, $jobService, $jobId, $payload, max(5000, $batchSize));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int|string|array<mixed>>
     */
    private function processCopyLikeToTarget(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $payload, int $batchSize): array
    {
        $conn = $em->getConnection();
        $targetListId = (int) ($payload['target_list_id'] ?? 0);
        $sourceType = (string) ($payload['source_type'] ?? 'list');
        $sourceListId = (int) ($payload['source_list_id'] ?? 0);
        $mode = (string) ($payload['mode'] ?? 'copy');
        $targetCount = max(1, (int) ($payload['target_count'] ?? 0));
        $removeDuplicates = ((int) ($payload['remove_duplicates'] ?? 1)) === 1;
        if ($targetListId < 1) {
            throw new \RuntimeException('Hedef liste bulunamadı.');
        }
        if ($sourceType === 'list' && $sourceListId < 1) {
            throw new \RuntimeException('Kaynak liste bulunamadı.');
        }

        $currentTarget = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$targetListId]);
        $need = max(0, $targetCount - $currentTarget);
        $total = max(0, $need);
        if ($need < 1) {
            return [
                'processed_count' => 0,
                'success_count' => 0,
                'failed_count' => 0,
                'operation_type' => 'complete_to_target',
                'message' => 'Hedef liste zaten dolu.',
            ];
        }

        $inserted = 0;
        $moved = 0;
        $processed = 0;
        $lastId = (int) ($conn->fetchOne('SELECT COALESCE(last_processed_id, 0) FROM data_pool_jobs WHERE id = ?', [$jobId]) ?? 0);
        $safeBatch = max(1000, min(100000, (int) ($_ENV['DATA_POOL_BALANCE_BATCH_SIZE'] ?? $batchSize)));

        while ($inserted < $need) {
            $this->assertNotCancelled($jobService, $jobId);
            $limit = min($safeBatch, $need - $inserted);
            $rows = [];
            if ($sourceType === 'list') {
                $rows = $conn->fetchAllAssociative(
                    "SELECT id, email, COALESCE(name, '') AS name, COALESCE(normalized_email, LOWER(TRIM(email))) AS norm
                       FROM email_data_pool
                      WHERE pool_list_id = ?
                        AND id > ?
                      ORDER BY id ASC
                      LIMIT $limit",
                    [$sourceListId, $lastId]
                );
            } else {
                $raw = (string) ($payload['source_payload'] ?? '');
                $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
                $slice = array_slice($lines, $processed, $limit);
                foreach ($slice as $line) {
                    $mail = strtolower(trim((string) $line));
                    if ($mail === '' || !str_contains($mail, '@')) {
                        continue;
                    }
                    $rows[] = ['id' => 0, 'email' => $mail, 'name' => '', 'norm' => $mail];
                }
            }

            if ($rows === []) {
                break;
            }
            $lastId = max($lastId, (int) ($rows[count($rows) - 1]['id'] ?? $lastId));
            $processed += count($rows);
            $insertValues = [];
            $insertParams = [];
            $moveIds = [];
            $norms = array_values(array_unique(array_filter(array_map(static fn (array $r): string => (string) ($r['norm'] ?? ''), $rows))));
            $existing = [];
            if ($removeDuplicates && $norms !== []) {
                foreach (array_chunk($norms, 1500) as $chunk) {
                    $in = implode(',', array_fill(0, count($chunk), '?'));
                    $found = $conn->fetchFirstColumn(
                        "SELECT COALESCE(normalized_email, LOWER(TRIM(email))) FROM email_data_pool WHERE pool_list_id = ? AND COALESCE(normalized_email, LOWER(TRIM(email))) IN ($in)",
                        array_merge([$targetListId], $chunk)
                    );
                    foreach ($found as $f) {
                        $existing[(string) $f] = true;
                    }
                }
            }
            foreach ($rows as $row) {
                $norm = (string) ($row['norm'] ?? '');
                if ($norm === '') {
                    continue;
                }
                if ($removeDuplicates && isset($existing[$norm])) {
                    continue;
                }
                $email = (string) ($row['email'] ?? '');
                $name = (string) ($row['name'] ?? '');
                $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
                $isGmail = $domain === 'gmail.com' ? 1 : 0;
                $insertValues[] = '(?, ?, ?, ?, ?, ?, 0, 0, 1, NOW(), NOW())';
                $insertParams[] = $targetListId;
                $insertParams[] = $email;
                $insertParams[] = $norm;
                $insertParams[] = $domain;
                $insertParams[] = $name;
                $insertParams[] = $isGmail;
                $existing[$norm] = true;
                if ($sourceType === 'list' && $mode === 'move' && (int) ($row['id'] ?? 0) > 0) {
                    $moveIds[] = (int) $row['id'];
                }
            }
            if ($insertValues !== []) {
                $sql = 'INSERT INTO email_data_pool (pool_list_id, email, normalized_email, domain, name, is_gmail, is_duplicate, is_invalid, is_active, created_at, updated_at) VALUES ' . implode(',', $insertValues);
                $insertedNow = $this->executeNamedSql($conn, 'copy_like_insert_batch', $sql, $insertParams);
                $inserted += $insertedNow;
            }
            if ($moveIds !== []) {
                $in = implode(',', array_fill(0, count($moveIds), '?'));
                $this->executeNamedSql($conn, 'copy_like_move_delete_source_batch', "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)", array_merge([$sourceListId], $moveIds));
                $moved += count($moveIds);
            }
            $jobService->updateProgress($jobId, $inserted, max(1, $total), $inserted, 0, [
                'current_step' => 'copy_batch',
                'message' => sprintf('%d / %d kayıt işlendi', $inserted, max(1, $total)),
                'last_processed_id' => $lastId,
                'cursor_payload' => ['last_id' => $lastId, 'inserted' => $inserted],
            ]);
            if ($sourceType !== 'list' && $processed >= count(preg_split('/\r\n|\r|\n/', (string) ($payload['source_payload'] ?? '')) ?: [])) {
                break;
            }
        }

        $this->refreshListCounts($conn, $targetListId);
        if ($sourceType === 'list' && $sourceListId > 0) {
            $this->refreshListCounts($conn, $sourceListId);
        }

        return [
            'processed_count' => $inserted,
            'success_count' => $inserted,
            'failed_count' => 0,
            'operation_type' => $mode === 'move' ? 'complete_to_target' : 'copy_to_target',
            'added_records' => $inserted,
            'moved_records' => $moved,
            'deleted_records' => $moved,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processMoveOverflowBalance(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $batchSize): array
    {
        $conn = $em->getConnection();
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $sourceListId = (int) ($payload['source_list_id'] ?? 0);
        $targetListId = (int) ($payload['target_list_id'] ?? 0);
        $targetCount = max(1, (int) ($payload['target_count'] ?? 0));
        if ($sourceListId < 1 || $targetListId < 1) {
            throw new \RuntimeException('Kaynak/hedef liste bilgisi eksik.');
        }
        $sourceCurrent = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$sourceListId]);
        $overflow = max(0, $sourceCurrent - $targetCount);
        if ($overflow < 1) {
            return ['processed_count' => 0, 'success_count' => 0, 'failed_count' => 0, 'operation_type' => 'move_overflow', 'message' => 'Fazla kayıt yok.'];
        }

        $moved = 0;
        $safeBatch = max(1000, min(100000, (int) ($_ENV['DATA_POOL_BALANCE_BATCH_SIZE'] ?? $batchSize)));
        while ($moved < $overflow) {
            $this->assertNotCancelled($jobService, $jobId);
            $limit = min($safeBatch, $overflow - $moved);
            $affected = $conn->executeStatement(
                "UPDATE email_data_pool
                    SET pool_list_id = ?, updated_at = NOW()
                  WHERE pool_list_id = ?
                  ORDER BY id DESC
                  LIMIT $limit",
                [$targetListId, $sourceListId]
            );
            if ($affected < 1) {
                break;
            }
            $moved += $affected;
            $jobService->updateProgress($jobId, $moved, max(1, $overflow), $moved, 0, [
                'current_step' => 'move_overflow_batch',
                'message' => sprintf('%d / %d fazla kayıt taşındı', $moved, max(1, $overflow)),
            ]);
        }
        $this->refreshListCounts($conn, $sourceListId);
        $this->refreshListCounts($conn, $targetListId);

        return [
            'processed_count' => $moved,
            'success_count' => $moved,
            'failed_count' => 0,
            'operation_type' => 'move_overflow',
            'moved_records' => $moved,
            'deleted_records' => $moved,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processSplitPool(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $batchSize): array
    {
        $conn = $em->getConnection();
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $sourceListId = (int) ($payload['source_list_id'] ?? 0);
        $chunkSize = max(1000, (int) ($payload['chunk_size'] ?? 0));
        $prefix = trim((string) ($payload['new_list_prefix'] ?? 'Parca'));
        $mode = (string) ($payload['mode'] ?? 'copy');
        if ($sourceListId < 1 || $chunkSize < 1) {
            throw new \RuntimeException('Split payload geçersiz.');
        }
        $total = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$sourceListId]);
        $processed = 0;
        $createdLists = [];
        $part = 0;
        $lastId = 0;
        $safeBatch = max(1000, min(100000, (int) ($_ENV['DATA_POOL_BALANCE_BATCH_SIZE'] ?? $batchSize)));
        $currentTargetId = 0;
        $currentCount = 0;

        while ($processed < $total) {
            $this->assertNotCancelled($jobService, $jobId);
            if ($currentTargetId < 1 || $currentCount >= $chunkSize) {
                $part++;
                $name = $prefix . ' - ' . $part;
                $conn->executeStatement(
                    'INSERT INTO email_data_pool_lists (name, sort_order, total_count, active_count, passive_count, updated_count_at) VALUES (?, 0, 0, 0, 0, NOW())',
                    [$name]
                );
                $currentTargetId = (int) $conn->lastInsertId();
                $currentCount = 0;
                $createdLists[] = $name;
            }
            $limit = min($safeBatch, $chunkSize - $currentCount);
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email, COALESCE(normalized_email, LOWER(TRIM(email))) AS norm, COALESCE(domain, SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1)) AS domain_name, COALESCE(name, '') AS name
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                  ORDER BY id ASC
                  LIMIT $limit",
                [$sourceListId, $lastId]
            );
            if ($rows === []) {
                break;
            }
            $insertValues = [];
            $insertParams = [];
            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (int) ($row['id'] ?? 0);
                $lastId = max($lastId, (int) ($row['id'] ?? 0));
                $insertValues[] = '(?, ?, ?, ?, ?, ?, 0, 0, 1, NOW(), NOW())';
                $insertParams[] = $currentTargetId;
                $insertParams[] = (string) ($row['email'] ?? '');
                $insertParams[] = (string) ($row['norm'] ?? '');
                $insertParams[] = (string) ($row['domain_name'] ?? '');
                $insertParams[] = (string) ($row['name'] ?? '');
                $insertParams[] = ((string) ($row['domain_name'] ?? '') === 'gmail.com') ? 1 : 0;
            }
            if ($insertValues !== []) {
                $this->executeNamedSql(
                    $conn,
                    'split_pool_insert_batch',
                    'INSERT INTO email_data_pool (pool_list_id, email, normalized_email, domain, name, is_gmail, is_duplicate, is_invalid, is_active, created_at, updated_at) VALUES ' . implode(',', $insertValues),
                    $insertParams
                );
            }
            if ($mode === 'move' && $ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $this->executeNamedSql($conn, 'split_pool_delete_source_batch', "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)", array_merge([$sourceListId], $ids));
            }
            $processed += count($rows);
            $currentCount += count($rows);
            $jobService->updateProgress($jobId, $processed, max(1, $total), $processed, 0, [
                'current_step' => 'split_pool_batch',
                'message' => sprintf('%d / %d kayıt bölündü', $processed, max(1, $total)),
                'last_processed_id' => $lastId,
                'cursor_payload' => ['last_id' => $lastId, 'part' => $part],
            ]);
        }
        $this->refreshListCounts($conn, $sourceListId);
        if ($createdLists !== []) {
            $newIds = $conn->fetchFirstColumn(
                'SELECT id FROM email_data_pool_lists WHERE name IN (' . implode(',', array_fill(0, count($createdLists), '?')) . ')',
                $createdLists
            );
            foreach ($newIds as $newId) {
                $this->refreshListCounts($conn, (int) $newId);
            }
        }
        return [
            'processed_count' => $processed,
            'success_count' => $processed,
            'failed_count' => 0,
            'operation_type' => 'split_pool',
            'target_list' => implode(', ', $createdLists),
            'added_records' => $processed,
            'moved_records' => $mode === 'move' ? $processed : 0,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processBalancePools(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $batchSize): array
    {
        $conn = $em->getConnection();
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $poolIds = array_values(array_filter(array_map('intval', (array) ($payload['pool_ids'] ?? [])), static fn (int $id): bool => $id > 0));
        $targetLimit = max(1, (int) ($payload['target_limit'] ?? 250000));
        if ($poolIds === []) {
            throw new \RuntimeException('Dengelenecek liste yok.');
        }

        $processed = 0;
        $operations = 0;
        foreach ($poolIds as $poolId) {
            $this->assertNotCancelled($jobService, $jobId);
            $current = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$poolId]);
            if ($current > $targetLimit) {
                $overflow = $current - $targetLimit;
                foreach ($poolIds as $targetId) {
                    if ($targetId === $poolId) {
                        continue;
                    }
                    $targetCurrent = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$targetId]);
                    $deficit = max(0, $targetLimit - $targetCurrent);
                    if ($deficit < 1 || $overflow < 1) {
                        continue;
                    }
                    $move = min($overflow, $deficit, max(1000, min(100000, (int) ($_ENV['DATA_POOL_BALANCE_BATCH_SIZE'] ?? $batchSize))));
                    $affected = $conn->executeStatement(
                        "UPDATE email_data_pool SET pool_list_id = ?, updated_at = NOW() WHERE pool_list_id = ? ORDER BY id DESC LIMIT $move",
                        [$targetId, $poolId]
                    );
                    if ($affected > 0) {
                        $overflow -= $affected;
                        $processed += $affected;
                        $operations++;
                    }
                    if ($overflow < 1) {
                        break;
                    }
                }
            }
            $jobService->updateProgress($jobId, $processed, max(1, count($poolIds) * $targetLimit), $processed, 0, [
                'current_step' => 'balance_pool_batch',
                'message' => sprintf('%d kayıt dengelendi', $processed),
                'cursor_payload' => ['current_pool_id' => $poolId, 'operations' => $operations],
            ]);
        }
        foreach ($poolIds as $poolId) {
            $this->refreshListCounts($conn, $poolId);
        }

        return [
            'processed_count' => $processed,
            'success_count' => $processed,
            'failed_count' => 0,
            'operation_type' => 'balance_pools',
            'moved_records' => $processed,
            'operations' => $operations,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processFillNewPool(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $batchSize): array
    {
        return $this->processBalancePools($em, $jobService, $jobId, $job, $batchSize);
    }

    /**
     * @return array<string, int|string|array<mixed>>
     */
    private function processGlobalAnalyzeAllPools(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, int $batchSize): array
    {
        $conn = $em->getConnection();
        $pools = $conn->fetchAllAssociative('SELECT id, name FROM email_data_pool_lists ORDER BY id ASC');
        $totalPools = count($pools);
        $totalRecords = (int) $conn->fetchOne('SELECT COALESCE(SUM(total_count), 0) FROM email_data_pool_lists');
        $processedPools = 0;
        $processedRecords = 0;
        $result = [
            'totalCount' => 0,
            'gmailCount' => 0,
            'nonGmailCount' => 0,
            'invalidCount' => 0,
            'duplicateCount' => 0,
        ];

        foreach ($pools as $pool) {
            $poolId = (int) ($pool['id'] ?? 0);
            if ($poolId < 1) {
                continue;
            }
            $analyzeResult = $this->processAnalyzePool($em, $jobService, $jobId, $poolId, $batchSize);
            $processedPools++;
            $processedRecords += (int) ($analyzeResult['processed_count'] ?? 0);
            $result['totalCount'] += (int) ($analyzeResult['processed_count'] ?? 0);
            $result['gmailCount'] += (int) ($analyzeResult['gmail_count'] ?? 0);
            $result['nonGmailCount'] += (int) ($analyzeResult['non_gmail_count'] ?? 0);
            $result['invalidCount'] += (int) ($analyzeResult['invalid_gmail_count'] ?? 0);
            $result['duplicateCount'] += (int) ($analyzeResult['duplicate_count'] ?? 0);
            $jobService->updateProgress($jobId, $processedRecords, max(1, $totalRecords), $processedRecords, 0);
            $conn->executeStatement(
                'UPDATE data_pool_jobs SET result = ? WHERE id = ?',
                [json_encode(array_merge($result, [
                    'currentPoolId' => $poolId,
                    'currentPoolName' => (string) ($pool['name'] ?? ''),
                    'processedPools' => $processedPools,
                    'totalPools' => $totalPools,
                    'processedRecords' => $processedRecords,
                    'totalRecords' => $totalRecords,
                ]), JSON_UNESCAPED_UNICODE), $jobId]
            );
        }

        return [
            'processed_count' => $processedRecords,
            'success_count' => $processedRecords,
            'failed_count' => 0,
            'type' => 'global_analyze_all_pools',
            'processedPools' => $processedPools,
            'totalPools' => $totalPools,
            'processedRecords' => $processedRecords,
            'totalRecords' => $totalRecords,
            'result' => $result,
        ];
    }

    /**
     * @return array<string, int|string|array<mixed>>
     */
    private function processRefreshAllPoolStats(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId): array
    {
        $conn = $em->getConnection();
        $pools = $conn->fetchAllAssociative('SELECT id, name, total_count FROM email_data_pool_lists ORDER BY id ASC');
        $totalPools = count($pools);
        $processedPools = 0;
        $processedRecords = 0;

        foreach ($pools as $pool) {
            $poolId = (int) ($pool['id'] ?? 0);
            if ($poolId < 1) {
                continue;
            }
            $row = $conn->fetchAssociative(
                "SELECT
                    COUNT(*) AS total_count,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN COALESCE(domain, SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1)) = 'gmail.com' THEN 1 ELSE 0 END) AS gmail_count,
                    SUM(CASE WHEN COALESCE(domain, SUBSTRING_INDEX(LOWER(TRIM(email)), '@', -1)) <> 'gmail.com' THEN 1 ELSE 0 END) AS non_gmail_count,
                    SUM(CASE WHEN is_duplicate = 1 THEN 1 ELSE 0 END) AS duplicate_count
                  FROM email_data_pool
                 WHERE pool_list_id = ?",
                [$poolId]
            ) ?: [];
            $total = (int) ($row['total_count'] ?? 0);
            $active = (int) ($row['active_count'] ?? 0);
            $gmail = (int) ($row['gmail_count'] ?? 0);
            $nonGmail = (int) ($row['non_gmail_count'] ?? 0);
            $duplicate = (int) ($row['duplicate_count'] ?? 0);

            $conn->executeStatement(
                'UPDATE email_data_pool_lists SET total_count = ?, active_count = ?, passive_count = ?, updated_count_at = NOW() WHERE id = ?',
                [$total, $active, max(0, $total - $active), $poolId]
            );
            $conn->executeStatement(
                'INSERT INTO email_pool_stats
                    (pool_id, total_count, active_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?, NULL, NOW(), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    total_count = VALUES(total_count),
                    active_count = VALUES(active_count),
                    gmail_count = VALUES(gmail_count),
                    non_gmail_count = VALUES(non_gmail_count),
                    duplicate_count = VALUES(duplicate_count),
                    updated_at = VALUES(updated_at)',
                [$poolId, $total, $active, $gmail, $nonGmail, $duplicate]
            );

            $processedPools++;
            $processedRecords += $total;
            $jobService->updateProgress($jobId, $processedPools, max(1, $totalPools), $processedPools, 0);
            $conn->executeStatement(
                'UPDATE data_pool_jobs SET result = ? WHERE id = ?',
                [json_encode([
                    'processedPools' => $processedPools,
                    'totalPools' => $totalPools,
                    'processedRecords' => $processedRecords,
                    'currentPoolId' => $poolId,
                    'currentPoolName' => (string) ($pool['name'] ?? ''),
                ], JSON_UNESCAPED_UNICODE), $jobId]
            );
        }

        return [
            'processed_count' => $processedPools,
            'success_count' => $processedPools,
            'failed_count' => 0,
            'type' => 'refresh_all_pool_stats',
            'processedPools' => $processedPools,
            'totalPools' => $totalPools,
            'processedRecords' => $processedRecords,
        ];
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processAlibabaInvalidFetch(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $fetchResult = $this->runAlibabaInvalidFetch($em, $jobService, $jobId, $payload);

        return array_merge($fetchResult, [
            'processed_count' => (int) ($fetchResult['saved_count'] ?? 0),
            'success_count' => (int) ($fetchResult['saved_count'] ?? 0),
            'failed_count' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processAlibabaInvalidMatchPreview(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $match = $this->runAlibabaInvalidMatchPreview($em, $jobService, $jobId, $payload);

        return array_merge($match, [
            'processed_count' => (int) ($match['matched_count'] ?? 0),
            'success_count' => (int) ($match['matched_count'] ?? 0),
            'failed_count' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processAlibabaInvalidCleanApply(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $clean = $this->runAlibabaInvalidClean($em, $jobService, $jobId, $payload);

        return array_merge($clean, [
            'processed_count' => (int) ($clean['cleaned_count'] ?? 0),
            'success_count' => (int) ($clean['cleaned_count'] ?? 0),
            'failed_count' => 0,
        ]);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, int|string|array<mixed>>
     */
    private function processAlibabaInvalidFetchAndClean(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $fetch = $this->runAlibabaInvalidFetch($em, $jobService, $jobId, $payload);
        $match = $this->runAlibabaInvalidMatchPreview($em, $jobService, $jobId, $payload);
        $clean = $this->runAlibabaInvalidClean($em, $jobService, $jobId, $payload);

        return [
            'processed_count' => (int) ($clean['cleaned_count'] ?? 0),
            'success_count' => (int) ($clean['cleaned_count'] ?? 0),
            'failed_count' => 0,
            'fetched_count' => (int) ($fetch['fetched_count'] ?? 0),
            'saved_count' => (int) ($fetch['saved_count'] ?? 0),
            'matched_count' => (int) ($match['matched_count'] ?? 0),
            'cleaned_count' => (int) ($clean['cleaned_count'] ?? 0),
            'retry_count' => (int) ($fetch['retry_count'] ?? 0),
            'next_start' => (string) ($fetch['next_start'] ?? ''),
            'mode' => (string) ($payload['mode'] ?? 'mark_invalid'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function processMaintenancePreview(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $jobService->updateProgress($jobId, 1, 1, 1, 0, [
            'current_step' => 'maintenance_preview',
            'message' => 'Bakım önizlemesi tamamlandı.',
            'cursor_payload' => ['preview' => true],
        ]);

        return [
            'processed_count' => 1,
            'success_count' => 1,
            'failed_count' => 0,
            'preview_payload' => $payload,
        ];
    }

    private function processCleanupEmailOrders(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceOrderCleanup($em, $jobService, $jobId, $job, $maxRuntime, true);
    }

    private function processCleanupEmailOrderDetails(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceOrderCleanup($em, $jobService, $jobId, $job, $maxRuntime, false);
    }

    private function processCleanupEmailRecipients(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceRecipientCleanup($em, $jobService, $jobId, $job, $maxRuntime, false);
    }

    private function processCleanupEmailSendResults(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceRecipientCleanup($em, $jobService, $jobId, $job, $maxRuntime, false);
    }

    private function processArchiveEmailRecipients(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceRecipientCleanup($em, $jobService, $jobId, $job, $maxRuntime, true);
    }

    private function processArchiveEmailSendResults(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceRecipientCleanup($em, $jobService, $jobId, $job, $maxRuntime, true);
    }

    private function processCleanupWorkerBatchResults(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceSimpleTableCleanup($em, $jobService, $jobId, $job, $maxRuntime, 'campaign_batch_metrics', 'created_at');
    }

    private function processCleanupDataPoolJobs(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceSimpleTableCleanup(
            $em,
            $jobService,
            $jobId,
            $job,
            $maxRuntime,
            'data_pool_jobs',
            'created_at',
            "status IN ('completed','failed','cancelled') AND type <> 'cleanup_data_pool_jobs'"
        );
    }

    private function processCleanupSystemLogs(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        $conn = $em->getConnection();
        $payload = $this->parseMaintenancePayload($job);
        $cursor = $payload['cursor'];
        $phase = (string) ($cursor['phase'] ?? 'approval_logs');
        $dateBefore = $payload['date_before'];
        $batchSize = $payload['batch_size'];
        $processed = (int) ($job['processed_count'] ?? 0);
        $success = (int) ($job['success_count'] ?? 0);
        $failed = (int) ($job['failed_count'] ?? 0);
        $total = max(1, (int) ($job['total_count'] ?? 1));
        $startedAt = time();
        $lastApprovalId = (int) ($cursor['last_approval_id'] ?? 0);
        $lastTemplateLogId = (int) ($cursor['last_template_log_id'] ?? 0);

        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            if ($phase === 'approval_logs') {
                $ids = array_map('intval', $conn->fetchFirstColumn(
                    "SELECT id FROM email_order_approval_logs WHERE id > ? AND created_at < ? ORDER BY id ASC LIMIT $batchSize",
                    [$lastApprovalId, $dateBefore . ' 23:59:59']
                ));
                if ($ids === []) {
                    $phase = 'template_logs';
                    continue;
                }
                $lastApprovalId = max($ids);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $deleted = $this->executeNamedSql($conn, 'maintenance_cleanup_approval_logs', "DELETE FROM email_order_approval_logs WHERE id IN ($in)", $ids);
                $processed += count($ids);
                $success += $deleted;
            } else {
                $ids = array_map('intval', $conn->fetchFirstColumn(
                    "SELECT id FROM email_template_test_logs WHERE id > ? AND created_at < ? ORDER BY id ASC LIMIT $batchSize",
                    [$lastTemplateLogId, $dateBefore . ' 23:59:59']
                ));
                if ($ids === []) {
                    break;
                }
                $lastTemplateLogId = max($ids);
                $in = implode(',', array_fill(0, count($ids), '?'));
                $deleted = $this->executeNamedSql($conn, 'maintenance_cleanup_template_logs', "DELETE FROM email_template_test_logs WHERE id IN ($in)", $ids);
                $processed += count($ids);
                $success += $deleted;
            }

            $jobService->updateProgress($jobId, $processed, $total, $success, $failed, [
                'current_step' => 'cleanup_system_logs',
                'message' => sprintf('%d log kaydı işlendi', $processed),
                'last_processed_id' => max($lastApprovalId, $lastTemplateLogId),
                'cursor_payload' => [
                    'phase' => $phase,
                    'last_approval_id' => $lastApprovalId,
                    'last_template_log_id' => $lastTemplateLogId,
                ],
            ]);

            if ($this->maintenanceRuntimeExceeded($startedAt, $maxRuntime)) {
                return [
                    'processed_count' => $processed,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'defer' => true,
                    'defer_message' => 'Sistem log temizliği batch süresi doldu, devam edecek.',
                ];
            }
        }

        return ['processed_count' => $processed, 'success_count' => $success, 'failed_count' => $failed];
    }

    private function processCleanupExportFiles(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceFileCleanup($jobService, $jobId, $job, $maxRuntime, 'storage/exports');
    }

    private function processCleanupTempFiles(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        return $this->runMaintenanceFileCleanup($jobService, $jobId, $job, $maxRuntime, 'storage/tmp');
    }

    private function processDatabaseOptimizeTables(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime): array
    {
        $payload = $this->parseMaintenancePayload($job);
        $conn = $em->getConnection();
        $cursor = $payload['cursor'];
        $tables = $payload['tables'] === [] ? ['email_orders', 'email_order_emails', 'data_pool_jobs'] : $payload['tables'];
        $index = max(0, (int) ($cursor['table_index'] ?? 0));
        $processed = (int) ($job['processed_count'] ?? 0);
        $success = (int) ($job['success_count'] ?? 0);
        $failed = (int) ($job['failed_count'] ?? 0);
        $total = max(1, count($tables));
        $startedAt = time();

        while ($index < count($tables)) {
            $this->assertNotCancelled($jobService, $jobId);
            $table = (string) $tables[$index];
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                $failed++;
                $processed++;
                $index++;
                continue;
            }
            $this->executeNamedSql($conn, 'maintenance_optimize_table', "OPTIMIZE TABLE `$table`");
            $processed++;
            $success++;
            $index++;
            $jobService->updateProgress($jobId, $processed, $total, $success, $failed, [
                'current_step' => 'database_optimize_tables',
                'message' => sprintf('%s optimize edildi', $table),
                'last_processed_id' => $index,
                'cursor_payload' => ['table_index' => $index, 'tables' => $tables],
            ]);

            if ($this->maintenanceRuntimeExceeded($startedAt, $maxRuntime) && $index < count($tables)) {
                return [
                    'processed_count' => $processed,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'optimized_tables' => $success,
                    'defer' => true,
                    'defer_message' => 'Tablo optimizasyonu batch süresi doldu, devam edecek.',
                ];
            }
        }

        return ['processed_count' => $processed, 'success_count' => $success, 'failed_count' => $failed, 'optimized_tables' => $success];
    }

    /**
     * @return array<string, mixed>
     */
    private function runMaintenanceOrderCleanup(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime, bool $allowHardDelete): array
    {
        $result = $this->runMaintenanceRecipientCleanup($em, $jobService, $jobId, $job, $maxRuntime, false, true);
        if ((bool) ($result['defer'] ?? false)) {
            return $result;
        }

        $payload = $this->parseMaintenancePayload($job);
        $mode = $payload['mode'];
        $conn = $em->getConnection();
        $cursor = $payload['cursor'];
        $lastOrderId = (int) ($cursor['last_order_id'] ?? 0);
        $statuses = $payload['statuses'];
        $dateBefore = $payload['date_before'];
        $batchSize = $payload['batch_size'];
        $processed = (int) ($result['processed_count'] ?? 0);
        $success = (int) ($result['success_count'] ?? 0);
        $failed = (int) ($result['failed_count'] ?? 0);
        $total = max(1, (int) ($job['total_count'] ?? 1));
        $startedAt = time();
        $statusIn = implode(',', array_fill(0, count($statuses), '?'));

        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            $rows = $conn->fetchAllAssociative(
                "SELECT id FROM email_orders
                  WHERE id > ?
                    AND status IN ($statusIn)
                    AND created_at < ?
                  ORDER BY id ASC
                  LIMIT $batchSize",
                array_merge([$lastOrderId], $statuses, [$dateBefore . ' 23:59:59'])
            );
            if ($rows === []) {
                break;
            }
            $orderIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows), static fn (int $v): bool => $v > 0));
            if ($orderIds === []) {
                break;
            }
            $lastOrderId = max($orderIds);
            $in = implode(',', array_fill(0, count($orderIds), '?'));
            if ($allowHardDelete && $mode === 'hard_delete') {
                $deleted = $this->executeNamedSql($conn, 'maintenance_delete_orders', "DELETE FROM email_orders WHERE id IN ($in)", $orderIds);
                $success += $deleted;
            } else {
                $params = array_merge([$mode, $jobId], $orderIds);
                $this->executeNamedSql(
                    $conn,
                    'maintenance_mark_orders_purged',
                    "UPDATE email_orders
                        SET details_purged_at = COALESCE(details_purged_at, NOW()),
                            purge_summary = JSON_OBJECT('mode', ?, 'job_id', ?, 'updated_at', NOW())
                      WHERE id IN ($in)",
                    $params
                );
                $success += count($orderIds);
            }
            $processed += count($orderIds);
            $jobService->updateProgress($jobId, $processed, $total, $success, $failed, [
                'current_step' => 'cleanup_email_orders',
                'message' => sprintf('%d order işlendi', $processed),
                'last_processed_id' => $lastOrderId,
                'cursor_payload' => ['last_order_id' => $lastOrderId, 'last_recipient_id' => (int) ($payload['cursor']['last_recipient_id'] ?? 0)],
            ]);

            if ($this->maintenanceRuntimeExceeded($startedAt, $maxRuntime)) {
                return [
                    'processed_count' => $processed,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'defer' => true,
                    'defer_message' => 'Email orders bakımı batch süresi doldu, devam edecek.',
                ];
            }
        }

        return ['processed_count' => $processed, 'success_count' => $success, 'failed_count' => $failed];
    }

    /**
     * @return array<string, mixed>
     */
    private function runMaintenanceRecipientCleanup(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime, bool $forceArchive = false, bool $includeOrderTouch = false): array
    {
        $conn = $em->getConnection();
        $this->ensureMaintenanceTables($conn);
        $payload = $this->parseMaintenancePayload($job);
        $cursor = $payload['cursor'];
        $lastRecipientId = (int) ($cursor['last_recipient_id'] ?? ($job['last_processed_id'] ?? 0));
        $statuses = $payload['statuses'];
        $dateBefore = $payload['date_before'];
        $batchSize = $payload['batch_size'];
        $mode = $forceArchive ? 'archive' : $payload['mode'];
        $processed = (int) ($job['processed_count'] ?? 0);
        $success = (int) ($job['success_count'] ?? 0);
        $failed = (int) ($job['failed_count'] ?? 0);
        $prevResult = json_decode((string) ($job['result'] ?? ''), true);
        $archived = (int) ((is_array($prevResult) ? ($prevResult['archived_count'] ?? 0) : 0));
        $total = max(1, (int) ($job['total_count'] ?? 1));
        $startedAt = time();
        $statusIn = implode(',', array_fill(0, count($statuses), '?'));

        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            $rows = $conn->fetchAllAssociative(
                "SELECT e.id, e.order_id
                   FROM email_order_emails e
             INNER JOIN email_orders o ON o.id = e.order_id
                  WHERE e.id > ?
                    AND o.status IN ($statusIn)
                    AND o.created_at < ?
                  ORDER BY e.id ASC
                  LIMIT $batchSize",
                array_merge([$lastRecipientId], $statuses, [$dateBefore . ' 23:59:59'])
            );
            if ($rows === []) {
                break;
            }
            $ids = [];
            $orderIds = [];
            foreach ($rows as $row) {
                $rid = (int) ($row['id'] ?? 0);
                if ($rid > 0) {
                    $ids[] = $rid;
                    $lastRecipientId = max($lastRecipientId, $rid);
                }
                $oid = (int) ($row['order_id'] ?? 0);
                if ($oid > 0) {
                    $orderIds[$oid] = $oid;
                }
            }
            if ($ids === []) {
                break;
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($mode === 'archive') {
                $archived += $this->executeNamedSql($conn, 'maintenance_archive_order_emails', "INSERT IGNORE INTO email_order_emails_archive SELECT * FROM email_order_emails WHERE id IN ($in)", $ids);
            }
            $deleted = $this->executeNamedSql($conn, 'maintenance_delete_order_emails', "DELETE FROM email_order_emails WHERE id IN ($in)", $ids);
            $processed += count($ids);
            $success += $deleted;

            if ($includeOrderTouch && $orderIds !== []) {
                $oidList = array_values($orderIds);
                $oin = implode(',', array_fill(0, count($oidList), '?'));
                $this->executeNamedSql(
                    $conn,
                    'maintenance_touch_orders',
                    "UPDATE email_orders
                        SET details_purged_at = COALESCE(details_purged_at, NOW()),
                            purge_summary = JSON_OBJECT('mode', ?, 'job_id', ?, 'updated_at', NOW())
                      WHERE id IN ($oin)",
                    array_merge([$mode, $jobId], $oidList)
                );
            }

            $jobService->updateProgress($jobId, $processed, $total, $success, $failed, [
                'current_step' => 'cleanup_email_order_emails',
                'message' => sprintf('%d recipient/result satırı işlendi', $processed),
                'last_processed_id' => $lastRecipientId,
                'cursor_payload' => [
                    'last_recipient_id' => $lastRecipientId,
                    'last_order_id' => (int) ($cursor['last_order_id'] ?? 0),
                ],
            ]);

            if ($this->maintenanceRuntimeExceeded($startedAt, $maxRuntime)) {
                return [
                    'processed_count' => $processed,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'archived_count' => $archived,
                    'cleaned_count' => $success,
                    'defer' => true,
                    'defer_message' => 'Recipient/result bakımı batch süresi doldu, devam edecek.',
                ];
            }
        }

        return [
            'processed_count' => $processed,
            'success_count' => $success,
            'failed_count' => $failed,
            'archived_count' => $archived,
            'cleaned_count' => $success,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runMaintenanceSimpleTableCleanup(
        EntityManagerInterface $em,
        EmailDataPoolJobService $jobService,
        int $jobId,
        array $job,
        int $maxRuntime,
        string $table,
        string $dateColumn,
        string $extraWhere = '1=1'
    ): array {
        $conn = $em->getConnection();
        $payload = $this->parseMaintenancePayload($job);
        $cursor = $payload['cursor'];
        $lastId = (int) ($cursor['last_id'] ?? ($job['last_processed_id'] ?? 0));
        $dateBefore = $payload['date_before'];
        $batchSize = $payload['batch_size'];
        $processed = (int) ($job['processed_count'] ?? 0);
        $success = (int) ($job['success_count'] ?? 0);
        $failed = (int) ($job['failed_count'] ?? 0);
        $total = max(1, (int) ($job['total_count'] ?? 1));
        $startedAt = time();

        while (true) {
            $this->assertNotCancelled($jobService, $jobId);
            $ids = array_map('intval', $conn->fetchFirstColumn(
                "SELECT id FROM {$table}
                  WHERE id > ?
                    AND {$dateColumn} < ?
                    AND {$extraWhere}
                  ORDER BY id ASC
                  LIMIT {$batchSize}",
                [$lastId, $dateBefore . ' 23:59:59']
            ));
            if ($ids === []) {
                break;
            }
            $lastId = max($ids);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $deleted = $this->executeNamedSql($conn, 'maintenance_simple_cleanup', "DELETE FROM {$table} WHERE id IN ($in)", $ids);
            $processed += count($ids);
            $success += $deleted;

            $jobService->updateProgress($jobId, $processed, $total, $success, $failed, [
                'current_step' => 'cleanup_' . $table,
                'message' => sprintf('%s tablosunda %d kayıt işlendi', $table, $processed),
                'last_processed_id' => $lastId,
                'cursor_payload' => ['last_id' => $lastId],
            ]);
            if ($this->maintenanceRuntimeExceeded($startedAt, $maxRuntime)) {
                return [
                    'processed_count' => $processed,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'defer' => true,
                    'defer_message' => sprintf('%s temizliği batch süresi doldu, devam edecek.', $table),
                ];
            }
        }

        return ['processed_count' => $processed, 'success_count' => $success, 'failed_count' => $failed];
    }

    /**
     * @return array<string, mixed>
     */
    private function runMaintenanceFileCleanup(EmailDataPoolJobService $jobService, int $jobId, array $job, int $maxRuntime, string $relativePath): array
    {
        $payload = $this->parseMaintenancePayload($job);
        $cursor = $payload['cursor'];
        $index = max(0, (int) ($cursor['file_index'] ?? 0));
        $dateBeforeTs = strtotime($payload['date_before'] . ' 23:59:59') ?: time();
        $root = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . trim($relativePath, '/');
        $files = [];
        if (is_dir($root)) {
            $items = scandir($root) ?: [];
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . $item;
                if (!is_file($path)) {
                    continue;
                }
                $mtime = filemtime($path) ?: 0;
                if ($mtime > 0 && $mtime <= $dateBeforeTs) {
                    $files[] = $path;
                }
            }
        }
        sort($files);
        $processed = (int) ($job['processed_count'] ?? 0);
        $success = (int) ($job['success_count'] ?? 0);
        $failed = (int) ($job['failed_count'] ?? 0);
        $total = max(1, count($files));
        $startedAt = time();

        while ($index < count($files)) {
            $this->assertNotCancelled($jobService, $jobId);
            $path = (string) $files[$index];
            $ok = @unlink($path);
            $processed++;
            if ($ok) {
                $success++;
            } else {
                $failed++;
            }
            $index++;
            $jobService->updateProgress($jobId, $processed, $total, $success, $failed, [
                'current_step' => 'cleanup_files',
                'message' => sprintf('%d/%d dosya işlendi', $processed, $total),
                'last_processed_id' => $index,
                'cursor_payload' => ['file_index' => $index],
            ]);
            if ($this->maintenanceRuntimeExceeded($startedAt, $maxRuntime) && $index < count($files)) {
                return [
                    'processed_count' => $processed,
                    'success_count' => $success,
                    'failed_count' => $failed,
                    'defer' => true,
                    'defer_message' => 'Dosya temizliği batch süresi doldu, devam edecek.',
                ];
            }
        }

        return ['processed_count' => $processed, 'success_count' => $success, 'failed_count' => $failed];
    }

    /**
     * @return array{mode:string,date_before:string,batch_size:int,statuses:array<int,string>,cursor:array<string,mixed>,tables:array<int,string>}
     */
    private function parseMaintenancePayload(array $job): array
    {
        $payload = json_decode((string) ($job['payload'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $cursor = json_decode((string) ($job['cursor_payload'] ?? ''), true);
        $cursor = is_array($cursor) ? $cursor : (is_array($payload['cursor'] ?? null) ? $payload['cursor'] : []);
        $statuses = $payload['statuses'] ?? ['completed', 'failed', 'cancelled', 'rejected'];
        if (!is_array($statuses) || $statuses === []) {
            $statuses = ['completed', 'failed', 'cancelled', 'rejected'];
        }
        $tables = $payload['tables'] ?? $payload['table_names'] ?? [];
        if (is_string($tables)) {
            $tables = array_values(array_filter(array_map('trim', explode(',', $tables))));
        }
        if (!is_array($tables)) {
            $tables = [];
        }

        return [
            'mode' => (string) ($payload['mode'] ?? 'purge_details_keep_summary'),
            'date_before' => (string) ($payload['date_before'] ?? (new \DateTimeImmutable('-90 days'))->format('Y-m-d')),
            'batch_size' => max(1000, (int) ($payload['batch_size'] ?? ($_ENV['MAINTENANCE_BATCH_SIZE'] ?? 50000))),
            'statuses' => array_values(array_map(static fn ($s): string => (string) $s, $statuses)),
            'cursor' => $cursor,
            'tables' => array_values(array_map(static fn ($t): string => (string) $t, $tables)),
        ];
    }

    private function maintenanceRuntimeExceeded(int $startedAt, int $maxRuntime): bool
    {
        return (time() - $startedAt) >= max(60, $maxRuntime);
    }

    private function ensureMaintenanceTables(\Doctrine\DBAL\Connection $conn): void
    {
        $conn->executeStatement("CREATE TABLE IF NOT EXISTS email_order_emails_archive LIKE email_order_emails");
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int|string>
     */
    private function runAlibabaInvalidFetch(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $payload): array
    {
        $conn = $em->getConnection();
        $this->ensureAlibabaInvalidTables($conn);

        $endpoint = rtrim((string) ($_ENV['ALIBABA_DM_ENDPOINT'] ?? 'https://dm.aliyuncs.com/'), '/') . '/';
        $accessKeyId = trim((string) ($_ENV['ALIBABA_DM_ACCESS_KEY_ID'] ?? ''));
        $accessKeySecret = trim((string) ($_ENV['ALIBABA_DM_ACCESS_KEY_SECRET'] ?? ''));
        if ($accessKeyId === '') {
            throw new \RuntimeException('Alibaba AccessKey tanımlı değil.');
        }
        if ($accessKeySecret === '') {
            throw new \RuntimeException('Alibaba AccessKey Secret tanımlı değil.');
        }

        $startDate = (string) ($payload['start_date'] ?? date('Y-m-d'));
        $endDate = (string) ($payload['end_date'] ?? date('Y-m-d'));
        $pageSize = max(1, min(500, (int) ($payload['length'] ?? ($_ENV['ALIBABA_DM_PAGE_SIZE'] ?? 500))));
        $action = (string) ($_ENV['ALIBABA_DM_INVALID_ACTION'] ?? 'QueryInvalidAddress');
        $version = (string) ($_ENV['ALIBABA_DM_VERSION'] ?? '2015-11-23');
        $retryCount = max(0, (int) ($_ENV['ALIBABA_DM_RETRY_COUNT'] ?? 5));
        $retryBaseMs = max(200, (int) ($_ENV['ALIBABA_DM_RETRY_BASE_MS'] ?? 1000));
        $timeoutMs = max(1000, (int) ($_ENV['ALIBABA_DM_TIMEOUT_MS'] ?? 15000));
        $maxDays = max(1, (int) ($_ENV['ALIBABA_DM_MAX_DAYS_PER_JOB'] ?? 30));

        $ranges = $this->splitDateRange($startDate, $endDate, $maxDays);
        $fetched = 0;
        $saved = 0;
        $page = 0;
        $totalRetries = 0;
        $nextStart = '';
        $logId = $this->insertAlibabaFetchLog($conn, $jobId, $startDate, $endDate, $pageSize);
        try {
            foreach ($ranges as $range) {
                $cursor = '';
                while (true) {
                    $this->assertNotCancelled($jobService, $jobId);
                    $page++;
                    $params = [
                        'Action' => $action,
                        'Version' => $version,
                        'Format' => 'JSON',
                        'AccessKeyId' => $accessKeyId,
                        'SignatureMethod' => 'HMAC-SHA1',
                        'SignatureVersion' => '1.0',
                        'SignatureNonce' => bin2hex(random_bytes(16)),
                        'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                        'StartTime' => $range['start'],
                        'EndTime' => $range['end'],
                        'Length' => (string) $pageSize,
                    ];
                    if ($cursor !== '') {
                        $params['NextStart'] = $cursor;
                    }
                    $params['Signature'] = $this->alibabaSign($params, $accessKeySecret);
                    $url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

                    $api = $this->callAlibabaApiWithRetry($url, $timeoutMs, $retryCount, $retryBaseMs);
                    $totalRetries += (int) ($api['retries'] ?? 0);
                    $data = is_array($api['data'] ?? null) ? $api['data'] : [];
                    $records = $this->extractAlibabaInvalidRows($data);
                    $fetched += count($records);
                    $saved += $this->upsertAlibabaInvalidRows($conn, $records);
                    $cursor = (string) ($data['NextStart'] ?? ($data['Data']['NextStart'] ?? ''));
                    $nextStart = $cursor;
                    $this->updateAlibabaFetchLog($conn, $logId, [
                        'next_start' => $nextStart,
                        'fetched_count' => $fetched,
                        'saved_count' => $saved,
                        'retry_count' => $totalRetries,
                        'status' => 'running',
                        'error_message' => null,
                    ]);
                    $jobService->updateProgress($jobId, $fetched, max(1, $fetched + 1), $saved, 0, [
                        'current_step' => 'alibaba_fetch_page',
                        'message' => sprintf('Alibaba sayfa %d işlendi, %d kayıt alındı', $page, $fetched),
                        'cursor_payload' => ['next_start' => $nextStart, 'page' => $page],
                    ]);
                    if ($cursor === '') {
                        break;
                    }
                }
            }

            $this->updateAlibabaFetchLog($conn, $logId, [
                'next_start' => $nextStart,
                'fetched_count' => $fetched,
                'saved_count' => $saved,
                'retry_count' => $totalRetries,
                'status' => 'completed',
                'error_message' => null,
            ], true);
        } catch (\Throwable $e) {
            $this->updateAlibabaFetchLog($conn, $logId, [
                'next_start' => $nextStart,
                'fetched_count' => $fetched,
                'saved_count' => $saved,
                'retry_count' => $totalRetries,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ], true);
            throw $e;
        }

        return [
            'fetched_count' => $fetched,
            'saved_count' => $saved,
            'retry_count' => $totalRetries,
            'current_page' => $page,
            'next_start' => $nextStart,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int|string>
     */
    private function runAlibabaInvalidMatchPreview(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $payload): array
    {
        $conn = $em->getConnection();
        $this->ensureAlibabaInvalidTables($conn);
        $scope = (string) ($payload['scope'] ?? 'all_lists');
        $selectedPoolId = (int) ($payload['selected_pool_id'] ?? 0);
        $where = $scope === 'selected_list' && $selectedPoolId > 0 ? ' AND p.pool_list_id = ?' : '';
        $params = $where !== '' ? [$selectedPoolId] : [];

        $matched = (int) $conn->fetchOne(
            "SELECT COUNT(*)
               FROM email_data_pool p
               JOIN alibaba_invalid_addresses a ON a.normalized_email = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
              WHERE p.is_active = 1{$where}",
            $params
        );
        $invalidStored = (int) $conn->fetchOne('SELECT COUNT(*) FROM alibaba_invalid_addresses');
        $jobService->updateProgress($jobId, $matched, max(1, $invalidStored), $matched, 0);

        return [
            'matched_count' => $matched,
            'saved_count' => $invalidStored,
            'cleaned_count' => 0,
            'fetched_count' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, int|string>
     */
    private function runAlibabaInvalidClean(EntityManagerInterface $em, EmailDataPoolJobService $jobService, int $jobId, array $payload): array
    {
        $conn = $em->getConnection();
        $this->ensureAlibabaInvalidTables($conn);
        $scope = (string) ($payload['scope'] ?? 'all_lists');
        $selectedPoolId = (int) ($payload['selected_pool_id'] ?? 0);
        $mode = (string) ($payload['mode'] ?? 'mark_invalid');
        $dryRun = ((int) ($payload['dry_run'] ?? 0)) === 1;
        $batch = max(1000, min(100000, (int) ($_ENV['ALIBABA_INVALID_CLEAN_BATCH_SIZE'] ?? 50000)));
        $hasStatus = $this->hasColumn($conn, 'email_data_pool', 'status');
        $where = $scope === 'selected_list' && $selectedPoolId > 0 ? ' AND p.pool_list_id = ?' : '';
        $params = $where !== '' ? [$selectedPoolId] : [];

        $matched = (int) $conn->fetchOne(
            "SELECT COUNT(*)
               FROM email_data_pool p
               JOIN alibaba_invalid_addresses a ON a.normalized_email = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
              WHERE p.is_active = 1{$where}",
            $params
        );
        if ($dryRun || $mode === 'fetch_only') {
            $jobService->updateProgress($jobId, $matched, max(1, $matched), 0, 0);
            return ['matched_count' => $matched, 'cleaned_count' => 0, 'mode' => 'dry_run'];
        }

        $cleaned = 0;
        while ($cleaned < $matched) {
            $this->assertNotCancelled($jobService, $jobId);
            $ids = $conn->fetchFirstColumn(
                "SELECT p.id
                   FROM email_data_pool p
                   JOIN alibaba_invalid_addresses a ON a.normalized_email = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
                  WHERE p.is_active = 1{$where}
                  LIMIT {$batch}",
                $params
            );
            if ($ids === []) {
                break;
            }
            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            if ($mode === 'hard_delete') {
                $affected = $conn->executeStatement("DELETE FROM email_data_pool WHERE id IN ($in)", $ids);
            } else {
                $statusSql = $hasStatus ? "status = CASE WHEN status = 'active' THEN 'invalid' ELSE status END," : '';
                $affected = $conn->executeStatement(
                    "UPDATE email_data_pool
                        SET is_invalid = 1,
                            invalid_source = 'alibaba',
                            invalid_marked_at = NOW(),
                            {$statusSql}
                            is_active = 0,
                            updated_at = NOW()
                      WHERE id IN ($in)",
                    $ids
                );
            }
            $cleaned += max(0, (int) $affected);
            $jobService->updateProgress($jobId, $cleaned, max(1, $matched), $cleaned, 0, [
                'current_step' => 'alibaba_clean_batch',
                'message' => sprintf('%d / %d kayıt temizlendi', $cleaned, max(1, $matched)),
            ]);
        }

        $this->refreshAllListCounts($conn);
        return [
            'matched_count' => $matched,
            'cleaned_count' => $cleaned,
            'mode' => $mode,
        ];
    }

    /**
     * @return array<int, array{start:string,end:string}>
     */
    private function splitDateRange(string $startDate, string $endDate, int $maxDays): array
    {
        $start = new \DateTimeImmutable($startDate);
        $end = new \DateTimeImmutable($endDate);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        $ranges = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $chunkEnd = $cursor->modify('+' . ($maxDays - 1) . ' days');
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }
            $ranges[] = ['start' => $cursor->format('Y-m-d'), 'end' => $chunkEnd->format('Y-m-d')];
            $cursor = $chunkEnd->modify('+1 day');
        }

        return $ranges;
    }

    /**
     * @return array<string, mixed>
     */
    private function callAlibabaApiWithRetry(string $url, int $timeoutMs, int $maxRetry, int $baseMs): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            $raw = false;
            $httpCode = 0;
            $transportError = '';

            if (function_exists('curl_init')) {
                $ch = curl_init();
                if ($ch === false) {
                    $transportError = 'CURL_INIT_FAILED';
                } else {
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 5000),
                        CURLOPT_TIMEOUT_MS => $timeoutMs,
                        CURLOPT_HTTPHEADER => ['Accept: application/json'],
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                    ]);
                    $rawResult = curl_exec($ch);
                    if ($rawResult === false) {
                        $transportError = 'CURL_ERROR:' . curl_error($ch);
                    } else {
                        $raw = $rawResult;
                        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    }
                    curl_close($ch);
                }
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => max(1, (int) ceil($timeoutMs / 1000)),
                        'header' => "Accept: application/json\r\n",
                        'ignore_errors' => true,
                    ],
                ]);
                $raw = @file_get_contents($url, false, $ctx);
                if ($raw === false) {
                    $last = error_get_last();
                    $transportError = 'HTTP_ERROR:' . (string) ($last['message'] ?? 'UNKNOWN');
                }
                if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
                    if (preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
                        $httpCode = (int) ($m[1] ?? 0);
                    }
                }
            }

            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $decoded = is_array($decoded) ? $decoded : [];
            $errorCode = (string) ($decoded['Code'] ?? '');
            $errorMsg = (string) ($decoded['Message'] ?? '');
            $lowerCode = strtolower($errorCode);
            $lowerMsg = strtolower($errorMsg);
            $isThrottle = str_contains($lowerCode, 'thrott') || str_contains($lowerMsg, 'thrott') || $httpCode === 429;
            $isTimeout = str_contains($lowerMsg, 'timeout') || str_contains(strtolower($transportError), 'timed out');
            $isTimestamp = str_contains($lowerCode, 'timestamp') || str_contains($lowerMsg, 'timestamp');
            $isSignature = str_contains($lowerCode, 'signature') || str_contains($lowerMsg, 'signature');
            $isCredential = str_contains($lowerCode, 'accesskey') || str_contains($lowerMsg, 'accesskey') || str_contains($lowerCode, 'invalidsecuritytoken');
            $isTransientHttp = $httpCode >= 500 || $httpCode === 429;
            $hasError = $raw === false || $transportError !== '' || $errorCode !== '' || $httpCode >= 400;
            if (!$hasError) {
                return ['data' => $decoded, 'retries' => $attempt - 1];
            }
            $retryable = $isThrottle || $isTimeout || $isTransientHttp || str_contains(strtolower($transportError), 'connection');
            if (!$retryable || $attempt > ($maxRetry + 1)) {
                $snippet = is_string($raw) ? trim(substr($raw, 0, 180)) : '';
                if ($isCredential) {
                    $detail = 'ACCESS_KEY_ERROR';
                } elseif ($isSignature) {
                    $detail = 'SIGNATURE_ERROR';
                } elseif ($isTimestamp) {
                    $detail = 'TIMESTAMP_ERROR';
                } else {
                    $detail = $errorCode !== '' ? $errorCode : ($transportError !== '' ? $transportError : ('HTTP_' . $httpCode));
                }
                if ($errorMsg !== '') {
                    $detail .= ' - ' . $errorMsg;
                } elseif ($snippet !== '' && $errorCode === '') {
                    $detail .= ' - ' . $snippet;
                }
                throw new \RuntimeException('Alibaba API çağrısı başarısız: ' . $detail);
            }
            usleep((int) (($baseMs * (2 ** ($attempt - 1))) * 1000));
        }
    }

    private function alibabaPercentEncode(string $value): string
    {
        return str_replace(['+','*','%7E'], ['%20','%2A','~'], rawurlencode($value));
    }

    /**
     * @param array<string, string> $params
     */
    private function alibabaSign(array $params, string $accessKeySecret): string
    {
        unset($params['Signature']);
        ksort($params);
        $canonicalParts = [];
        foreach ($params as $k => $v) {
            $canonicalParts[] = $this->alibabaPercentEncode((string) $k) . '=' . $this->alibabaPercentEncode((string) $v);
        }
        $canonical = implode('&', $canonicalParts);
        $stringToSign = 'GET&' . $this->alibabaPercentEncode('/') . '&' . $this->alibabaPercentEncode($canonical);

        return base64_encode(hash_hmac('sha1', $stringToSign, $accessKeySecret . '&', true));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{email:string,normalized_email:string,reason:string,raw_payload:string,first_seen_at:string,last_seen_at:string}>
     */
    private function extractAlibabaInvalidRows(array $data): array
    {
        $rows = [];
        $candidateArrays = [];
        if (isset($data['InvalidAddress']) && is_array($data['InvalidAddress'])) {
            $candidateArrays[] = $data['InvalidAddress'];
        }
        if (isset($data['Data']['InvalidAddress']) && is_array($data['Data']['InvalidAddress'])) {
            $candidateArrays[] = $data['Data']['InvalidAddress'];
        }
        if (isset($data['Data']['InvalidAddressList']) && is_array($data['Data']['InvalidAddressList'])) {
            $candidateArrays[] = $data['Data']['InvalidAddressList'];
        }
        foreach ($candidateArrays as $arr) {
            foreach ($arr as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $email = strtolower(trim((string) ($item['EmailAddress'] ?? $item['Address'] ?? $item['email'] ?? '')));
                if ($email === '' || !str_contains($email, '@')) {
                    continue;
                }
                $rows[] = [
                    'email' => $email,
                    'normalized_email' => $email,
                    'reason' => (string) ($item['Reason'] ?? $item['ErrorMessage'] ?? ''),
                    'raw_payload' => (string) json_encode($item, JSON_UNESCAPED_UNICODE),
                    'first_seen_at' => date('Y-m-d H:i:s'),
                    'last_seen_at' => date('Y-m-d H:i:s'),
                ];
            }
        }
        return $rows;
    }

    /**
     * @param array<int, array{email:string,normalized_email:string,reason:string,raw_payload:string,first_seen_at:string,last_seen_at:string}> $rows
     */
    private function upsertAlibabaInvalidRows(\Doctrine\DBAL\Connection $conn, array $rows): int
    {
        $saved = 0;
        foreach (array_chunk($rows, 1000) as $chunk) {
            foreach ($chunk as $row) {
                $conn->executeStatement(
                    'INSERT INTO alibaba_invalid_addresses
                        (email, normalized_email, reason, source, raw_payload, first_seen_at, last_seen_at, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        email = VALUES(email),
                        reason = VALUES(reason),
                        raw_payload = VALUES(raw_payload),
                        last_seen_at = VALUES(last_seen_at),
                        updated_at = VALUES(updated_at)',
                    [
                        $row['email'],
                        $row['normalized_email'],
                        $row['reason'],
                        'alibaba',
                        $row['raw_payload'],
                        $row['first_seen_at'],
                        $row['last_seen_at'],
                    ]
                );
                $saved++;
            }
        }
        return $saved;
    }

    private function insertAlibabaFetchLog(\Doctrine\DBAL\Connection $conn, int $jobId, string $startDate, string $endDate, int $pageSize): int
    {
        $conn->executeStatement(
            'INSERT INTO alibaba_invalid_fetch_logs
                (job_id, start_date, end_date, page_size, status, started_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())',
            [$jobId, $startDate, $endDate, $pageSize, 'running']
        );
        return (int) $conn->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateAlibabaFetchLog(\Doctrine\DBAL\Connection $conn, int $logId, array $fields, bool $finished = false): void
    {
        $conn->executeStatement(
            'UPDATE alibaba_invalid_fetch_logs
                SET next_start = ?,
                    fetched_count = ?,
                    saved_count = ?,
                    retry_count = ?,
                    status = ?,
                    error_message = ?,
                    finished_at = CASE WHEN ? = 1 THEN NOW() ELSE finished_at END,
                    updated_at = NOW()
              WHERE id = ?',
            [
                (string) ($fields['next_start'] ?? ''),
                (int) ($fields['fetched_count'] ?? 0),
                (int) ($fields['saved_count'] ?? 0),
                (int) ($fields['retry_count'] ?? 0),
                (string) ($fields['status'] ?? 'running'),
                $fields['error_message'] ?? null,
                $finished ? 1 : 0,
                $logId,
            ]
        );
    }

    private function ensureAlibabaInvalidTables(\Doctrine\DBAL\Connection $conn): void
    {
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS alibaba_invalid_addresses (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                email VARCHAR(320) NOT NULL,
                normalized_email VARCHAR(320) NOT NULL,
                reason VARCHAR(255) DEFAULT NULL,
                source VARCHAR(50) NOT NULL DEFAULT 'alibaba',
                raw_payload JSON DEFAULT NULL,
                first_seen_at DATETIME DEFAULT NULL,
                last_seen_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uniq_normalized_email (normalized_email),
                INDEX idx_email (email),
                INDEX idx_normalized_email (normalized_email),
                INDEX idx_last_seen_at (last_seen_at),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS alibaba_invalid_fetch_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                job_id BIGINT DEFAULT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                page_size INT NOT NULL DEFAULT 500,
                next_start VARCHAR(255) DEFAULT NULL,
                fetched_count INT NOT NULL DEFAULT 0,
                saved_count INT NOT NULL DEFAULT 0,
                matched_count INT NOT NULL DEFAULT 0,
                cleaned_count INT NOT NULL DEFAULT 0,
                retry_count INT NOT NULL DEFAULT 0,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                error_message TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_job_id (job_id),
                INDEX idx_status (status),
                INDEX idx_date_range (start_date, end_date),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @param array<string, mixed> $result
     */
    private function requeueContinuation(\Doctrine\DBAL\Connection $conn, int $jobId, string $message, array $result): void
    {
        $result['defer'] = false;
        unset($result['defer_message']);
        $conn->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'queued',
                    pause_requested = 0,
                    cancel_requested = 0,
                    locked_by = NULL,
                    locked_at = NULL,
                    next_run_at = NOW(),
                    status_message = ?,
                    result = ?,
                    updated_at = NOW()
              WHERE id = ?",
            [
                $message,
                json_encode($result, JSON_UNESCAPED_UNICODE),
                $jobId,
            ]
        );
    }

    private function touchWorkerHeartbeat(\Doctrine\DBAL\Connection $conn, string $workerId, ?int $currentJobId, string $status): void
    {
        $hostname = gethostname() ?: 'unknown';
        $pid = getmypid() ?: 0;
        $conn->executeStatement(
            'INSERT INTO data_pool_worker_heartbeats
                (worker_id, hostname, pid, current_job_id, status, heartbeat_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                hostname = VALUES(hostname),
                pid = VALUES(pid),
                current_job_id = VALUES(current_job_id),
                status = VALUES(status),
                heartbeat_at = VALUES(heartbeat_at),
                updated_at = VALUES(updated_at)',
            [$workerId, $hostname, $pid, $currentJobId, $status]
        );
    }

    /**
     * @param array<string, mixed> $job
     */
    private function handleJobFailure(
        EmailDataPoolJobService $jobService,
        array $job,
        \Throwable $e,
        string $workerId,
        SymfonyStyle $io
    ): void {
        $jobId = (int) ($job['id'] ?? 0);
        $message = trim($e->getMessage());
        $errorCode = $this->extractErrorCode($e, $message);
        $failedStep = $this->extractFailedStep($message);
        $lastSqlName = $this->extractLastSqlName($message);

        if (str_starts_with($message, self::CANCELLED_EXCEPTION_PREFIX)) {
            $jobService->markCancelled($jobId, trim(substr($message, strlen(self::CANCELLED_EXCEPTION_PREFIX))));
            $io->warning(sprintf('#%d iptal edildi: %s', $jobId, trim(substr($message, strlen(self::CANCELLED_EXCEPTION_PREFIX)))));
            return;
        }
        if (str_starts_with($message, self::PAUSED_EXCEPTION_PREFIX)) {
            $jobService->markPaused($jobId, trim(substr($message, strlen(self::PAUSED_EXCEPTION_PREFIX))));
            $io->warning(sprintf('#%d duraklatıldı: %s', $jobId, trim(substr($message, strlen(self::PAUSED_EXCEPTION_PREFIX)))));
            return;
        }

        $attempts = max(1, (int) ($job['attempts'] ?? 1));
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 3));
        if ($this->isRetryableException($e) && $attempts < $maxAttempts) {
            $delay = $this->retryBackoffSeconds($attempts);
            $jobService->requeueForRetry($jobId, $delay, sprintf('Geçici hata nedeniyle retry planlandı (%d/%d)', $attempts, $maxAttempts));
            $io->warning(sprintf('#%d geçici hata, %ds sonra retry: %s', $jobId, $delay, $message));
            return;
        }

        $userMessage = $this->userFacingErrorMessage((string) ($job['type'] ?? ''), $message);
        $jobService->markFailed($jobId, $userMessage, [
            'error_code' => $errorCode,
            'exception_class' => $e::class,
            'failed_step' => $failedStep,
            'last_sql_name' => $lastSqlName,
            'worker_id' => $workerId,
            'status_message' => 'İşlem hata ile sonlandı.',
        ]);
        $io->error(sprintf('#%d basarisiz [%s]: %s', $jobId, $errorCode, $message));
    }

    private function retryBackoffSeconds(int $attempt): int
    {
        $base = max(2, (int) ($_ENV['DATA_POOL_JOB_RETRY_BASE_SECONDS'] ?? 5));
        $max = max(10, (int) ($_ENV['DATA_POOL_JOB_RETRY_MAX_SECONDS'] ?? 300));
        $delay = $base * (2 ** max(0, $attempt - 1));

        return (int) min($max, $delay);
    }

    private function isRetryableException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'deadlock') || str_contains($message, 'lock wait timeout')) {
            return true;
        }
        if (str_contains($message, 'server has gone away') || str_contains($message, 'lost connection')) {
            return true;
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'temporarily unavailable')) {
            return true;
        }
        if (str_contains($message, 'cURL error') || str_contains($message, 'CURL_ERROR')) {
            return true;
        }

        return false;
    }

    private function extractErrorCode(\Throwable $e, string $message): string
    {
        if (preg_match('/SQLSTATE\[(.*?)\]/', $message, $m)) {
            return (string) ($m[1] ?? 'UNKNOWN');
        }
        $code = (string) $e->getCode();
        if ($code !== '' && $code !== '0') {
            return $code;
        }
        if (preg_match('/Alibaba API çağrısı başarısız:\s*([A-Z0-9_:-]+)/u', $message, $m)) {
            return (string) ($m[1] ?? 'ALIBABA_API_ERROR');
        }

        return 'RUNTIME_ERROR';
    }

    private function extractFailedStep(string $message): string
    {
        if (preg_match('/FAILED_STEP:([a-zA-Z0-9_\-]+)/', $message, $m)) {
            return (string) ($m[1] ?? 'unknown');
        }

        return 'handle';
    }

    private function extractLastSqlName(string $message): ?string
    {
        if (preg_match('/SQL_NAME:([a-zA-Z0-9_\-]+)/', $message, $m)) {
            return (string) ($m[1] ?? null);
        }

        return null;
    }

    private function userFacingErrorMessage(string $jobType, string $rawMessage): string
    {
        $m = strtolower($rawMessage);
        if (str_contains($m, 'sqlstate[hy093]')) {
            return 'İşlem SQL parametre uyumsuzluğu nedeniyle başarısız oldu.';
        }
        if (str_contains($m, 'table') && str_contains($m, 'is full')) {
            return 'İşlem geçici depolama limiti nedeniyle tamamlanamadı.';
        }
        if (str_contains($m, 'accesskey') || str_contains($m, 'signature')) {
            return 'Alibaba kimlik doğrulama bilgileri geçersiz veya eksik.';
        }
        if (str_contains($m, 'timeout')) {
            return 'İşlem zaman aşımı nedeniyle başarısız oldu.';
        }

        return sprintf('%s işlemi hata nedeniyle tamamlanamadı.', $jobType !== '' ? $jobType : 'Worker');
    }

    private function assertNotCancelled(EmailDataPoolJobService $jobService, int $jobId): void
    {
        if ($jobService->isPauseRequested($jobId)) {
            throw new \RuntimeException(self::PAUSED_EXCEPTION_PREFIX . 'Kullanıcı duraklatma talebi gönderdi.');
        }
        if ($jobService->isCancelRequested($jobId)) {
            throw new \RuntimeException(self::CANCELLED_EXCEPTION_PREFIX . 'Kullanıcı iptal talebi gönderdi.');
        }
    }

    /**
     * @param array<int, mixed> $params
     */
    private function executeNamedSql(\Doctrine\DBAL\Connection $conn, string $sqlName, string $sql, array $params = []): int
    {
        $placeholderCount = substr_count($sql, '?');
        if ($placeholderCount !== count($params)) {
            throw new \RuntimeException(sprintf(
                'FAILED_STEP:sql_execute SQL_NAME:%s SQLSTATE[HY093] Parametre sayısı eşleşmedi (%d != %d).',
                $sqlName,
                $placeholderCount,
                count($params)
            ));
        }

        return $conn->executeStatement($sql, $params);
    }

    private function refreshListCounts(\Doctrine\DBAL\Connection $conn, int $poolId): void
    {
        $row = $conn->fetchAssociative(
            'SELECT COUNT(*) AS total_count, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count FROM email_data_pool WHERE pool_list_id = ?',
            [$poolId]
        ) ?: [];
        $total = (int) ($row['total_count'] ?? 0);
        $active = (int) ($row['active_count'] ?? 0);

        $conn->executeStatement(
            'UPDATE email_data_pool_lists
                SET total_count = ?, active_count = ?, passive_count = ?, updated_count_at = NOW()
              WHERE id = ?',
            [$total, $active, max(0, $total - $active), $poolId]
        );
    }
}
