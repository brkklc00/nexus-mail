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
        $once = (bool) $input->getOption('once');
        $startedAt = time();

        $io->text(sprintf('Data pool worker basladi (batch=%d, sleep=%dms, max_runtime=%ds)', $batchSize, $sleepMs, $maxRuntime));
        $recovered = $this->recoverStaleRunningJobs($em, $staleMinutes);
        if ($recovered > 0) {
            $io->warning(sprintf('%d adet takılı running job failed olarak işaretlendi.', $recovered));
        }

        while (true) {
            if ((time() - $startedAt) >= $maxRuntime) {
                $io->text('Max runtime doldu, worker sonlaniyor.');
                break;
            }

            $job = $this->claimNextQueuedJob($em);
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
            $jobService->markRunning($jobId);

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
                    default => throw new \RuntimeException('Desteklenmeyen job tipi: ' . $type),
                };

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
                $io->success(sprintf('#%d tamamlandi', $jobId));
            } catch (\Throwable $e) {
                $jobService->markFailed($jobId, $e->getMessage());
                $io->error(sprintf('#%d basarisiz: %s', $jobId, $e->getMessage()));
            } finally {
                $em->clear();
            }

            if ($once) {
                break;
            }
        }

        return Command::SUCCESS;
    }

    private function recoverStaleRunningJobs(EntityManagerInterface $em, int $staleMinutes): int
    {
        $conn = $em->getConnection();
        return $conn->executeStatement(
            "UPDATE data_pool_jobs
                SET status = 'failed',
                    error_message = COALESCE(NULLIF(error_message, ''), 'Worker yeniden başlatıldığı için job sonlandırıldı.'),
                    finished_at = NOW(),
                    updated_at = NOW()
              WHERE status = 'running'
                AND updated_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)",
            [$staleMinutes]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimNextQueuedJob(EntityManagerInterface $em): ?array
    {
        $conn = $em->getConnection();
        $lock = (int) $conn->fetchOne("SELECT GET_LOCK('data_pool_jobs_worker', 0)");
        if ($lock !== 1) {
            return null;
        }

        try {
            $job = $conn->fetchAssociative(
                "SELECT * FROM data_pool_jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1"
            );
            if (!$job) {
                return null;
            }
            $conn->executeStatement(
                "UPDATE data_pool_jobs SET status = 'running', started_at = COALESCE(started_at, ?), updated_at = ? WHERE id = ? AND status = 'queued'",
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), (new \DateTimeImmutable())->format('Y-m-d H:i:s'), (int) ($job['id'] ?? 0)]
            );

            return $job;
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

            $jobService->updateProgress($jobId, $processed, $total, $processed, 0);
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
            $ids = $conn->fetchFirstColumn("SELECT id FROM tmp_pool_dup_ids ORDER BY id ASC LIMIT {$safeBatch}");
            if ($ids === []) {
                break;
            }

            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $deleted += $conn->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)",
                array_merge([$poolId], $ids)
            );
            $conn->executeStatement(
                "DELETE FROM tmp_pool_dup_ids WHERE id IN ($in)",
                $ids
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
            $jobService->updateProgress($jobId, $processed, $totalToFix, $fixed, 0);
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
        $declaredTotal = max(1, (int) ($job['total_count'] ?? 1));

        $jobService->updateProgress($jobId, (int) floor($declaredTotal * 0.02), $declaredTotal, 0, 0);
        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_global_dup_norms');
        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_global_dedup_keep_ids');
        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_global_dedup_dup_ids');
        $conn->executeStatement('CREATE TEMPORARY TABLE tmp_global_dup_norms (norm VARCHAR(320) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        // MEMORY tabloları 15M+ datasetlerde "table is full" hatasına düşebildiği için InnoDB kullan.
        $conn->executeStatement('CREATE TEMPORARY TABLE tmp_global_dedup_keep_ids (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $conn->executeStatement('CREATE TEMPORARY TABLE tmp_global_dedup_dup_ids (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        $jobService->updateProgress($jobId, (int) floor($declaredTotal * 0.05), $declaredTotal, 0, 0);

        $conn->executeStatement(
            "INSERT INTO tmp_global_dup_norms (norm)
             SELECT COALESCE(normalized_email, LOWER(TRIM(email))) AS norm
               FROM email_data_pool
              WHERE " . $this->globalActiveWhereClause($conn) . "
                AND COALESCE(normalized_email, LOWER(TRIM(email))) <> ''
              GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
             HAVING COUNT(*) > 1"
        );
        $jobService->updateProgress($jobId, (int) floor($declaredTotal * 0.18), $declaredTotal, 0, 0);

        if ($strategy === 'keep_newest') {
            $conn->executeStatement(
                "INSERT INTO tmp_global_dedup_keep_ids (id)
                 SELECT MAX(p.id) AS keep_id
                   FROM email_data_pool p
                   JOIN tmp_global_dup_norms d ON d.norm = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
                  WHERE {$where}
                  GROUP BY d.norm"
            );
        } elseif ($strategy === 'keep_priority' && $priorityListIds !== []) {
            $inPriority = implode(',', array_fill(0, count($priorityListIds), '?'));
            $conn->executeStatement(
                "INSERT INTO tmp_global_dedup_keep_ids (id)
                 SELECT COALESCE(
                            MIN(CASE WHEN p.pool_list_id IN ($inPriority) THEN p.id END),
                            MIN(p.id)
                        ) AS keep_id
                   FROM email_data_pool p
                   JOIN tmp_global_dup_norms d ON d.norm = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
                  WHERE {$where}
                  GROUP BY d.norm",
                $priorityListIds
            );
        } else {
            $conn->executeStatement(
                "INSERT INTO tmp_global_dedup_keep_ids (id)
                 SELECT MIN(p.id) AS keep_id
                   FROM email_data_pool p
                   JOIN tmp_global_dup_norms d ON d.norm = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
                  WHERE {$where}
                  GROUP BY d.norm"
            );
        }
        $jobService->updateProgress($jobId, (int) floor($declaredTotal * 0.30), $declaredTotal, 0, 0);

        $conn->executeStatement(
            "INSERT INTO tmp_global_dedup_dup_ids (id)
             SELECT p.id
               FROM email_data_pool p
          LEFT JOIN tmp_global_dedup_keep_ids k ON k.id = p.id
               JOIN tmp_global_dup_norms d ON d.norm = COALESCE(p.normalized_email, LOWER(TRIM(p.email)))
              WHERE {$where}
                AND k.id IS NULL"
        );
        $jobService->updateProgress($jobId, (int) floor($declaredTotal * 0.40), $declaredTotal, 0, 0);

        $totalToAffect = (int) $conn->fetchOne('SELECT COUNT(*) FROM tmp_global_dedup_dup_ids');
        $processed = 0;
        $affected = 0;
        $hasStatus = $this->hasColumn($conn, 'email_data_pool', 'status');
        $hasIsDuplicate = $this->hasColumn($conn, 'email_data_pool', 'is_duplicate');
        $globalBatchSize = max(5000, (int) ($_ENV['DATA_POOL_GLOBAL_DEDUP_BATCH_SIZE'] ?? $batchSize));
        $limit = max(1000, min(100000, $globalBatchSize));
        $phaseBase = (int) floor($declaredTotal * 0.40);
        $phaseSpan = max(1, $declaredTotal - $phaseBase);

        while (true) {
            $ids = $conn->fetchFirstColumn("SELECT id FROM tmp_global_dedup_dup_ids ORDER BY id ASC LIMIT {$limit}");
            if ($ids === []) {
                break;
            }
            $ids = array_values(array_map('intval', $ids));
            $in = implode(',', array_fill(0, count($ids), '?'));

            if ($mode === 'delete') {
                $affected += $conn->executeStatement(
                    "DELETE FROM email_data_pool WHERE id IN ($in)",
                    $ids
                );
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
                $affected += $conn->executeStatement(
                    "UPDATE email_data_pool SET {$setSql} WHERE id IN ($in)",
                    $ids
                );
            }

            $conn->executeStatement(
                "DELETE FROM tmp_global_dedup_dup_ids WHERE id IN ($in)",
                $ids
            );
            $processed += count($ids);
            $phaseProgress = (int) floor((($processed / max(1, $totalToAffect)) * $phaseSpan));
            $jobService->updateProgress($jobId, min($declaredTotal, $phaseBase + $phaseProgress), $declaredTotal, $affected, 0);
        }
        $conn->executeStatement('DROP TEMPORARY TABLE IF EXISTS tmp_global_dup_norms');
        $jobService->updateProgress($jobId, max((int) floor($declaredTotal * 0.95), $phaseBase), max(1, $declaredTotal), $affected, 0);

        $this->refreshAllListCounts($conn);
        $preview = $this->processGlobalDeduplicatePreview($em, $jobService, $jobId, $batchSize);
        $reportUrl = $this->writeGlobalDedupReport([
            'mode' => $mode,
            'strategy' => $strategy,
            'priority_list_ids' => $priorityListIds,
            'affected_rows' => $affected,
            'duplicate_groups' => (int) ($preview['duplicate_groups'] ?? 0),
            'total_rows' => (int) ($preview['total_rows'] ?? 0),
            'unique_emails' => (int) ($preview['unique_emails'] ?? 0),
            'top_domains' => $preview['top_domains'] ?? [],
            'by_list' => $preview['by_list'] ?? [],
            'finished_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return [
            'processed_count' => $processed,
            'success_count' => $affected,
            'failed_count' => 0,
            'affected_rows' => $affected,
            'removed_count' => $mode === 'delete' ? $affected : 0,
            'duplicate_groups' => (int) ($preview['duplicate_groups'] ?? 0),
            'total_rows' => (int) ($preview['total_rows'] ?? 0),
            'unique_emails' => (int) ($preview['unique_emails'] ?? 0),
            'mode' => $mode,
            'strategy' => $strategy,
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
        $lastId = 0;
        $safeBatch = max(1000, min(100000, (int) ($_ENV['DATA_POOL_BALANCE_BATCH_SIZE'] ?? $batchSize)));

        while ($inserted < $need) {
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
                $insertValues[] = '(?, ?, ?, ?, ?, 0, 0, 1, NOW(), NOW())';
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
                $insertedNow = $conn->executeStatement($sql, $insertParams);
                $inserted += $insertedNow;
            }
            if ($moveIds !== []) {
                $in = implode(',', array_fill(0, count($moveIds), '?'));
                $conn->executeStatement("DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)", array_merge([$sourceListId], $moveIds));
                $moved += count($moveIds);
            }
            $jobService->updateProgress($jobId, $inserted, max(1, $total), $inserted, 0);
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
            $jobService->updateProgress($jobId, $moved, max(1, $overflow), $moved, 0);
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
                $conn->executeStatement(
                    'INSERT INTO email_data_pool (pool_list_id, email, normalized_email, domain, name, is_gmail, is_duplicate, is_invalid, is_active, created_at, updated_at) VALUES ' . implode(',', $insertValues),
                    $insertParams
                );
            }
            if ($mode === 'move' && $ids !== []) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $conn->executeStatement("DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)", array_merge([$sourceListId], $ids));
            }
            $processed += count($rows);
            $currentCount += count($rows);
            $jobService->updateProgress($jobId, $processed, max(1, $total), $processed, 0);
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
            $jobService->updateProgress($jobId, $processed, max(1, count($poolIds) * $targetLimit), $processed, 0);
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
        if ($accessKeyId === '' || $accessKeySecret === '') {
            throw new \RuntimeException('Alibaba AccessKey ayarları eksik.');
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
                    $jobService->updateProgress($jobId, $fetched, max(1, $fetched + 1), $saved, 0);
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
            $jobService->updateProgress($jobId, $cleaned, max(1, $matched), $cleaned, 0);
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
            $isThrottle = str_contains(strtolower($errorCode), 'thrott') || str_contains(strtolower($errorMsg), 'thrott') || $httpCode === 429;
            $hasError = $raw === false || $transportError !== '' || $errorCode !== '' || $httpCode >= 400;
            if (!$hasError) {
                return ['data' => $decoded, 'retries' => $attempt - 1];
            }
            if (!$isThrottle || $attempt > ($maxRetry + 1)) {
                $snippet = is_string($raw) ? trim(substr($raw, 0, 180)) : '';
                $detail = $errorCode !== '' ? $errorCode : ($transportError !== '' ? $transportError : ('HTTP_' . $httpCode));
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
