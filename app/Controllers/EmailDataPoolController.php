<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\EmailDataPool;
use App\Domain\Entities\EmailDataPoolList;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailDataPoolController
{
    private const CLEANER_CSRF_SESSION_KEY = 'email_pool_cleaner_csrf';
    private const LEFTOVER_FILES_SESSION_KEY = 'email_pool_leftover_files';
    private const DEFAULT_TARGET_LIMIT = 250000;
    private const BALANCE_JOB_TYPES = [
        'complete_to_target',
        'move_overflow',
        'copy_to_target',
        'split_pool',
        'balance_pools',
        'fill_new_pool',
    ];
    private const GLOBAL_BLOCKING_JOB_TYPES = [
        'global_analyze_all_pools',
        'global_deduplicate_apply',
        'refresh_all_pool_stats',
        'alibaba_invalid_fetch',
        'alibaba_invalid_match_preview',
        'alibaba_invalid_clean_apply',
        'alibaba_invalid_fetch_and_clean',
    ];
    private const ALIBABA_JOB_TYPES = [
        'alibaba_invalid_fetch',
        'alibaba_invalid_match_preview',
        'alibaba_invalid_clean_apply',
        'alibaba_invalid_fetch_and_clean',
    ];
    private ?bool $hasNormalizationColumns = null;
    private ?bool $analysisTablesReady = null;

    /** @var array<int, string> */
    private const GMAIL_TYPO_DOMAINS = [
        'gmial.com',
        'gamil.com',
        'gmai.com',
        'gmail.co',
        'gmail.con',
        'gmal.com',
        'gmaill.com',
        'gml.com',
        'gnail.com',
        'gmaiil.com',
        'gmail.cm',
        'gmail.om',
        'gmail.com.tr',
        'gmail.coom',
        'gmail.comm',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    /**
     * Mail havuzu listesi
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $listIdParam = (int) ($params['list_id'] ?? 0);
        $listSearch = trim((string) ($params['list_search'] ?? ''));

        $listQb = $this->em->createQueryBuilder()
            ->select('l')
            ->from(EmailDataPoolList::class, 'l');
        if ($listSearch !== '') {
            $listQb->where('l.name LIKE :listSearch')
                ->setParameter('listSearch', '%' . $listSearch . '%');
        }
        $totalLists = (int) (clone $listQb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();

        /** @var EmailDataPoolList[] $visibleLists */
        $visibleLists = $listQb
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        $currentList = $listIdParam > 0 ? $this->em->find(EmailDataPoolList::class, $listIdParam) : null;
        if (!$currentList && $visibleLists !== []) {
            $currentList = $visibleLists[0];
        }
        if ($currentList) {
            $hasCurrentInVisible = false;
            foreach ($visibleLists as $visibleList) {
                if ($visibleList->getId() === $currentList->getId()) {
                    $hasCurrentInVisible = true;
                    break;
                }
            }
            if (!$hasCurrentInVisible) {
                array_unshift($visibleLists, $currentList);
            }
        }

        $totalAllPoolsEntries = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(total_count), 0) FROM email_data_pool_lists');
        $statsByListId = $this->fetchListStatsSummaries(
            array_values(array_unique(array_map(static fn (EmailDataPoolList $list): int => (int) $list->getId(), $visibleLists)))
        );
        $analysisByListId = $this->fetchListAnalysisSummaries(
            array_values(array_unique(array_map(static fn (EmailDataPoolList $list): int => (int) $list->getId(), $visibleLists)))
        );
        $listSummaries = [];
        foreach ($visibleLists as $pl) {
            $lid = $pl->getId();
            $analysis = $analysisByListId[$lid] ?? [];
            $stats = $statsByListId[$lid] ?? [];
            $listSummaries[] = [
                'id' => $lid,
                'name' => $pl->getName(),
                'sort_order' => $pl->getSortOrder(),
                'entry_count' => (int) ($stats['total_count'] ?? $pl->getTotalCount()),
                'active_count' => (int) ($stats['active_count'] ?? $pl->getActiveCount()),
                'passive_count' => $pl->getPassiveCount(),
                'updated_count_at' => $pl->getUpdatedCountAt()?->format('Y-m-d H:i:s'),
                'analysis_status' => (string) ($analysis['status'] ?? 'idle'),
                'gmail_ratio' => isset($analysis['gmail_ratio'])
                    ? (float) $analysis['gmail_ratio']
                    : ((int) ($stats['active_count'] ?? 0) > 0 ? round(((int) ($stats['gmail_count'] ?? 0) / (int) ($stats['active_count'] ?? 0)) * 100, 2) : null),
                'invalid_gmail_count' => isset($analysis['invalid_gmail_count']) ? (int) $analysis['invalid_gmail_count'] : (int) ($stats['invalid_gmail_count'] ?? 0),
                'duplicate_count' => isset($analysis['duplicate_count']) ? (int) $analysis['duplicate_count'] : (int) ($stats['duplicate_count'] ?? 0),
                'non_gmail_count' => isset($analysis['non_gmail_count']) ? (int) $analysis['non_gmail_count'] : (int) ($stats['non_gmail_count'] ?? 0),
                'last_analyzed_at' => isset($analysis['last_analyzed_at']) ? (string) $analysis['last_analyzed_at'] : (isset($stats['last_analyzed_at']) ? (string) $stats['last_analyzed_at'] : null),
            ];
        }

        $total = 0;
        $activeCount = 0;
        $passiveCount = 0;
        $listTotalAll = 0;
        $updatedCountAt = null;
        if ($currentList) {
            $listTotalAll = $currentList->getTotalCount();
            $total = $listTotalAll;
            $activeCount = $currentList->getActiveCount();
            $passiveCount = $currentList->getPassiveCount();
            $updatedCountAt = $currentList->getUpdatedCountAt()?->format('Y-m-d H:i:s');
        }

        $html = $this->twig->render('admin/email-data-pool/index.twig', [
            'pool_lists' => $listSummaries,
            'current_list_id' => $currentList?->getId(),
            'current_list_name' => $currentList?->getName(),
            'list_search' => $listSearch,
            'list_total' => $totalLists,
            'total' => $total,
            'active_count' => $activeCount,
            'passive_count' => $passiveCount,
            'list_total_all' => $listTotalAll,
            'updated_count_at' => $updatedCountAt,
            'total_all_pools_entries' => $totalAllPoolsEntries,
            'default_target_limit' => (int) ($_ENV['EMAIL_POOL_DEFAULT_TARGET_LIMIT'] ?? self::DEFAULT_TARGET_LIMIT),
            'cleaner_csrf' => $this->getOrCreateCleanerCsrfToken(),
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null
        ]);
        
        // Flash messages temizle
        unset($_SESSION['success'], $_SESSION['error']);
        
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * @param array<int, int> $listIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchListAnalysisSummaries(array $listIds): array
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds), static fn (int $id): bool => $id > 0));
        if ($listIds === []) {
            return [];
        }

        try {
            $this->ensureAnalysisTables();
        } catch (\Throwable) {
            return [];
        }

        $conn = $this->em->getConnection();
        $rowsById = [];
        foreach (array_chunk($listIds, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT list_id, status, gmail_ratio, invalid_gmail_count, duplicate_count, non_gmail_count, last_analyzed_at
                   FROM email_data_pool_analysis_cache
                  WHERE list_id IN ($placeholders)",
                $chunk
            );
            foreach ($rows as $row) {
                $lid = (int) ($row['list_id'] ?? 0);
                if ($lid > 0) {
                    $rowsById[$lid] = $row;
                }
            }
        }

        return $rowsById;
    }

    /**
     * @param array<int, int> $listIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchListStatsSummaries(array $listIds): array
    {
        $listIds = array_values(array_filter(array_map('intval', $listIds), static fn (int $id): bool => $id > 0));
        if ($listIds === []) {
            return [];
        }

        try {
            $this->ensureDataPoolStatsTable();
        } catch (\Throwable) {
            return [];
        }

        $conn = $this->em->getConnection();
        $rowsById = [];
        foreach (array_chunk($listIds, 1000) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT pool_id, total_count, active_count, gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at, updated_at
                   FROM email_pool_stats
                  WHERE pool_id IN ($placeholders)",
                $chunk
            );
            foreach ($rows as $row) {
                $pid = (int) ($row['pool_id'] ?? 0);
                if ($pid > 0) {
                    $rowsById[$pid] = $row;
                }
            }
        }

        return $rowsById;
    }

    /**
     * Toplu mail ekleme (OPTIMIZED with bulk insert)
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $startTime = microtime(true);
        $poolList = null;

        try {
            $listId = (int) ($data['pool_list_id'] ?? 0);
            $poolList = $this->resolvePoolListById($listId);
            // Performance ayarları
            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', '600');
            
            $rawInput = (string) ($data['emails'] ?? '');
            $parseResult = $this->parseImportInput($rawInput, true);
            $unique = $parseResult['records'];
            $stats = $parseResult['stats'];
            
            // Existing emails'leri bulk olarak çek (aynı liste içinde)
            $existingEmails = $this->em->createQueryBuilder()
                ->select('d.email')
                ->from(EmailDataPool::class, 'd')
                ->where('d.poolList = :pl')
                ->andWhere('d.email IN (:emails)')
                ->setParameter('pl', $poolList)
                ->setParameter('emails', array_column($unique, 'email'))
                ->getQuery()
                ->getResult();
            
            $existingSet = array_flip(array_column($existingEmails, 'email'));
            
            // Yeni email'leri filtrele
            $newEmails = array_filter($unique, fn($item) => !isset($existingSet[$item['email']]));
            $existingSkipped = count($unique) - count($newEmails);
            
            if (empty($newEmails)) {
                if (($stats['valid_email'] ?? 0) === 0) {
                    $_SESSION['error'] = 'Geçerli mail bulunamadı. Lütfen formatı kontrol edin.';
                } else {
                    $_SESSION['error'] = 'Tüm mail adresleri bu listede zaten mevcut';
                }
                return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
            }
            
            // Bulk insert ile ekle (aktif olarak)
            $added = $this->bulkInsertEmails($newEmails, $poolList->getId());
            if ($added > 0) {
                $this->incrementListCounts((int) $poolList->getId(), $added, $added, 0);
            }
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = sprintf(
                'Import tamamlandı (%ss): Toplam satır %d, geçerli %d, eklenen %d, zaten var %d, duplicate atlanan %d, geçersiz atlanan %d, durum nedeniyle atlanan %d.',
                $elapsed,
                (int) ($stats['total_rows'] ?? 0),
                (int) ($stats['valid_email'] ?? 0),
                $added,
                $existingSkipped,
                (int) ($stats['duplicate_skipped'] ?? 0),
                (int) ($stats['invalid_skipped'] ?? 0),
                (int) ($stats['status_skipped'] ?? 0)
            );

        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::store import error: ' . $e->getMessage());
            $_SESSION['error'] = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Import sırasında bir hata oluştu. Lütfen tekrar deneyin.';
        }

        return $response->withHeader('Location', $this->poolListRedirect($poolList?->getId()))->withStatus(302);
    }
    
    /**
     * Import metnini akıllı şekilde parse eder (TXT/CSV benzeri).
     *
     * @return array{
     *   records: array<int, array{email: string, name: ?string}>,
     *   stats: array{
     *     total_rows: int,
     *     valid_email: int,
     *     duplicate_skipped: int,
     *     invalid_skipped: int,
     *     status_skipped: int
     *   }
     * }
     */
    private function parseImportInput(string $rawInput, bool $requireEmailColumnWhenHeader = false): array
    {
        $rawInput = preg_replace('/^\xEF\xBB\xBF/', '', $rawInput) ?? $rawInput;

        $stats = [
            'total_rows' => 0,
            'valid_email' => 0,
            'duplicate_skipped' => 0,
            'invalid_skipped' => 0,
            'status_skipped' => 0,
        ];

        // Büyük importlarda satırları diziye toplamak bellek patlatır.
        // Sadece örnek satırlar toplanır, kalan içerik satır satır işlenir.
        $sampleLines = [];
        $firstNonEmptyLine = null;

        $token = strtok($rawInput, "\r\n");
        while ($token !== false) {
            $line = rtrim((string) $token);
            if (trim($line) !== '') {
                if ($firstNonEmptyLine === null) {
                    $firstNonEmptyLine = $line;
                }
                if (count($sampleLines) < 5) {
                    $sampleLines[] = $line;
                }
            }
            $token = strtok("\r\n");
        }

        if ($firstNonEmptyLine === null) {
            throw new \RuntimeException('Dosya boş. Lütfen import edilecek satırları kontrol edin.');
        }

        $delimiter = $this->detectDelimiter($sampleLines);
        $headerRow = str_getcsv($firstNonEmptyLine, $delimiter);
        $headerMap = $this->detectHeaderMap($headerRow);
        $hasHeader = $headerMap['has_header'];
        $emailIdx = $headerMap['email'];
        $nameIdx = $headerMap['name'];
        $statusIdx = $headerMap['status'];

        if ($hasHeader && $requireEmailColumnWhenHeader && $emailIdx === null) {
            throw new \RuntimeException('Mail kolonu bulunamadı. Lütfen Mail Adresi, email veya mail başlıklı kolon kullanın.');
        }

        $parsed = [];
        $seen = [];
        $nonEmptyLineIdx = 0;

        $token = strtok($rawInput, "\r\n");
        while ($token !== false) {
            $line = rtrim((string) $token);
            $token = strtok("\r\n");

            if (trim($line) === '') {
                continue;
            }
            $nonEmptyLineIdx++;

            if ($hasHeader && $nonEmptyLineIdx === 1) {
                continue;
            }

            $row = str_getcsv($line, $delimiter);
            if (!is_array($row) || $row === []) {
                continue;
            }

            $stats['total_rows']++;

            $email = null;
            $name = null;
            $status = null;

            if ($emailIdx !== null && array_key_exists($emailIdx, $row)) {
                $email = trim((string) $row[$emailIdx]);
            }
            if ($nameIdx !== null && array_key_exists($nameIdx, $row)) {
                $name = trim((string) $row[$nameIdx]) ?: null;
            }
            if ($statusIdx !== null && array_key_exists($statusIdx, $row)) {
                $status = trim((string) $row[$statusIdx]);
            }

            if ($email === null || $email === '') {
                foreach ($row as $cell) {
                    $candidate = trim((string) $cell);
                    if (strpos($candidate, '@') !== false) {
                        $email = $candidate;
                        break;
                    }
                }
            }

            $email = strtolower(trim((string) $email));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $stats['invalid_skipped']++;
                continue;
            }

            if ($statusIdx !== null && $this->isPassiveStatus($status)) {
                $stats['status_skipped']++;
                continue;
            }

            if (isset($seen[$email])) {
                $stats['duplicate_skipped']++;
                continue;
            }
            $seen[$email] = true;
            $stats['valid_email']++;

            $parsed[] = [
                'email' => $email,
                'name' => $name
            ];
        }

        return ['records' => $parsed, 'stats' => $stats];
    }

    private function detectDelimiter(array $lines): string
    {
        $candidates = [',', ';', "\t"];
        $sample = array_slice($lines, 0, 5);
        $best = ',';
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $score = 0;
            foreach ($sample as $line) {
                $cols = str_getcsv((string) $line, $candidate);
                $score += is_array($cols) ? count($cols) : 0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }
        return $best;
    }

    private function normalizeHeaderName(string $header): string
    {
        $h = trim($header);
        $h = str_replace("\xEF\xBB\xBF", '', $h);
        if (function_exists('mb_strtolower')) {
            $h = mb_strtolower($h, 'UTF-8');
        } else {
            $h = strtolower($h);
        }
        $h = str_replace(['"', "'", '-', ' '], '', $h);
        return $h;
    }

    /**
     * @param array<int, mixed> $headerRow
     * @return array{has_header: bool, email: ?int, name: ?int, status: ?int}
     */
    private function detectHeaderMap(array $headerRow): array
    {
        $emailAliases = ['mailadresi', 'email', 'emailadresi', 'e-mail', 'mail', 'eposta', 'e-posta', 'epostaadresi', 'mailaddress', 'recipient', 'address'];
        $nameAliases = ['isim', 'name', 'ad', 'fullname', 'full_name'];
        $statusAliases = ['durum', 'status'];
        $emailAliasesNorm = array_map([$this, 'normalizeHeaderName'], $emailAliases);
        $nameAliasesNorm = array_map([$this, 'normalizeHeaderName'], $nameAliases);
        $statusAliasesNorm = array_map([$this, 'normalizeHeaderName'], $statusAliases);

        $emailIdx = null;
        $nameIdx = null;
        $statusIdx = null;
        $headerHits = 0;

        foreach ($headerRow as $idx => $col) {
            $normalized = $this->normalizeHeaderName((string) $col);
            if ($normalized === '') {
                continue;
            }
            if ($emailIdx === null && in_array($normalized, $emailAliasesNorm, true)) {
                $emailIdx = (int) $idx;
                $headerHits++;
                continue;
            }
            if ($nameIdx === null && in_array($normalized, $nameAliasesNorm, true)) {
                $nameIdx = (int) $idx;
                $headerHits++;
                continue;
            }
            if ($statusIdx === null && in_array($normalized, $statusAliasesNorm, true)) {
                $statusIdx = (int) $idx;
                $headerHits++;
            }
        }

        return [
            'has_header' => $headerHits > 0,
            'email' => $emailIdx,
            'name' => $nameIdx,
            'status' => $statusIdx,
        ];
    }

    private function isPassiveStatus(?string $status): bool
    {
        $status = trim((string) $status);
        if ($status === '') {
            return false;
        }
        if (function_exists('mb_strtolower')) {
            $status = mb_strtolower($status, 'UTF-8');
        } else {
            $status = strtolower($status);
        }

        $passiveValues = ['pasif', 'passive', 'inactive', 'deleted', 'silindi', 'invalid', 'geçersiz', 'gecersiz', '0'];
        return in_array($status, $passiveValues, true);
    }
    
    /**
     * Bulk insert ile email'leri ekle (RAW SQL - SUPER FAST)
     * Yeni eklenen mailler aktif durumda eklenir
     */
    private function bulkInsertEmails(array $emails, int $poolListId): int
    {
        $conn = $this->em->getConnection();
        $now = (new \DateTime())->format('Y-m-d H:i:s');
        
        $batchSize = 5000;
        $batches = array_chunk($emails, $batchSize);
        $totalAdded = 0;
        
        foreach ($batches as $batch) {
            $values = [];
            $params = [];
            $types = [];
            $withNormalization = $this->hasNormalizationColumns();
            
            foreach ($batch as $item) {
                $email = strtolower(trim((string) ($item['email'] ?? '')));
                $name = $item['name'] ?? null;
                $values[] = $withNormalization ? "(?, ?, ?, ?, ?, ?, ?, ?)" : "(?, ?, ?, ?, ?, ?)";
                $params[] = $email;
                $params[] = $item['name'];
                $params[] = 1;
                $params[] = $now;
                $params[] = $now;
                $params[] = $poolListId;
                if ($withNormalization) {
                    $params[] = $email;
                    $params[] = $this->extractDomain($email);
                }
                $types[] = \PDO::PARAM_STR;
                $types[] = $name ? \PDO::PARAM_STR : \PDO::PARAM_NULL;
                $types[] = \PDO::PARAM_INT;
                $types[] = \PDO::PARAM_STR;
                $types[] = \PDO::PARAM_STR;
                $types[] = \PDO::PARAM_INT;
                if ($withNormalization) {
                    $types[] = \PDO::PARAM_STR;
                    $types[] = \PDO::PARAM_STR;
                }
            }
            
            $sql = $withNormalization
                ? "INSERT INTO email_data_pool (email, name, is_active, created_at, updated_at, pool_list_id, normalized_email, domain) VALUES " . implode(", ", $values)
                : "INSERT INTO email_data_pool (email, name, is_active, created_at, updated_at, pool_list_id) VALUES " . implode(", ", $values);
            
            $conn->executeStatement($sql, $params, $types);
            $totalAdded += count($batch);
        }
        
        $this->em->clear();
        
        return $totalAdded;
    }

    /**
     * Toplu mail çıkarma/silme (Bulk remove)
     */
    public function remove(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $listId = (int) ($data['pool_list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);
        $startTime = microtime(true);

        try {
            // Performance ayarları
            ini_set('memory_limit', '1024M');
            ini_set('max_execution_time', '600');
            
            $rawInput = (string) ($data['emails'] ?? '');
            $parseResult = $this->parseImportInput($rawInput, false);
            $emailAddresses = array_column($parseResult['records'], 'email');
            
            // Benzersiz yap
            $emailAddresses = array_unique($emailAddresses);
            
            if (empty($emailAddresses)) {
                $_SESSION['error'] = 'Geçerli mail adresi bulunamadı';
                return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
            }

            $removeAllLists = (($data['remove_scope'] ?? '') === 'all_lists');
            $listIdForDelete = $removeAllLists ? null : $poolList->getId();
            $affectedListIds = $this->findImpactedListIdsForEmails($emailAddresses, $listIdForDelete);

            // Bulk delete ile sil (RAW SQL — listId null ise tüm pool listeleri)
            $removed = $this->bulkDeleteEmails($emailAddresses, $listIdForDelete);
            if ($removed > 0 && $affectedListIds !== []) {
                $this->recalculateListCounts($affectedListIds);
            }

            $elapsed = round(microtime(true) - $startTime, 2);

            if ($removed > 0) {
                if ($removeAllLists) {
                    $_SESSION['success'] = "{$removed} kayıt {$elapsed}s içinde tüm havuz listelerinden silindi (aynı adres birden fazla listede varsa birden fazla satır silinir)";
                } else {
                    $_SESSION['success'] = "{$removed} mail adresi {$elapsed}s içinde bu listeden çıkarıldı";
                }
            } else {
                $_SESSION['error'] = $removeAllLists
                    ? 'Belirtilen mail adresleri hiçbir havuz listesinde bulunamadı'
                    : 'Belirtilen mail adresleri bu listede bulunamadı';
            }

        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::remove error: ' . $e->getMessage());
            $_SESSION['error'] = $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Mail çıkarma sırasında bir hata oluştu.';
        }

        return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
    }

    /**
     * Bulk delete ile email'leri sil (RAW SQL - SUPER FAST)
     */
    private function bulkDeleteEmails(array $emailAddresses, ?int $poolListId = null): int
    {
        $conn = $this->em->getConnection();
        
        $batchSize = 5000;
        $batches = array_chunk($emailAddresses, $batchSize);
        $totalRemoved = 0;
        
        foreach ($batches as $batch) {
            // IN clause ile bulk delete
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $sql = "DELETE FROM email_data_pool WHERE email IN ($placeholders)";
            $params = $batch;
            if ($poolListId !== null) {
                $sql .= ' AND pool_list_id = ?';
                $params[] = $poolListId;
            }
            
            $affectedRows = $conn->executeStatement($sql, $params);
            $totalRemoved += $affectedRows;
        }
        
        // Entity manager'ı temizle
        $this->em->clear();
        
        return $totalRemoved;
    }

    /**
     * Mail silme (OPTIMIZED)
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $data = $request->getParsedBody() ?? [];
        $listId = (int) ($data['pool_list_id'] ?? 0);
        $redirect = $this->poolListRedirect($listId > 0 ? $listId : null);

        try {
            $id = (int) $args['id'];
            $conn = $this->em->getConnection();
            $row = $conn->fetchAssociative('SELECT pool_list_id FROM email_data_pool WHERE id = ?', [$id]);
            $deleted = $conn->executeStatement('DELETE FROM email_data_pool WHERE id = ?', [$id]);
            
            if ($deleted > 0) {
                if (!empty($row['pool_list_id'])) {
                    $this->recalculateListCounts([(int) $row['pool_list_id']]);
                }
                $_SESSION['success'] = 'Mail havuzdan çıkarıldı';
            } else {
                $_SESSION['error'] = 'Mail bulunamadı';
            }
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::delete error: ' . $e->getMessage());
            $_SESSION['error'] = 'Mail silinirken bir hata oluştu.';
        }

        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    /**
     * Toplu silme (OPTIMIZED - RAW SQL)
     */
    public function bulkDelete(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $ids = $data['ids'] ?? [];
        $listId = (int) ($data['pool_list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);

        if (empty($ids)) {
            $_SESSION['error'] = 'Silinecek mail seçilmedi';
            return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
        }

        try {
            $startTime = microtime(true);
            $conn = $this->em->getConnection();
            
            // Convert to integers for security
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            
            // Raw SQL bulk delete
            $sql = "DELETE FROM email_data_pool WHERE id IN ($placeholders) AND pool_list_id = ?";
            $params = array_merge($ids, [$poolList->getId()]);
            $deleted = $conn->executeStatement($sql, $params);
            if ($deleted > 0) {
                $this->recalculateListCounts([(int) $poolList->getId()]);
            }
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = "{$deleted} mail {$elapsed}s içinde havuzdan silindi";

        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::bulkDelete error: ' . $e->getMessage());
            $_SESSION['error'] = 'Toplu silme sırasında bir hata oluştu.';
        }

        return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
    }

    /**
     * Seçili listedeki tüm kayıtları sil
     */
    public function deleteAll(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $listId = (int) ($data['pool_list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);

        try {
            $startTime = microtime(true);
            $conn = $this->em->getConnection();
            
            $deleted = $conn->executeStatement(
                'DELETE FROM email_data_pool WHERE pool_list_id = ?',
                [$poolList->getId()]
            );
            $this->setListCounts((int) $poolList->getId(), 0, 0, 0);
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = sprintf('"%s" listesindeki %d kayıt %ss içinde silindi', $poolList->getName(), (int) $deleted, $elapsed);

        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::deleteAll error: ' . $e->getMessage());
            $_SESSION['error'] = 'Liste temizleme sırasında bir hata oluştu.';
        }

        return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
    }

    /**
     * Toplu aktif yap (OPTIMIZED - RAW SQL)
     */
    public function bulkActivate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $ids = $data['ids'] ?? [];
        $listId = (int) ($data['pool_list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);

        if (empty($ids)) {
            $_SESSION['error'] = 'Aktif yapılacak mail seçilmedi';
            return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
        }

        try {
            $startTime = microtime(true);
            $conn = $this->em->getConnection();
            
            // Convert to integers for security
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $toActivate = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM email_data_pool WHERE id IN ($placeholders) AND pool_list_id = ? AND is_active = 0",
                array_merge($ids, [$poolList->getId()])
            );
            
            // Raw SQL bulk update
            $sql = "UPDATE email_data_pool SET is_active = 1, updated_at = NOW() WHERE id IN ($placeholders) AND pool_list_id = ?";
            $params = array_merge($ids, [$poolList->getId()]);
            $updated = $conn->executeStatement($sql, $params);
            if ($toActivate > 0) {
                $this->incrementListCounts((int) $poolList->getId(), 0, $toActivate, -$toActivate);
            }
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = "{$updated} mail {$elapsed}s içinde aktif yapıldı";

        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::bulkActivate error: ' . $e->getMessage());
            $_SESSION['error'] = 'Toplu aktif etme sırasında bir hata oluştu.';
        }

        return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
    }

    /**
     * Toplu pasif yap (OPTIMIZED - RAW SQL)
     */
    public function bulkDeactivate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $ids = $data['ids'] ?? [];
        $listId = (int) ($data['pool_list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);

        if (empty($ids)) {
            $_SESSION['error'] = 'Pasif yapılacak mail seçilmedi';
            return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
        }

        try {
            $startTime = microtime(true);
            $conn = $this->em->getConnection();
            
            // Convert to integers for security
            $ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $toDeactivate = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM email_data_pool WHERE id IN ($placeholders) AND pool_list_id = ? AND is_active = 1",
                array_merge($ids, [$poolList->getId()])
            );
            
            // Raw SQL bulk update
            $sql = "UPDATE email_data_pool SET is_active = 0, updated_at = NOW() WHERE id IN ($placeholders) AND pool_list_id = ?";
            $params = array_merge($ids, [$poolList->getId()]);
            $updated = $conn->executeStatement($sql, $params);
            if ($toDeactivate > 0) {
                $this->incrementListCounts((int) $poolList->getId(), 0, -$toDeactivate, $toDeactivate);
            }
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = "{$updated} mail {$elapsed}s içinde pasif yapıldı";

        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::bulkDeactivate error: ' . $e->getMessage());
            $_SESSION['error'] = 'Toplu pasif etme sırasında bir hata oluştu.';
        }

        return $response->withHeader('Location', $this->poolListRedirect($poolList->getId()))->withStatus(302);
    }

    /**
     * Excel export — PhpSpreadsheet bellekte tuttuğu için satır üst sınırı vardır; büyük listelerde CSV kullanılmalı.
     */
    public function exportExcel(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $listId = (int) ($params['list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);
        $listPk = (int) $poolList->getId();

        $maxRows = max(1000, min(100000, (int) ($_ENV['EMAIL_POOL_EXCEL_EXPORT_MAX_ROWS'] ?? 50000)));
        $total = $this->countPoolExportRows($listPk, $search);
        if ($total > $maxRows) {
            $_SESSION['error'] = sprintf(
                'Bu liste yaklaşık %s kayıt içeriyor; Excel en fazla %s satır aktarabilir. Milyonlarca kayıt için lütfen CSV indirmeyi kullanın (sunucu ve tarayıcı zaman aşımı riski olmadan).',
                number_format($total),
                number_format($maxRows)
            );

            return $response->withHeader('Location', $this->poolListRedirect($listPk))->withStatus(302);
        }

        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', '1024M');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->setCellValue('A1', 'ID');
        $sheet->setCellValue('B1', 'Mail Adresi');
        $sheet->setCellValue('C1', 'İsim');
        $sheet->setCellValue('D1', 'Durum');
        $sheet->setCellValue('E1', 'Tarih');

        $headerStyle = [
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ],
        ];
        $sheet->getStyle('A1:E1')->applyFromArray($headerStyle);

        $batchSize = 2000;
        $lastId = 0;
        $row = 2;

        do {
            $batch = $this->fetchPoolExportBatch($listPk, $search, $lastId, $batchSize);
            foreach ($batch as $r) {
                $lastId = (int) ($r['id'] ?? 0);
                $sheet->setCellValue('A' . $row, $lastId);
                $sheet->setCellValue('B' . $row, (string) ($r['email'] ?? ''));
                $sheet->setCellValue('C' . $row, (string) ($r['name'] ?? ''));
                $sheet->setCellValue('D' . $row, $this->exportRowIsActive($r) ? 'Aktif' : 'Pasif');
                $sheet->setCellValue('E' . $row, $this->formatExportCreatedAt($r['created_at'] ?? ''));
                $row++;
            }
            $this->em->clear();
        } while (count($batch) === $batchSize);

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $poolList->getName());
        $filename = 'mail_havuzu_' . $safeName . '_' . date('Y-m-d_His') . '.xlsx';

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $excelOutput = ob_get_clean();

        $response->getBody()->write($excelOutput);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * CSV export — disk üzerinde akışlı yazım; milyonlarca satırda bellekte tek dize biriktirilmez (OFFSET yerine id ile sayfalama).
     */
    public function exportCsv(Request $request, Response $response): Response
    {
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', (string) ($_ENV['EMAIL_POOL_EXPORT_MEMORY_LIMIT'] ?? '1024M'));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $listId = (int) ($params['list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);
        $listPk = (int) $poolList->getId();

        try {
            $tmpPath = $this->writePoolListCsvToTempFile($listPk, $search);
        } catch (\Throwable $e) {
            $_SESSION['error'] = 'CSV oluşturulamadı: ' . $e->getMessage();

            return $response->withHeader('Location', $this->poolListRedirect($listPk))->withStatus(302);
        }

        $resource = fopen($tmpPath, 'rb');
        if ($resource === false) {
            @unlink($tmpPath);
            $_SESSION['error'] = 'CSV dosyası okunamadı.';

            return $response->withHeader('Location', $this->poolListRedirect($listPk))->withStatus(302);
        }

        register_shutdown_function(static function () use ($tmpPath): void {
            @unlink($tmpPath);
        });

        $stream = new \Slim\Psr7\Stream($resource);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $poolList->getName());
        $filename = 'mail_havuzu_' . $safeName . '_' . date('Y-m-d_His') . '.csv';

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    /**
     * TXT export — her satırda tek email olacak şekilde akışlı oluşturulur.
     */
    public function exportTxt(Request $request, Response $response): Response
    {
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', (string) ($_ENV['EMAIL_POOL_EXPORT_MEMORY_LIMIT'] ?? '1024M'));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        $params = $request->getQueryParams();
        $search = trim((string) ($params['search'] ?? ''));
        $listId = (int) ($params['list_id'] ?? 0);
        $poolList = $this->resolvePoolListById($listId);
        $listPk = (int) $poolList->getId();

        try {
            $tmpPath = $this->writePoolListTxtToTempFile($listPk, $search);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::exportTxt error: ' . $e->getMessage());
            $_SESSION['error'] = 'TXT oluşturulamadı.';

            return $response->withHeader('Location', $this->poolListRedirect($listPk))->withStatus(302);
        }

        $resource = fopen($tmpPath, 'rb');
        if ($resource === false) {
            @unlink($tmpPath);
            $_SESSION['error'] = 'TXT dosyası okunamadı.';

            return $response->withHeader('Location', $this->poolListRedirect($listPk))->withStatus(302);
        }

        register_shutdown_function(static function () use ($tmpPath): void {
            @unlink($tmpPath);
        });

        $stream = new \Slim\Psr7\Stream($resource);
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $poolList->getName());
        $filename = 'mail_havuzu_' . $safeName . '_' . date('Y-m-d_His') . '.txt';

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0');
    }

    private function countPoolExportRows(int $poolListId, string $search): int
    {
        $conn = $this->em->getConnection();
        $sql = 'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?';
        $params = [$poolListId];
        if ($search !== '') {
            $sql .= ' AND (email LIKE ? OR name LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
        }

        return (int) $conn->fetchOne($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchPoolExportBatch(int $poolListId, string $search, int $lastId, int $limit): array
    {
        $limit = max(1, min(50000, $limit));
        $conn = $this->em->getConnection();
        $sql = 'SELECT id, email, name, is_active, created_at FROM email_data_pool WHERE pool_list_id = ? AND id > ?';
        $params = [$poolListId, $lastId];
        if ($search !== '') {
            $sql .= ' AND (email LIKE ? OR name LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $limit;

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * TXT export için en hafif payload: sadece id + email.
     *
     * @return list<array{id:mixed,email:mixed}>
     */
    private function fetchPoolExportTxtBatch(int $poolListId, string $search, int $lastId, int $limit): array
    {
        $limit = max(1, min(100000, $limit));
        $conn = $this->em->getConnection();
        $sql = 'SELECT id, email FROM email_data_pool WHERE pool_list_id = ? AND id > ?';
        $params = [$poolListId, $lastId];
        if ($search !== '') {
            $sql .= ' AND (email LIKE ? OR name LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $limit;

        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function exportRowIsActive(array $row): bool
    {
        $v = $row['is_active'] ?? false;

        return $v === true || $v === 1 || $v === '1';
    }

    private function formatExportCreatedAt(mixed $createdAt): string
    {
        if ($createdAt instanceof \DateTimeInterface) {
            return $createdAt->format('Y-m-d H:i:s');
        }
        $s = trim((string) $createdAt);

        return $s !== '' ? $s : '';
    }

    /**
     * UTF-8 BOM + başlık; satırlar toplu yazılır, bellekte tüm CSV tutulmaz.
     */
    private function writePoolListCsvToTempFile(int $poolListId, string $search): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'edpcsv');
        if ($tmpPath === false) {
            throw new \RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Geçici dosya açılamadı.');
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, ['ID', 'Mail Adresi', 'İsim', 'Durum', 'Tarih']);

        $batchSize = max(2000, min(20000, (int) ($_ENV['EMAIL_POOL_CSV_EXPORT_BATCH'] ?? 10000)));
        $lastId = 0;

        do {
            $batch = $this->fetchPoolExportBatch($poolListId, $search, $lastId, $batchSize);
            foreach ($batch as $r) {
                $lastId = (int) ($r['id'] ?? 0);
                fputcsv($fp, [
                    $lastId,
                    (string) ($r['email'] ?? ''),
                    (string) ($r['name'] ?? ''),
                    $this->exportRowIsActive($r) ? 'Aktif' : 'Pasif',
                    $this->formatExportCreatedAt($r['created_at'] ?? ''),
                ]);
            }
        } while (count($batch) === $batchSize);

        if (fclose($fp) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('CSV dosyası kapatılamadı.');
        }

        return $tmpPath;
    }

    private function writePoolListTxtToTempFile(int $poolListId, string $search): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'edptxt');
        if ($tmpPath === false) {
            throw new \RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Geçici dosya açılamadı.');
        }

        $batchSize = max(5000, min(100000, (int) ($_ENV['EMAIL_POOL_TXT_EXPORT_BATCH'] ?? 50000)));
        $lastId = 0;
        do {
            $batch = $this->fetchPoolExportTxtBatch($poolListId, $search, $lastId, $batchSize);
            foreach ($batch as $row) {
                $lastId = (int) ($row['id'] ?? 0);
                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '') {
                    fwrite($fp, $email . PHP_EOL);
                }
            }
        } while (count($batch) === $batchSize);

        if (fclose($fp) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('TXT dosyası kapatılamadı.');
        }

        return $tmpPath;
    }

    private function incrementListCounts(int $listId, int $totalDelta, int $activeDelta, int $passiveDelta): void
    {
        if ($listId < 1) {
            return;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE email_data_pool_lists
                SET total_count = GREATEST(0, total_count + ?),
                    active_count = GREATEST(0, active_count + ?),
                    passive_count = GREATEST(0, passive_count + ?),
                    updated_count_at = NOW()
              WHERE id = ?',
            [$totalDelta, $activeDelta, $passiveDelta, $listId]
        );
        $this->syncPoolStatsFromCache($listId);
    }

    private function setListCounts(int $listId, int $total, int $active, int $passive): void
    {
        if ($listId < 1) {
            return;
        }

        $this->em->getConnection()->executeStatement(
            'UPDATE email_data_pool_lists
                SET total_count = ?,
                    active_count = ?,
                    passive_count = ?,
                    updated_count_at = NOW()
              WHERE id = ?',
            [max(0, $total), max(0, $active), max(0, $passive), $listId]
        );
        $this->syncPoolStatsFromCache($listId);
    }

    /**
     * @param array<int, int> $listIds
     */
    private function recalculateListCounts(array $listIds): void
    {
        $listIds = array_values(array_unique(array_map('intval', $listIds)));
        $listIds = array_values(array_filter($listIds, static fn (int $id) => $id > 0));
        if ($listIds === []) {
            return;
        }

        $conn = $this->em->getConnection();
        foreach ($listIds as $listId) {
            $row = $conn->fetchAssociative(
                'SELECT COUNT(*) AS total_count,
                        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
                   FROM email_data_pool
                  WHERE pool_list_id = ?',
                [$listId]
            ) ?: [];
            $total = (int) ($row['total_count'] ?? 0);
            $active = (int) ($row['active_count'] ?? 0);
            $this->setListCounts($listId, $total, $active, max(0, $total - $active));
        }
    }

    /**
     * @param array<int, string> $emails
     * @return array<int, int>
     */
    private function findImpactedListIdsForEmails(array $emails, ?int $poolListId = null): array
    {
        $emails = array_values(array_unique(array_filter(array_map('strval', $emails), static fn (string $email) => $email !== '')));
        if ($emails === []) {
            return [];
        }

        $conn = $this->em->getConnection();
        $batchSize = 2000;
        $listIds = [];
        foreach (array_chunk($emails, $batchSize) as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $sql = "SELECT DISTINCT pool_list_id FROM email_data_pool WHERE email IN ($placeholders)";
            $params = $batch;
            if ($poolListId !== null) {
                $sql .= ' AND pool_list_id = ?';
                $params[] = $poolListId;
            }
            $rows = $conn->fetchFirstColumn($sql, $params);
            foreach ($rows as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $listIds[$id] = $id;
                }
            }
        }

        return array_values($listIds);
    }

    public function cleanerAnalyze(Request $request, Response $response, array $args): Response
    {
        try {
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $stats = $this->buildCleanerStats((int) $poolList->getId());

            return $this->jsonResponse($response, $stats);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerAnalyze error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Analiz sırasında hata oluştu.'], 500);
        }
    }

    public function cleanerPreview(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $operation = (string) ($data['operation'] ?? '');
            $stats = $this->buildCachedListStats((int) $poolList->getId(), 0);
            $nonGmail = (int) ($stats['non_gmail_count'] ?? 0);
            $typo = (int) ($stats['typo_gmail_count'] ?? 0);
            $duplicate = (int) ($stats['duplicate_count'] ?? 0);

            return match ($operation) {
                'delete_non_gmail' => $this->jsonResponse($response, [
                    'success' => true,
                    'operation' => $operation,
                    'count' => $nonGmail,
                    'message' => $nonGmail > 0 ? 'Gmail dışı kayıtlar silinecek.' : 'Silinecek Gmail dışı kayıt bulunamadı.',
                ]),
                'fix_typo_gmail' => $this->jsonResponse($response, [
                    'success' => true,
                    'operation' => $operation,
                    'count' => $typo,
                    'message' => $typo > 0 ? 'Hatalı Gmail alan adları düzeltilecek.' : 'Düzeltilecek hatalı Gmail kaydı bulunamadı.',
                ]),
                'remove_duplicates' => $this->jsonResponse($response, [
                    'success' => true,
                    'operation' => $operation,
                    'count' => $duplicate,
                    'message' => $duplicate > 0 ? 'Duplicate kayıtlar silinecek.' : 'Silinecek duplicate kayıt bulunamadı.',
                ]),
                default => $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz preview işlemi.'], 400),
            };
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 404;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerPreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Önizleme alınamadı.'], 500);
        }
    }

    public function cleanerExportNonGmail(Request $request, Response $response, array $args): Response
    {
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', (string) ($_ENV['EMAIL_POOL_EXPORT_MEMORY_LIMIT'] ?? '1024M'));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        try {
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $tmpPath = $this->writeNonGmailTxtToTempFile((int) $poolList->getId());
            $resource = fopen($tmpPath, 'rb');
            if ($resource === false) {
                @unlink($tmpPath);
                throw new \RuntimeException('Dosya açılamadı.');
            }

            register_shutdown_function(static function () use ($tmpPath): void {
                @unlink($tmpPath);
            });

            $stream = new \Slim\Psr7\Stream($resource);
            $filename = sprintf('gmail-disi-liste-%d-%s.txt', (int) $poolList->getId(), date('Y-m-d'));

            return $response
                ->withBody($stream)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerExportNonGmail error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Dışa aktarma sırasında hata oluştu.'], 500);
        }
    }

    public function cleanerExportTypoGmail(Request $request, Response $response, array $args): Response
    {
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', (string) ($_ENV['EMAIL_POOL_EXPORT_MEMORY_LIMIT'] ?? '1024M'));
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        try {
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $tmpPath = $this->writeTypoGmailTxtToTempFile((int) $poolList->getId());
            $resource = fopen($tmpPath, 'rb');
            if ($resource === false) {
                @unlink($tmpPath);
                throw new \RuntimeException('Dosya açılamadı.');
            }

            register_shutdown_function(static function () use ($tmpPath): void {
                @unlink($tmpPath);
            });

            $stream = new \Slim\Psr7\Stream($resource);
            $filename = sprintf('gmail-hatali-domain-%d-%s.txt', (int) $poolList->getId(), date('Y-m-d'));

            return $response
                ->withBody($stream)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerExportTypoGmail error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Dışa aktarma sırasında hata oluştu.'], 500);
        }
    }

    public function startExportJob(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $scope = (string) ($data['scope'] ?? 'full_csv');
            if (!in_array($scope, ['full_csv', 'non_gmail', 'typo_gmail'], true)) {
                throw new \RuntimeException('Geçersiz export tipi.');
            }

            $estimated = match ($scope) {
                'non_gmail' => (int) $this->em->getConnection()->fetchOne(
                    "SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ? AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'",
                    [(int) $poolList->getId()]
                ),
                'typo_gmail' => (int) $this->em->getConnection()->fetchOne(
                    "SELECT COUNT(*) FROM email_data_pool
                      WHERE pool_list_id = ?
                        AND INSTR(email, '@') > 1
                        AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN (" . implode(',', array_fill(0, count(self::GMAIL_TYPO_DOMAINS), '?')) . ')',
                    array_merge([(int) $poolList->getId()], self::GMAIL_TYPO_DOMAINS)
                ),
                default => $this->getListTotalCountFast((int) $poolList->getId(), (int) $poolList->getTotalCount()),
            };

            $jobId = $this->createDataPoolJob((int) $poolList->getId(), 'export_pool', [
                'scope' => $scope,
                'list_name' => $poolList->getName(),
            ], $estimated);

            return $this->jsonResponse($response, [
                'success' => true,
                'queued' => true,
                'message' => 'Export işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'poolId' => (int) $poolList->getId(),
                    'status' => 'queued',
                    'scope' => $scope,
                    'estimated' => $estimated,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::startExportJob error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Export kuyruğa alınamadı.'], 500);
        }
    }

    public function downloadExportFile(Request $request, Response $response, array $args): Response
    {
        try {
            $file = basename((string) ($args['file'] ?? ''));
            if ($file === '' || str_contains($file, '..')) {
                throw new \RuntimeException('Dosya bulunamadı.');
            }

            $exportPath = rtrim((string) ($_ENV['DATA_POOL_EXPORT_PATH'] ?? 'storage/exports'), '/');
            $baseDir = dirname(__DIR__, 2) . '/' . ltrim($exportPath, '/');
            $fullPath = $baseDir . '/' . $file;
            if (!is_file($fullPath) || !is_readable($fullPath)) {
                throw new \RuntimeException('Export dosyası bulunamadı.');
            }

            $resource = fopen($fullPath, 'rb');
            if ($resource === false) {
                throw new \RuntimeException('Export dosyası açılamadı.');
            }

            $mime = str_ends_with($file, '.txt') ? 'text/plain; charset=utf-8' : 'text/csv; charset=utf-8';
            return $response
                ->withBody(new \Slim\Psr7\Stream($resource))
                ->withHeader('Content-Type', $mime)
                ->withHeader('Content-Disposition', 'attachment; filename="' . $file . '"')
                ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::downloadExportFile error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Export dosyası indirilemedi.'], 500);
        }
    }

    public function cleanerDeleteNonGmail(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $estimated = (int) $this->em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ? AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'",
                [(int) $poolList->getId()]
            );
            $jobId = $this->createDataPoolJob((int) $poolList->getId(), 'remove_non_gmail', [], $estimated);

            return $this->jsonResponse($response, [
                'success' => true,
                'queued' => true,
                'message' => 'Gmail dışı silme işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'poolId' => (int) $poolList->getId(),
                    'status' => 'queued',
                    'estimated' => $estimated,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 404;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerDeleteNonGmail error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Silme sırasında hata oluştu.'], 500);
        }
    }

    public function cleanerFixGmailTypos(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $typoInPlaceholders = implode(',', array_fill(0, count(self::GMAIL_TYPO_DOMAINS), '?'));
            $estimated = (int) $this->em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND INSTR(email, '@') > 1
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoInPlaceholders)",
                array_merge([(int) $poolList->getId()], self::GMAIL_TYPO_DOMAINS)
            );
            $jobId = $this->createDataPoolJob((int) $poolList->getId(), 'fix_gmail_typos', [], $estimated);

            return $this->jsonResponse($response, [
                'success' => true,
                'queued' => true,
                'message' => 'Gmail typo düzeltme işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'poolId' => (int) $poolList->getId(),
                    'status' => 'queued',
                    'estimated' => $estimated,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 404;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerFixGmailTypos error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Düzeltme sırasında hata oluştu.'], 500);
        }
    }

    public function cleanerRemoveDuplicates(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $estimated = (int) $this->em->getConnection()->fetchOne(
                'SELECT COALESCE(SUM(t.cnt - 1), 0)
                   FROM (
                        SELECT COUNT(*) AS cnt
                          FROM email_data_pool
                         WHERE pool_list_id = ?
                         GROUP BY COALESCE(normalized_email, LOWER(TRIM(email)))
                        HAVING COUNT(*) > 1
                   ) t',
                [(int) $poolList->getId()]
            );
            $jobId = $this->createDataPoolJob((int) $poolList->getId(), 'remove_duplicates', [], $estimated);

            return $this->jsonResponse($response, [
                'success' => true,
                'queued' => true,
                'message' => 'Duplicate temizleme işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'poolId' => (int) $poolList->getId(),
                    'status' => 'queued',
                    'estimated' => $estimated,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 404;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::cleanerRemoveDuplicates error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Duplicate temizleme sırasında hata oluştu.'], 500);
        }
    }

    public function stats(Request $request, Response $response, array $args): Response
    {
        try {
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $targetLimit = max(0, (int) ($request->getQueryParams()['target_limit'] ?? ($_ENV['EMAIL_POOL_DEFAULT_TARGET_LIMIT'] ?? self::DEFAULT_TARGET_LIMIT)));
            $stats = $this->buildCachedListStats((int) $poolList->getId(), $targetLimit);
            try {
                $runningJob = $this->getRunningDataPoolJob((int) $poolList->getId(), ['analyze_pool', 'remove_non_gmail', 'fix_gmail_typos', 'remove_duplicates']);
                if ($runningJob !== null) {
                    $stats['running_job'] = $this->formatDataPoolJobPayload($runningJob);
                    if (($runningJob['type'] ?? '') === 'analyze_pool') {
                        $stats['analysis_status'] = (string) ($runningJob['status'] ?? 'running');
                    }
                }
            } catch (\Throwable) {
            }

            return $this->jsonResponse($response, array_merge(['success' => true], $stats));
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::stats error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'İstatistikler yüklenemedi.'], 500);
        }
    }

    public function startAnalysis(Request $request, Response $response, array $args): Response
    {
        $lockName = '';
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $lockName = $this->acquireListLock((int) $poolList->getId());
            $totalCount = $this->getListTotalCountFast((int) $poolList->getId(), (int) $poolList->getTotalCount());
            $running = $this->getLatestPendingOrRunningDataPoolJob((int) $poolList->getId(), ['analyze_pool']);
            if ($running !== null) {
                $payload = $this->formatDataPoolJobPayload($running);
                $payload['already_running'] = true;
                $payload['message'] = 'Bu liste için analiz zaten kuyrukta/çalışıyor.';
                return $this->jsonResponse($response, $payload);
            }

            $jobId = $this->createDataPoolJob((int) $poolList->getId(), 'analyze_pool', [
                'chunk_size' => max(2000, min(20000, (int) ($_ENV['EMAIL_POOL_ANALYSIS_CHUNK_SIZE'] ?? 12000))),
            ], $totalCount);
            $this->upsertAnalysisCache([
                'list_id' => (int) $poolList->getId(),
                'status' => 'queued',
                'error_message' => null,
                'last_analyzed_at' => null,
            ]);
            return $this->jsonResponse($response, [
                'success' => true,
                'already_running' => false,
                'message' => 'Analiz kuyruğa alındı.',
                'job_id' => $jobId,
                'list_id' => (int) $poolList->getId(),
                'status' => 'queued',
                'total' => $totalCount,
                'processed' => 0,
                'percent' => 0,
                'data' => [
                    'jobId' => $jobId,
                    'poolId' => (int) $poolList->getId(),
                    'status' => 'queued',
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = $this->runtimeStatusCode($e);
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::startAnalysis error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Analiz başlatılamadı.'], 500);
        } finally {
            $this->releaseListLock($lockName);
        }
    }

    public function analysisStatus(Request $request, Response $response, array $args): Response
    {
        try {
            $jobId = (int) ($args['jobId'] ?? 0);
            if ($jobId < 1) {
                throw new \RuntimeException('Analiz işi bulunamadı.');
            }
            $job = $this->getDataPoolJob($jobId);
            if ($job === null) {
                throw new \RuntimeException('Analiz işi bulunamadı.');
            }
            return $this->jsonResponse($response, $this->formatDataPoolJobPayload($job));
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::analysisStatus error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Analiz durumu alınamadı.'], 500);
        }
    }

    public function analysisStatusByList(Request $request, Response $response, array $args): Response
    {
        try {
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $job = $this->getRunningDataPoolJob((int) $poolList->getId(), ['analyze_pool']);
            if ($job === null) {
                $job = $this->getLatestDataPoolJob((int) $poolList->getId(), ['analyze_pool']);
            }
            if ($job === null) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'message' => 'Analiz işi bulunamadı.',
                    'data' => [
                        'jobId' => null,
                        'poolId' => (int) $poolList->getId(),
                        'status' => 'idle',
                        'processed' => 0,
                        'total' => 0,
                        'percent' => 0,
                        'gmailCount' => 0,
                        'nonGmailCount' => 0,
                        'invalidCount' => 0,
                        'duplicateCount' => 0,
                    ],
                ]);
            }
            return $this->jsonResponse($response, $this->formatDataPoolJobPayload($job));
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::analysisStatusByList error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Analiz durumu alınamadı.'], 500);
        }
    }

    public function toolsPreview(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $tool = (string) ($data['tool'] ?? '');

            return match ($tool) {
                'fill_to_target' => $this->jsonResponse($response, $this->previewFillToTarget($data)),
                'move_overflow' => $this->jsonResponse($response, $this->previewMoveOverflow($data)),
                'split_list' => $this->jsonResponse($response, $this->previewSplitList($data)),
                default => $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz preview aracı.'], 400),
            };
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsPreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Önizleme oluşturulamadı.'], 500);
        }
    }

    public function balanceCompletePreview(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            return $this->jsonResponse($response, ['success' => true, 'data' => $this->previewFillToTargetDetailed($data)]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::balanceCompletePreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Önizleme alınamadı.'], 500);
        }
    }

    public function balanceCompleteApply(Request $request, Response $response): Response
    {
        return $this->toolsFillToTarget($request, $response);
    }

    public function balanceOverflowPreview(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            return $this->jsonResponse($response, ['success' => true, 'data' => $this->previewMoveOverflowDetailed($data)]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::balanceOverflowPreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Önizleme alınamadı.'], 500);
        }
    }

    public function balanceOverflowApply(Request $request, Response $response): Response
    {
        return $this->toolsMoveOverflow($request, $response);
    }

    public function balanceSplitPreview(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $preview = $this->previewSplitList($data);
            return $this->jsonResponse($response, ['success' => true, 'data' => $preview]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::balanceSplitPreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Önizleme alınamadı.'], 500);
        }
    }

    public function balanceSplitApply(Request $request, Response $response): Response
    {
        return $this->toolsSplitList($request, $response);
    }

    public function balanceEqualizePreview(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $poolIdsRaw = (string) ($data['pool_ids'] ?? '');
            $poolIds = array_values(array_filter(array_unique(array_map('intval', preg_split('/[,\s]+/', $poolIdsRaw) ?: [])), static fn (int $id): bool => $id > 0));
            $targetLimit = max(1, (int) ($data['target_limit'] ?? self::DEFAULT_TARGET_LIMIT));
            if ($poolIds === []) {
                throw new \RuntimeException('En az bir liste seçin.');
            }
            $conn = $this->em->getConnection();
            $placeholders = implode(',', array_fill(0, count($poolIds), '?'));
            $rows = $conn->fetchAllAssociative(
                "SELECT id, name, total_count FROM email_data_pool_lists WHERE id IN ($placeholders) ORDER BY id ASC",
                $poolIds
            );
            $items = [];
            $totalDeficit = 0;
            $totalOverflow = 0;
            foreach ($rows as $row) {
                $current = (int) ($row['total_count'] ?? 0);
                $deficit = max(0, $targetLimit - $current);
                $overflow = max(0, $current - $targetLimit);
                $totalDeficit += $deficit;
                $totalOverflow += $overflow;
                $items[] = [
                    'poolId' => (int) ($row['id'] ?? 0),
                    'poolName' => (string) ($row['name'] ?? ''),
                    'current' => $current,
                    'targetLimit' => $targetLimit,
                    'deficit' => $deficit,
                    'overflow' => $overflow,
                ];
            }
            return $this->jsonResponse($response, ['success' => true, 'data' => [
                'operation' => 'balance_pools',
                'targetLimit' => $targetLimit,
                'items' => $items,
                'totalDeficit' => $totalDeficit,
                'totalOverflow' => $totalOverflow,
                'willMove' => min($totalDeficit, $totalOverflow),
            ]]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::balanceEqualizePreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Önizleme alınamadı.'], 500);
        }
    }

    public function balanceEqualizeApply(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $poolIdsRaw = (string) ($data['pool_ids'] ?? '');
            $poolIds = array_values(array_filter(array_unique(array_map('intval', preg_split('/[,\s]+/', $poolIdsRaw) ?: [])), static fn (int $id): bool => $id > 0));
            $targetLimit = max(1, (int) ($data['target_limit'] ?? self::DEFAULT_TARGET_LIMIT));
            if ($poolIds === []) {
                throw new \RuntimeException('En az bir liste seçin.');
            }
            $this->assertNoConflictingJobs($poolIds, self::BALANCE_JOB_TYPES);
            $jobId = $this->createDataPoolJob(
                $poolIds[0],
                'balance_pools',
                [
                    'operation' => 'balance_pools',
                    'pool_ids' => $poolIds,
                    'target_limit' => $targetLimit,
                    'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                0
            );
            return $this->jsonResponse($response, ['success' => true, 'message' => 'Liste dengeleme işlemi kuyruğa alındı.', 'data' => ['jobId' => $jobId, 'type' => 'balance_pools']]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::balanceEqualizeApply error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Dengeleme kuyruğa alınamadı.'], 500);
        }
    }

    public function globalAnalysisStart(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $this->ensureDataPoolJobTables();
            $this->assertNoConflictingJobs([], self::BALANCE_JOB_TYPES);
            $conn = $this->em->getConnection();
            $totalPools = (int) $conn->fetchOne('SELECT COUNT(*) FROM email_data_pool_lists');
            $totalRecords = (int) $conn->fetchOne('SELECT COALESCE(SUM(total_count), 0) FROM email_data_pool_lists');
            $jobId = $this->createDataPoolJob(
                0,
                'global_analyze_all_pools',
                ['requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
                $totalRecords
            );
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Tüm listeler için analiz kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'type' => 'global_analyze_all_pools',
                    'totalPools' => $totalPools,
                    'totalRecords' => $totalRecords,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::globalAnalysisStart error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Toplu analiz kuyruğa alınamadı.'], 500);
        }
    }

    public function globalStatsRefresh(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $this->ensureDataPoolJobTables();
            $this->assertNoConflictingJobs([], self::BALANCE_JOB_TYPES);
            $total = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(total_count), 0) FROM email_data_pool_lists');
            $jobId = $this->createDataPoolJob(
                0,
                'refresh_all_pool_stats',
                ['requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
                $total
            );
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Tüm liste istatistik yenileme kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'type' => 'refresh_all_pool_stats',
                ],
            ]);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $this->runtimeStatusCode($e));
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::globalStatsRefresh error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Global istatistik yenileme kuyruğa alınamadı.'], 500);
        }
    }

    public function alibabaInvalidStatus(Request $request, Response $response): Response
    {
        try {
            $this->ensureAlibabaInvalidTables();
            $conn = $this->em->getConnection();
            $lastFetch = $conn->fetchAssociative(
                "SELECT * FROM alibaba_invalid_fetch_logs ORDER BY id DESC LIMIT 1"
            ) ?: [];
            $lastCount = (int) ($conn->fetchOne('SELECT COUNT(*) FROM alibaba_invalid_addresses') ?? 0);
            $accessKeyId = trim((string) ($_ENV['ALIBABA_DM_ACCESS_KEY_ID'] ?? ''));
            $maskedAccessKeyId = $accessKeyId === '' ? '' : (substr($accessKeyId, 0, 4) . '****' . substr($accessKeyId, -4));

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'endpoint' => (string) ($_ENV['ALIBABA_DM_ENDPOINT'] ?? 'https://dm.aliyuncs.com/'),
                    'action' => (string) ($_ENV['ALIBABA_DM_INVALID_ACTION'] ?? 'QueryInvalidAddress'),
                    'accessKeyConfigured' => $accessKeyId !== '',
                    'secretConfigured' => trim((string) ($_ENV['ALIBABA_DM_ACCESS_KEY_SECRET'] ?? '')) !== '',
                    'maskedAccessKeyId' => $maskedAccessKeyId,
                    'lastSuccessfulFetchAt' => $lastFetch['finished_at'] ?? null,
                    'lastErrorMessage' => $lastFetch['error_message'] ?? null,
                    'lastFetchedInvalidCount' => (int) ($lastFetch['saved_count'] ?? 0),
                    'storedInvalidCount' => $lastCount,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::alibabaInvalidStatus error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Alibaba invalid durumu alınamadı.'], 500);
        }
    }

    public function alibabaInvalidFetch(Request $request, Response $response): Response
    {
        return $this->enqueueAlibabaInvalidJob($request, $response, 'alibaba_invalid_fetch');
    }

    public function alibabaInvalidPreview(Request $request, Response $response): Response
    {
        return $this->enqueueAlibabaInvalidJob($request, $response, 'alibaba_invalid_match_preview');
    }

    public function alibabaInvalidClean(Request $request, Response $response): Response
    {
        return $this->enqueueAlibabaInvalidJob($request, $response, 'alibaba_invalid_clean_apply');
    }

    public function alibabaInvalidFetchAndClean(Request $request, Response $response): Response
    {
        return $this->enqueueAlibabaInvalidJob($request, $response, 'alibaba_invalid_fetch_and_clean');
    }

    public function alibabaInvalidLogs(Request $request, Response $response): Response
    {
        try {
            $this->ensureAlibabaInvalidTables();
            $query = $request->getQueryParams();
            $limit = max(10, min(200, (int) ($query['limit'] ?? 50)));
            $rows = $this->em->getConnection()->fetchAllAssociative(
                "SELECT id, job_id, start_date, end_date, page_size, next_start, fetched_count, saved_count, matched_count, cleaned_count, retry_count, status, error_message, started_at, finished_at, created_at
                   FROM alibaba_invalid_fetch_logs
               ORDER BY id DESC
                  LIMIT {$limit}"
            );
            return $this->jsonResponse($response, ['success' => true, 'data' => $rows]);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::alibabaInvalidLogs error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Alibaba invalid logları alınamadı.'], 500);
        }
    }

    private function enqueueAlibabaInvalidJob(Request $request, Response $response, string $type): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $this->ensureDataPoolJobTables();
            $this->ensureAlibabaInvalidTables();
            $this->assertNoConflictingJobs([], array_merge(self::BALANCE_JOB_TYPES, self::ALIBABA_JOB_TYPES));
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $startDate = trim((string) ($data['start_date'] ?? ''));
            $endDate = trim((string) ($data['end_date'] ?? ''));
            if ($startDate === '' || $endDate === '') {
                throw new \RuntimeException('Başlangıç ve bitiş tarihi zorunludur.');
            }
            $pageSize = max(1, min(500, (int) ($data['length'] ?? ($_ENV['ALIBABA_DM_PAGE_SIZE'] ?? 500))));
            $scope = (string) ($data['scope'] ?? 'all_lists');
            $mode = (string) ($data['mode'] ?? 'mark_invalid');
            if (!in_array($mode, ['fetch_only', 'mark_invalid', 'hard_delete'], true)) {
                throw new \RuntimeException('Geçersiz temizleme modu.');
            }
            if ($mode === 'hard_delete' && !filter_var((string) ($data['hard_delete_confirmed'] ?? '0'), FILTER_VALIDATE_BOOLEAN)) {
                throw new \RuntimeException('Hard delete için onay zorunludur.');
            }
            $selectedPoolId = (int) ($data['selected_pool_id'] ?? 0);
            if ($scope === 'selected_list' && $selectedPoolId < 1) {
                throw new \RuntimeException('Seçili liste kapsamı için liste seçin.');
            }

            $payload = [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'length' => $pageSize,
                'scope' => $scope,
                'selected_pool_id' => $selectedPoolId > 0 ? $selectedPoolId : null,
                'mode' => $mode,
                'dry_run' => filter_var((string) ($data['dry_run'] ?? '0'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0,
                'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
            $jobId = $this->createDataPoolJob(0, $type, $payload, 0);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Alibaba invalid işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'type' => $type,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::enqueueAlibabaInvalidJob error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Alibaba invalid job kuyruğa alınamadı.'], 500);
        }
    }

    public function jobStatus(Request $request, Response $response, array $args): Response
    {
        try {
            $jobId = (int) ($args['jobId'] ?? 0);
            if ($jobId < 1) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            $job = $this->getDataPoolJob($jobId);
            if ($job === null) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            return $this->jsonResponse($response, $this->formatDataPoolJobPayload($job));
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::jobStatus error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Job durumu alınamadı.'], 500);
        }
    }

    public function jobs(Request $request, Response $response): Response
    {
        try {
            $this->ensureDataPoolJobTables();
            $query = $request->getQueryParams();
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(10, min(100, (int) ($query['per_page'] ?? 25)));
            $offset = ($page - 1) * $perPage;
            $status = trim((string) ($query['status'] ?? ''));
            $type = trim((string) ($query['type'] ?? ''));
            $poolId = (int) ($query['pool_id'] ?? 0);

            $where = [];
            $params = [];
            if ($status !== '') {
                $where[] = 'status = ?';
                $params[] = $status;
            }
            if ($type !== '') {
                $where[] = 'type = ?';
                $params[] = $type;
            }
            if ($poolId > 0) {
                $where[] = 'pool_id = ?';
                $params[] = $poolId;
            }
            $whereSql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

            $conn = $this->em->getConnection();
            $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM data_pool_jobs{$whereSql}", $params);
            $rows = $conn->fetchAllAssociative(
                "SELECT id, pool_id, type, status, total_count, processed_count, success_count, failed_count, progress_percent, result, error_message,
                        error_code, exception_class, failed_step, last_sql_name, current_step, status_message,
                        locked_by, locked_at, heartbeat_at, attempts, max_attempts, resumable, cancel_requested, last_processed_id, worker_id,
                        started_at, finished_at, created_at, updated_at
                   FROM data_pool_jobs{$whereSql}
               ORDER BY id DESC
                  LIMIT {$perPage} OFFSET {$offset}",
                $params
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => array_map(function (array $row): array {
                    $job = $this->getDataPoolJob((int) ($row['id'] ?? 0));
                    return $job ? $this->formatDataPoolJobPayload($job) : [];
                }, $rows),
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::jobs error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Job listesi alınamadı.'], 500);
        }
    }

    public function jobCancel(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $jobId = (int) ($args['jobId'] ?? 0);
            if ($jobId < 1) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            $this->ensureDataPoolJobTables();
            $affected = $this->em->getConnection()->executeStatement(
                "UPDATE data_pool_jobs
                    SET cancel_requested = 1,
                        status_message = 'Kullanıcı iptal talebi gönderdi.',
                        updated_at = NOW()
                  WHERE id = ?
                    AND status IN ('queued', 'running')",
                [$jobId]
            );
            if ($affected < 1) {
                throw new \RuntimeException('Sadece queued/running job iptal edilebilir.');
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'İptal talebi alındı.']);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::jobCancel error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Job iptal edilemedi.'], 500);
        }
    }

    public function jobRetry(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $jobId = (int) ($args['jobId'] ?? 0);
            if ($jobId < 1) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            $this->ensureDataPoolJobTables();
            $job = $this->getDataPoolJob($jobId);
            if ($job === null) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            if (!in_array((string) ($job['status'] ?? ''), ['failed', 'cancelled'], true)) {
                throw new \RuntimeException('Sadece failed/cancelled job tekrar kuyruğa alınabilir.');
            }
            if ((int) ($job['attempts'] ?? 0) >= (int) ($job['max_attempts'] ?? 3)) {
                throw new \RuntimeException('Job max deneme limitine ulaşmış.');
            }

            $this->em->getConnection()->executeStatement(
                "UPDATE data_pool_jobs
                    SET status = 'queued',
                        cancel_requested = 0,
                        error_message = NULL,
                        error_code = NULL,
                        exception_class = NULL,
                        failed_step = NULL,
                        last_sql_name = NULL,
                        locked_by = NULL,
                        locked_at = NULL,
                        heartbeat_at = NULL,
                        next_run_at = NOW(),
                        status_message = 'Kullanıcı tarafından retry edildi.',
                        finished_at = NULL,
                        updated_at = NOW()
                  WHERE id = ?",
                [$jobId]
            );

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Job tekrar kuyruğa alındı.']);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::jobRetry error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Job retry edilemedi.'], 500);
        }
    }

    public function jobResume(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $jobId = (int) ($args['jobId'] ?? 0);
            if ($jobId < 1) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            $this->ensureDataPoolJobTables();
            $job = $this->getDataPoolJob($jobId);
            if ($job === null) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            if (((int) ($job['resumable'] ?? 1)) !== 1) {
                throw new \RuntimeException('Bu job resume desteklemiyor.');
            }
            if (!in_array((string) ($job['status'] ?? ''), ['running', 'failed'], true)) {
                throw new \RuntimeException('Sadece running/failed job resume edilebilir.');
            }
            if ((int) ($job['attempts'] ?? 0) >= (int) ($job['max_attempts'] ?? 3)) {
                throw new \RuntimeException('Job max deneme limitine ulaştı.');
            }

            $this->em->getConnection()->executeStatement(
                "UPDATE data_pool_jobs
                    SET status = 'queued',
                        cancel_requested = 0,
                        error_message = NULL,
                        error_code = NULL,
                        exception_class = NULL,
                        failed_step = NULL,
                        last_sql_name = NULL,
                        locked_by = NULL,
                        locked_at = NULL,
                        heartbeat_at = NULL,
                        next_run_at = NOW(),
                        status_message = 'Job resume için kuyruğa alındı.',
                        finished_at = NULL,
                        updated_at = NOW()
                  WHERE id = ?",
                [$jobId]
            );

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Job resume için kuyruğa alındı.']);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::jobResume error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Job resume edilemedi.'], 500);
        }
    }

    public function jobMarkFailed(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $jobId = (int) ($args['jobId'] ?? 0);
            if ($jobId < 1) {
                throw new \RuntimeException('Job bulunamadı.');
            }
            $this->ensureDataPoolJobTables();
            $affected = $this->em->getConnection()->executeStatement(
                "UPDATE data_pool_jobs
                    SET status = 'failed',
                        error_message = COALESCE(NULLIF(error_message, ''), 'Operatör tarafından failed işaretlendi.'),
                        error_code = COALESCE(error_code, 'MANUAL_FAIL'),
                        exception_class = COALESCE(exception_class, 'RuntimeException'),
                        failed_step = COALESCE(failed_step, 'manual_mark_failed'),
                        status_message = 'Operatör tarafından failed işaretlendi.',
                        locked_by = NULL,
                        locked_at = NULL,
                        heartbeat_at = NOW(),
                        finished_at = NOW(),
                        updated_at = NOW()
                  WHERE id = ?
                    AND status IN ('queued', 'running')",
                [$jobId]
            );
            if ($affected < 1) {
                throw new \RuntimeException('Sadece queued/running job failed işaretlenebilir.');
            }

            return $this->jsonResponse($response, ['success' => true, 'message' => 'Job failed olarak işaretlendi.']);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::jobMarkFailed error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Job failed işaretleme başarısız.'], 500);
        }
    }

    public function globalDeduplicatePreview(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $this->ensureDataPoolJobTables();
            $this->assertNoConflictingJobs([], self::BALANCE_JOB_TYPES);
            $running = $this->getRunningGlobalDedupJob();
            if ($running !== null) {
                $payload = $this->formatDataPoolJobPayload($running);
                $payload['already_running'] = true;
                $payload['message'] = 'Global mükerrer işlemi zaten çalışıyor.';
                return $this->jsonResponse($response, $payload);
            }

            $total = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(total_count), 0) FROM email_data_pool_lists');
            $jobId = $this->createDataPoolJob(0, 'global_deduplicate_preview', [
                'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], $total);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Global mükerrer önizleme kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'status' => 'queued',
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::globalDeduplicatePreview error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Global önizleme kuyruğa alınamadı.'], 500);
        }
    }

    public function globalDeduplicateApply(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $this->ensureDataPoolJobTables();
            $this->assertNoConflictingJobs([], self::BALANCE_JOB_TYPES);
            $running = $this->getRunningGlobalDedupJob();
            if ($running !== null) {
                $payload = $this->formatDataPoolJobPayload($running);
                $payload['already_running'] = true;
                $payload['message'] = 'Global mükerrer işlemi zaten çalışıyor.';
                return $this->jsonResponse($response, $payload);
            }

            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $strategy = (string) ($data['strategy'] ?? 'keep_first');
            $mode = (string) ($data['mode'] ?? 'mark_duplicate');
            $priorityRaw = (string) ($data['priority_list_ids'] ?? '');
            $priorityListIds = array_values(array_filter(array_unique(array_map('intval', preg_split('/[,\s]+/', $priorityRaw) ?: [])), static fn (int $id): bool => $id > 0));

            if (!in_array($strategy, ['keep_first', 'keep_oldest', 'keep_newest', 'keep_priority'], true)) {
                throw new \RuntimeException('Geçersiz koruma stratejisi.');
            }
            if (!in_array($mode, ['mark_duplicate', 'delete'], true)) {
                throw new \RuntimeException('Geçersiz işlem modu.');
            }
            if ($strategy === 'keep_priority' && $priorityListIds === []) {
                throw new \RuntimeException('Öncelikli strateji için en az bir liste seçin.');
            }

            $total = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(SUM(total_count), 0) FROM email_data_pool_lists');
            $jobId = $this->createDataPoolJob(0, 'global_deduplicate_apply', [
                'strategy' => $strategy,
                'mode' => $mode,
                'priority_list_ids' => $priorityListIds,
                'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], $total);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Global mükerrer temizliği kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'status' => 'queued',
                    'strategy' => $strategy,
                    'mode' => $mode,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::globalDeduplicateApply error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Global temizleme kuyruğa alınamadı.'], 500);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRunningGlobalDedupJob(): ?array
    {
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative(
            "SELECT id FROM data_pool_jobs WHERE type IN ('global_deduplicate_preview', 'global_deduplicate_apply') AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1"
        );
        if (!$row) {
            return null;
        }

        return $this->getDataPoolJob((int) ($row['id'] ?? 0));
    }

    public function poolListCollection(Request $request, Response $response): Response
    {
        try {
            $query = $request->getQueryParams();
            $search = trim((string) ($query['search'] ?? ''));
            $page = max(1, (int) ($query['page'] ?? 1));
            $perPage = max(10, min(100, (int) ($query['per_page'] ?? 30)));
            $offset = ($page - 1) * $perPage;

            $conn = $this->em->getConnection();
            $whereSql = '';
            $params = [];
            if ($search !== '') {
                $whereSql = ' WHERE l.name LIKE ?';
                $params[] = '%' . $search . '%';
            }

            $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM email_data_pool_lists l{$whereSql}", $params);
            $rows = $conn->fetchAllAssociative(
                "SELECT l.id, l.name, l.sort_order, l.total_count, l.active_count, l.passive_count, l.updated_count_at,
                        s.gmail_count, s.non_gmail_count, s.invalid_gmail_count, s.duplicate_count, s.last_analyzed_at
                   FROM email_data_pool_lists l
              LEFT JOIN email_pool_stats s ON s.pool_id = l.id
                   {$whereSql}
               ORDER BY l.sort_order ASC, l.id ASC
                  LIMIT {$perPage} OFFSET {$offset}",
                $params
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => array_map(static function (array $row): array {
                    $active = (int) ($row['active_count'] ?? 0);
                    $gmail = (int) ($row['gmail_count'] ?? 0);
                    return [
                        'id' => (int) ($row['id'] ?? 0),
                        'name' => (string) ($row['name'] ?? ''),
                        'sort_order' => (int) ($row['sort_order'] ?? 0),
                        'entry_count' => (int) ($row['total_count'] ?? 0),
                        'active_count' => $active,
                        'passive_count' => (int) ($row['passive_count'] ?? 0),
                        'updated_count_at' => $row['updated_count_at'] ?? null,
                        'gmail_count' => $gmail,
                        'non_gmail_count' => (int) ($row['non_gmail_count'] ?? 0),
                        'invalid_gmail_count' => (int) ($row['invalid_gmail_count'] ?? 0),
                        'duplicate_count' => (int) ($row['duplicate_count'] ?? 0),
                        'gmail_ratio' => $active > 0 ? round(($gmail / $active) * 100, 2) : 0,
                        'last_analyzed_at' => $row['last_analyzed_at'] ?? null,
                    ];
                }, $rows),
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::poolListCollection error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Liste verisi alınamadı.'], 500);
        }
    }

    public function toolsFillToTarget(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $targetListId = (int) ($data['target_list_id'] ?? 0);
            $targetList = $this->resolveExistingPoolListById($targetListId);
            $sourceType = (string) ($data['source_type'] ?? 'list');
            $mode = (string) ($data['mode'] ?? 'copy');
            $targetCount = max(0, (int) ($data['target_count'] ?? 0));
            if ($targetCount < 1) {
                throw new \RuntimeException('Hedef adet zorunludur.');
            }
            $sourceList = null;
            if ($sourceType === 'list') {
                $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
                if ((int) $sourceList->getId() === (int) $targetList->getId()) {
                    throw new \RuntimeException('Kaynak ve hedef liste aynı olamaz.');
                }
            }
            $this->assertNoConflictingJobs(
                array_values(array_filter([(int) $targetList->getId(), $sourceList ? (int) $sourceList->getId() : 0])),
                ['complete_to_target', 'copy_to_target', 'move_overflow', 'split_pool', 'balance_pools', 'fill_new_pool']
            );
            $preview = $this->previewFillToTarget($data);
            $jobType = $mode === 'move' ? 'complete_to_target' : 'copy_to_target';
            $jobId = $this->createDataPoolJob(
                (int) $targetList->getId(),
                $jobType,
                [
                    'operation' => 'fill_to_target',
                    'target_list_id' => (int) $targetList->getId(),
                    'source_type' => $sourceType,
                    'source_list_id' => $sourceList ? (int) $sourceList->getId() : null,
                    'mode' => $mode,
                    'target_count' => $targetCount,
                    'only_gmail' => (int) filter_var((string) ($data['only_gmail'] ?? '0'), FILTER_VALIDATE_BOOLEAN),
                    'clean_gmail_typos' => (int) filter_var((string) ($data['clean_gmail_typos'] ?? '0'), FILTER_VALIDATE_BOOLEAN),
                    'remove_duplicates' => (int) filter_var((string) ($data['remove_duplicates'] ?? '1'), FILTER_VALIDATE_BOOLEAN),
                    'leftover_action' => (string) ($data['leftover_action'] ?? 'ignore'),
                    'new_list_name' => trim((string) ($data['new_list_name'] ?? '')),
                    'source_payload' => (string) ($data['source_payload'] ?? ''),
                    'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                (int) ($preview['will_process'] ?? 0)
            );
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Liste dengeleme işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'type' => $jobType,
                    'operation' => 'fill_to_target',
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsFillToTarget error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Tamamlama işlemi başarısız oldu.'], 500);
        }
    }

    public function toolsMoveOverflow(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
            $targetCount = max(0, (int) ($data['target_count'] ?? 0));
            if ($targetCount < 1) {
                throw new \RuntimeException('Hedef limit zorunludur.');
            }

            $targetType = (string) ($data['overflow_target_type'] ?? 'existing');
            $targetList = $targetType === 'new'
                ? $this->createPoolList(trim((string) ($data['new_list_name'] ?? 'Taşınan Fazla Kayıtlar')))
                : $this->resolveExistingPoolListById((int) ($data['overflow_target_list_id'] ?? 0));
            if ((int) $targetList->getId() === (int) $sourceList->getId()) {
                throw new \RuntimeException('Fazla aktarımda hedef liste kaynak ile aynı olamaz.');
            }
            $this->assertNoConflictingJobs([(int) $sourceList->getId(), (int) $targetList->getId()], self::BALANCE_JOB_TYPES);
            $preview = $this->previewMoveOverflow($data);
            $jobId = $this->createDataPoolJob(
                (int) $sourceList->getId(),
                'move_overflow',
                [
                    'operation' => 'move_overflow',
                    'source_list_id' => (int) $sourceList->getId(),
                    'target_list_id' => (int) $targetList->getId(),
                    'target_count' => $targetCount,
                    'remove_duplicates' => (int) filter_var((string) ($data['remove_duplicates'] ?? '1'), FILTER_VALIDATE_BOOLEAN),
                    'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                (int) ($preview['overflow'] ?? 0)
            );
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Liste dengeleme işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'type' => 'move_overflow',
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsMoveOverflow error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Fazla aktarım işlemi başarısız oldu.'], 500);
        }
    }

    public function toolsSplitList(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
            $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
            $chunkSize = max(1000, (int) ($data['chunk_size'] ?? 0));
            $prefix = trim((string) ($data['new_list_prefix'] ?? 'Parca'));
            $mode = (string) ($data['mode'] ?? 'copy');
            $onlyGmail = filter_var((string) ($data['only_gmail'] ?? '0'), FILTER_VALIDATE_BOOLEAN);
            $cleanTypos = filter_var((string) ($data['clean_gmail_typos'] ?? '0'), FILTER_VALIDATE_BOOLEAN);
            $removeDuplicates = filter_var((string) ($data['remove_duplicates'] ?? '1'), FILTER_VALIDATE_BOOLEAN);
            if ($prefix === '') {
                throw new \RuntimeException('Liste adı prefix zorunlu.');
            }
            $this->assertNoConflictingJobs([(int) $sourceList->getId()], self::BALANCE_JOB_TYPES);
            $preview = $this->previewSplitList($data);
            $jobId = $this->createDataPoolJob(
                (int) $sourceList->getId(),
                'split_pool',
                [
                    'operation' => 'split_pool',
                    'source_list_id' => (int) $sourceList->getId(),
                    'chunk_size' => $chunkSize,
                    'new_list_prefix' => $prefix,
                    'mode' => $mode,
                    'only_gmail' => $onlyGmail ? 1 : 0,
                    'clean_gmail_typos' => $cleanTypos ? 1 : 0,
                    'remove_duplicates' => $removeDuplicates ? 1 : 0,
                    'requested_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
                (int) ($preview['will_process'] ?? 0)
            );
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Liste dengeleme işlemi kuyruğa alındı.',
                'data' => [
                    'jobId' => $jobId,
                    'type' => 'split_pool',
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsSplitList error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Liste bölme işlemi başarısız oldu.'], 500);
        }
    }

    public function downloadLeftoverFile(Request $request, Response $response, array $args): Response
    {
        try {
            $token = trim((string) ($args['token'] ?? ''));
            if ($token === '') {
                throw new \RuntimeException('Dosya bulunamadı.');
            }
            $files = $_SESSION[self::LEFTOVER_FILES_SESSION_KEY] ?? [];
            $meta = is_array($files) ? ($files[$token] ?? null) : null;
            if (!is_array($meta)) {
                throw new \RuntimeException('Dosya bulunamadı.');
            }
            $path = (string) ($meta['path'] ?? '');
            $expiresAt = (int) ($meta['expires_at'] ?? 0);
            if ($path === '' || !is_file($path) || $expiresAt < time()) {
                @unlink($path);
                unset($_SESSION[self::LEFTOVER_FILES_SESSION_KEY][$token]);
                throw new \RuntimeException('Dosya süresi dolmuş.');
            }

            $resource = fopen($path, 'rb');
            if ($resource === false) {
                throw new \RuntimeException('Dosya okunamadı.');
            }
            register_shutdown_function(static function () use ($path, $token): void {
                @unlink($path);
                if (isset($_SESSION[self::LEFTOVER_FILES_SESSION_KEY][$token])) {
                    unset($_SESSION[self::LEFTOVER_FILES_SESSION_KEY][$token]);
                }
            });

            return $response
                ->withBody(new \Slim\Psr7\Stream($resource))
                ->withHeader('Content-Type', 'text/plain; charset=utf-8')
                ->withHeader('Content-Disposition', 'attachment; filename="kalan-veri-' . date('Y-m-d-His') . '.txt"');
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        }
    }

    private function previewFillToTarget(array $data): array
    {
        $targetList = $this->resolveExistingPoolListById((int) ($data['target_list_id'] ?? 0));
        $targetCount = max(0, (int) ($data['target_count'] ?? 0));
        $current = $this->getListTotalCountFast((int) $targetList->getId(), (int) $targetList->getTotalCount());
        $need = max(0, $targetCount - $current);
        $sourceType = (string) ($data['source_type'] ?? 'list');
        $available = 0;
        if ($sourceType === 'list') {
            $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
            $available = $this->getListTotalCountFast((int) $sourceList->getId(), (int) $sourceList->getTotalCount());
        } else {
            $raw = (string) ($data['source_payload'] ?? $data['emails'] ?? '');
            $parsed = $this->parseImportInput($raw, false);
            $available = count($parsed['records']);
        }

        return [
            'success' => true,
            'tool' => 'fill_to_target',
            'current_total' => $current,
            'target_total' => $targetCount,
            'required' => $need,
            'available' => $available,
            'will_process' => min($need, $available),
            'estimated_leftover' => max(0, $available - $need),
        ];
    }

    private function previewMoveOverflow(array $data): array
    {
        $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
        $targetCount = max(0, (int) ($data['target_count'] ?? 0));
        $current = $this->getListTotalCountFast((int) $sourceList->getId(), (int) $sourceList->getTotalCount());
        $overflow = max(0, $current - $targetCount);

        return [
            'success' => true,
            'tool' => 'move_overflow',
            'current_total' => $current,
            'target_total' => $targetCount,
            'overflow' => $overflow,
            'will_process' => $overflow,
        ];
    }

    private function previewSplitList(array $data): array
    {
        $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
        $chunkSize = max(1000, (int) ($data['chunk_size'] ?? 0));
        $total = $this->getListTotalCountFast((int) $sourceList->getId(), (int) $sourceList->getTotalCount());
        $parts = (int) ceil($total / $chunkSize);

        return [
            'success' => true,
            'tool' => 'split_list',
            'source_total' => $total,
            'chunk_size' => $chunkSize,
            'estimated_parts' => max(0, $parts),
            'will_process' => $total,
        ];
    }

    private function previewFillToTargetDetailed(array $data): array
    {
        $sourceType = (string) ($data['source_type'] ?? 'list');
        $targetList = $this->resolveExistingPoolListById((int) ($data['target_list_id'] ?? 0));
        $targetCurrent = $this->getListTotalCountFast((int) $targetList->getId(), (int) $targetList->getTotalCount());
        $targetLimit = max(1, (int) ($data['target_count'] ?? self::DEFAULT_TARGET_LIMIT));
        $needed = max(0, $targetLimit - $targetCurrent);
        $sourcePoolId = 0;
        $sourcePoolName = strtoupper($sourceType);
        $sourceCurrent = 0;
        if ($sourceType === 'list') {
            $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
            $sourcePoolId = (int) $sourceList->getId();
            $sourcePoolName = (string) $sourceList->getName();
            $sourceCurrent = $this->getListTotalCountFast((int) $sourceList->getId(), (int) $sourceList->getTotalCount());
        } else {
            $raw = (string) ($data['source_payload'] ?? $data['emails'] ?? '');
            $parsed = $this->parseImportInput($raw, false);
            $sourceCurrent = count((array) ($parsed['records'] ?? []));
        }

        return [
            'operation' => 'complete_to_target',
            'sourcePoolId' => $sourcePoolId,
            'sourcePoolName' => $sourcePoolName,
            'targetPoolId' => (int) $targetList->getId(),
            'targetPoolName' => (string) $targetList->getName(),
            'sourceCurrent' => $sourceCurrent,
            'targetCurrent' => $targetCurrent,
            'targetLimit' => $targetLimit,
            'missingCount' => $needed,
            'willMove' => min($needed, max(0, $sourceCurrent)),
            'mode' => (string) ($data['mode'] ?? 'copy'),
            'warnings' => [
                sprintf('Bu işlem hedef listeye en fazla %s kayıt aktaracaktır.', number_format(min($needed, max(0, $sourceCurrent)))),
            ],
        ];
    }

    private function previewMoveOverflowDetailed(array $data): array
    {
        $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
        $targetType = (string) ($data['overflow_target_type'] ?? 'existing');
        $targetList = $targetType === 'new'
            ? null
            : $this->resolveExistingPoolListById((int) ($data['overflow_target_list_id'] ?? 0));
        $targetLimit = max(1, (int) ($data['target_count'] ?? self::DEFAULT_TARGET_LIMIT));
        $sourceCurrent = $this->getListTotalCountFast((int) $sourceList->getId(), (int) $sourceList->getTotalCount());
        $targetCurrent = $targetList ? $this->getListTotalCountFast((int) $targetList->getId(), (int) $targetList->getTotalCount()) : 0;
        $overflowCount = max(0, $sourceCurrent - $targetLimit);
        $targetPoolName = $targetList ? (string) $targetList->getName() : trim((string) ($data['new_list_name'] ?? 'Yeni Liste'));

        return [
            'operation' => 'move_overflow',
            'sourcePoolId' => (int) $sourceList->getId(),
            'sourcePoolName' => (string) $sourceList->getName(),
            'targetPoolId' => $targetList ? (int) $targetList->getId() : 0,
            'targetPoolName' => $targetPoolName,
            'sourceCurrent' => $sourceCurrent,
            'targetCurrent' => $targetCurrent,
            'targetLimit' => $targetLimit,
            'overflowCount' => $overflowCount,
            'willMove' => $overflowCount,
            'mode' => 'move',
            'warnings' => [
                sprintf('Bu işlem kaynak listeden %s kaydı hedef listeye aktaracak.', number_format($overflowCount)),
            ],
        ];
    }

    /**
     * @param array<int, int> $poolIds
     * @param array<int, string> $requestedTypes
     */
    private function assertNoConflictingJobs(array $poolIds, array $requestedTypes): void
    {
        $this->ensureDataPoolJobTables();
        $conn = $this->em->getConnection();
        $allTypes = array_values(array_unique(array_merge($requestedTypes, self::GLOBAL_BLOCKING_JOB_TYPES)));
        $typePlaceholders = implode(',', array_fill(0, count($allTypes), '?'));
        $params = $allTypes;
        $sql = "SELECT COUNT(*) FROM data_pool_jobs WHERE status IN ('queued', 'running') AND type IN ($typePlaceholders)";
        if ($poolIds !== []) {
            $poolIds = array_values(array_filter(array_map('intval', $poolIds), static fn (int $id): bool => $id > 0));
            if ($poolIds !== []) {
                $poolPlaceholders = implode(',', array_fill(0, count($poolIds), '?'));
                $sql .= " AND (pool_id IN ($poolPlaceholders) OR pool_id IS NULL)";
                foreach ($poolIds as $pid) {
                    $params[] = $pid;
                }
            }
        }
        $count = (int) $conn->fetchOne($sql, $params);
        if ($count > 0) {
            throw new \RuntimeException('Bu liste için devam eden bir işlem var. Lütfen önce mevcut işlemin bitmesini bekleyin.');
        }
    }

    private function buildCachedListStats(int $poolListId, int $targetLimit): array
    {
        $conn = $this->em->getConnection();
        $target = $targetLimit > 0 ? $targetLimit : null;
        $listTotal = (int) $conn->fetchOne('SELECT total_count FROM email_data_pool_lists WHERE id = ?', [$poolListId]);
        $statsRow = [];
        try {
            $this->ensureDataPoolStatsTable();
            $statsRow = $conn->fetchAssociative('SELECT * FROM email_pool_stats WHERE pool_id = ?', [$poolListId]) ?: [];
        } catch (\Throwable) {
            $statsRow = [];
        }
        try {
            $this->ensureAnalysisTables();
            $cache = $conn->fetchAssociative('SELECT * FROM email_data_pool_analysis_cache WHERE list_id = ?', [$poolListId]) ?: [];
        } catch (\Throwable) {
            $cache = [];
        }
        $total = isset($cache['total_count'])
            ? (int) $cache['total_count']
            : (isset($statsRow['total_count']) ? (int) $statsRow['total_count'] : $listTotal);
        $missing = $target !== null ? max(0, $target - $total) : 0;
        $over = $target !== null ? max(0, $total - $target) : 0;

        return [
            'analysis_status' => (string) ($cache['status'] ?? 'idle'),
            'analysis_available' => !empty($cache) && in_array((string) ($cache['status'] ?? ''), ['completed', 'running'], true),
            'total' => $total,
            'gmail_count' => isset($cache['gmail_count']) ? (int) $cache['gmail_count'] : (isset($statsRow['gmail_count']) ? (int) $statsRow['gmail_count'] : null),
            'non_gmail_count' => isset($cache['non_gmail_count']) ? (int) $cache['non_gmail_count'] : (isset($statsRow['non_gmail_count']) ? (int) $statsRow['non_gmail_count'] : null),
            'duplicate_count' => isset($cache['duplicate_count']) ? (int) $cache['duplicate_count'] : (isset($statsRow['duplicate_count']) ? (int) $statsRow['duplicate_count'] : null),
            'typo_gmail_count' => isset($cache['invalid_gmail_count']) ? (int) $cache['invalid_gmail_count'] : (isset($statsRow['invalid_gmail_count']) ? (int) $statsRow['invalid_gmail_count'] : null),
            'deletable_count' => isset($cache['deletable_count']) ? (int) $cache['deletable_count'] : max(0, (int) ($statsRow['non_gmail_count'] ?? 0) + (int) ($statsRow['duplicate_count'] ?? 0)),
            'gmail_ratio' => isset($cache['gmail_ratio'])
                ? (float) $cache['gmail_ratio']
                : ($total > 0 ? round(((int) ($statsRow['gmail_count'] ?? 0) / $total) * 100, 2) : null),
            'target_limit' => $target,
            'missing_to_target' => $missing,
            'over_target' => $over,
            'last_analyzed_at' => isset($cache['last_analyzed_at']) ? (string) $cache['last_analyzed_at'] : (isset($statsRow['last_analyzed_at']) ? (string) $statsRow['last_analyzed_at'] : null),
            'normalized_preview' => $this->decodePreview((string) ($cache['normalized_preview'] ?? '[]')),
            'non_gmail_preview' => $this->decodePreview((string) ($cache['non_gmail_preview'] ?? '[]')),
            'error_message' => (string) ($cache['error_message'] ?? ''),
            'updated_at' => isset($cache['updated_at']) ? (string) $cache['updated_at'] : (isset($statsRow['updated_at']) ? (string) $statsRow['updated_at'] : null),
        ];
    }

    private function getRunningAnalysisJob(int $poolListId): ?array
    {
        $this->ensureAnalysisTables();
        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT id, status, total_count, processed_count, percent, message
               FROM email_data_pool_analysis_jobs
              WHERE list_id = ? AND status = 'running'
              ORDER BY id DESC LIMIT 1",
            [$poolListId]
        );
        if (!$row) {
            return null;
        }

        return [
            'job_id' => (int) ($row['id'] ?? 0),
            'status' => (string) ($row['status'] ?? 'idle'),
            'total' => (int) ($row['total_count'] ?? 0),
            'processed' => (int) ($row['processed_count'] ?? 0),
            'percent' => (int) ($row['percent'] ?? 0),
            'gmail_count' => (int) ($row['gmail_count'] ?? 0),
            'non_gmail_count' => (int) ($row['non_gmail_count'] ?? 0),
            'invalid_gmail_count' => (int) ($row['invalid_gmail_count'] ?? 0),
            'message' => (string) ($row['message'] ?? 'Liste analiz ediliyor...'),
        ];
    }

    private function analysisJobPayload(int $jobId): array
    {
        $this->ensureAnalysisTables();
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT * FROM email_data_pool_analysis_jobs WHERE id = ?',
            [$jobId]
        );
        if (!$row) {
            throw new \RuntimeException('Analiz işi bulunamadı.');
        }
        $payload = [
            'success' => true,
            'job_id' => (int) ($row['id'] ?? 0),
            'list_id' => (int) ($row['list_id'] ?? 0),
            'status' => (string) ($row['status'] ?? 'idle'),
            'total' => (int) ($row['total_count'] ?? 0),
            'processed' => (int) ($row['processed_count'] ?? 0),
            'percent' => (int) ($row['percent'] ?? 0),
            'gmail_count' => (int) ($row['gmail_count'] ?? 0),
            'non_gmail_count' => (int) ($row['non_gmail_count'] ?? 0),
            'invalid_gmail_count' => (int) ($row['invalid_gmail_count'] ?? 0),
            'duplicate_count' => (int) ($row['duplicate_count'] ?? 0),
            'deletable_count' => (int) ($row['deletable_count'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'error' => (string) ($row['error_message'] ?? ''),
        ];
        if (($payload['status'] ?? '') === 'completed') {
            $cache = $this->buildCachedListStats((int) $payload['list_id'], 0);
            $payload['result'] = [
                'total' => $cache['total'],
                'gmail' => (int) ($cache['gmail_count'] ?? 0),
                'non_gmail' => (int) ($cache['non_gmail_count'] ?? 0),
                'invalid_gmail' => (int) ($cache['typo_gmail_count'] ?? 0),
                'duplicate' => (int) ($cache['duplicate_count'] ?? 0),
                'deletable' => (int) ($cache['deletable_count'] ?? 0),
                'gmail_ratio' => (float) ($cache['gmail_ratio'] ?? 0),
                'last_analyzed_at' => $cache['last_analyzed_at'],
            ];
        }

        return $payload;
    }

    private function runtimeStatusCode(\RuntimeException $e): int
    {
        $message = $e->getMessage();
        if (str_contains($message, 'CSRF')) {
            return 419;
        }
        if (str_contains($message, 'Liste bulunamadı')) {
            return 404;
        }
        if (str_contains($message, 'Analiz tabloları bulunamadı')) {
            return 503;
        }

        return 400;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createDataPoolJob(int $poolId, string $type, array $payload = [], int $totalCount = 0): int
    {
        $this->ensureDataPoolJobTables();
        $conn = $this->em->getConnection();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $maxAttempts = max(1, (int) ($_ENV['DATA_POOL_JOB_MAX_ATTEMPTS'] ?? 3));
        $conn->executeStatement(
            'INSERT INTO data_pool_jobs
                (pool_id, type, status, payload, total_count, processed_count, success_count, failed_count, progress_percent, result, error_message, started_at, finished_at, created_at, updated_at, attempts, max_attempts, resumable, cancel_requested)
             VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, NULL, NULL, NULL, NULL, ?, ?, 0, ?, 1, 0)',
            [
                $poolId > 0 ? $poolId : null,
                $type,
                'queued',
                json_encode($payload, JSON_UNESCAPED_UNICODE),
                max(0, $totalCount),
                $now,
                $now,
                $maxAttempts,
            ]
        );

        return (int) $conn->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDataPoolJob(int $jobId): ?array
    {
        $this->ensureDataPoolJobTables();
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM data_pool_jobs WHERE id = ?', [$jobId]);
        if (!$row) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? ''), true);
        $result = json_decode((string) ($row['result'] ?? ''), true);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'pool_id' => isset($row['pool_id']) ? (int) $row['pool_id'] : null,
            'type' => (string) ($row['type'] ?? ''),
            'status' => (string) ($row['status'] ?? 'queued'),
            'payload' => is_array($payload) ? $payload : [],
            'result' => is_array($result) ? $result : [],
            'total_count' => (int) ($row['total_count'] ?? 0),
            'processed_count' => (int) ($row['processed_count'] ?? 0),
            'success_count' => (int) ($row['success_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'progress_percent' => (int) ($row['progress_percent'] ?? 0),
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
            'worker_id' => (string) ($row['worker_id'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<int, string>|null $types
     * @return array<string, mixed>|null
     */
    private function getRunningDataPoolJob(int $poolId, ?array $types = null): ?array
    {
        $this->ensureDataPoolJobTables();
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

        $row = $this->em->getConnection()->fetchAssociative($sql, $params);
        if (!$row) {
            return null;
        }

        return $this->getDataPoolJob((int) ($row['id'] ?? 0));
    }

    /**
     * @param array<int, string>|null $types
     * @return array<string, mixed>|null
     */
    private function getLatestDataPoolJob(int $poolId, ?array $types = null): ?array
    {
        $this->ensureDataPoolJobTables();
        $params = [$poolId];
        $sql = 'SELECT * FROM data_pool_jobs WHERE pool_id = ?';
        if (is_array($types) && $types !== []) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND type IN ($in)";
            foreach ($types as $type) {
                $params[] = (string) $type;
            }
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = $this->em->getConnection()->fetchAssociative($sql, $params);
        if (!$row) {
            return null;
        }

        return $this->getDataPoolJob((int) ($row['id'] ?? 0));
    }

    /**
     * @param array<int, string>|null $types
     * @return array<string, mixed>|null
     */
    private function getLatestPendingOrRunningDataPoolJob(int $poolId, ?array $types = null): ?array
    {
        $this->ensureDataPoolJobTables();
        $params = [$poolId];
        $sql = "SELECT * FROM data_pool_jobs WHERE pool_id = ? AND status IN ('queued', 'running')";
        if (is_array($types) && $types !== []) {
            $in = implode(',', array_fill(0, count($types), '?'));
            $sql .= " AND type IN ($in)";
            foreach ($types as $type) {
                $params[] = (string) $type;
            }
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        $row = $this->em->getConnection()->fetchAssociative($sql, $params);
        if (!$row) {
            return null;
        }

        return $this->getDataPoolJob((int) ($row['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function formatDataPoolJobPayload(array $job): array
    {
        $result = is_array($job['result'] ?? null) ? $job['result'] : [];
        $status = (string) ($job['status'] ?? 'queued');
        $poolId = (int) ($job['pool_id'] ?? 0);
        $heartbeatAt = (string) ($job['heartbeat_at'] ?? '');
        $staleThreshold = max(30, (int) ($_ENV['DATA_POOL_STALE_HEARTBEAT_SECONDS'] ?? 60));
        $isStale = false;
        if ($status === 'running' && $heartbeatAt !== '') {
            $heartbeatTs = strtotime($heartbeatAt) ?: 0;
            $isStale = $heartbeatTs > 0 && (time() - $heartbeatTs) > $staleThreshold;
        }
        $payload = [
            'success' => true,
            'job_id' => (int) ($job['id'] ?? 0),
            'list_id' => $poolId,
            'scope' => $poolId > 0 ? 'list' : 'global',
            'type' => (string) ($job['type'] ?? ''),
            'status' => $status,
            'total' => (int) ($job['total_count'] ?? 0),
            'processed' => (int) ($job['processed_count'] ?? 0),
            'success_count' => (int) ($job['success_count'] ?? 0),
            'failed_count' => (int) ($job['failed_count'] ?? 0),
            'percent' => (int) ($job['progress_percent'] ?? 0),
            'gmail_count' => (int) ($result['gmail_count'] ?? 0),
            'non_gmail_count' => (int) ($result['non_gmail_count'] ?? 0),
            'invalid_gmail_count' => (int) ($result['invalid_gmail_count'] ?? 0),
            'duplicate_count' => (int) ($result['duplicate_count'] ?? 0),
            'deletable_count' => (int) (($result['non_gmail_count'] ?? 0) + ($result['duplicate_count'] ?? 0)),
            'message' => (string) ($job['status_message'] ?? ($status === 'failed' ? 'İşlem başarısız oldu.' : ($status === 'completed' ? 'İşlem tamamlandı.' : 'İşlem sürüyor...'))),
            'error' => (string) ($job['error_message'] ?? ''),
            'error_code' => (string) ($job['error_code'] ?? ''),
            'exception_class' => (string) ($job['exception_class'] ?? ''),
            'failed_step' => (string) ($job['failed_step'] ?? ''),
            'last_sql_name' => (string) ($job['last_sql_name'] ?? ''),
            'result' => $result,
            'started_at' => $job['started_at'] ?? null,
            'finished_at' => $job['finished_at'] ?? null,
            'created_at' => $job['created_at'] ?? null,
            'updated_at' => $job['updated_at'] ?? null,
            'locked_by' => $job['locked_by'] ?? null,
            'locked_at' => $job['locked_at'] ?? null,
            'heartbeat_at' => $job['heartbeat_at'] ?? null,
            'worker_id' => (string) ($job['worker_id'] ?? ''),
            'attempts' => (int) ($job['attempts'] ?? 0),
            'max_attempts' => (int) ($job['max_attempts'] ?? 3),
            'resumable' => ((int) ($job['resumable'] ?? 1)) === 1,
            'cancel_requested' => ((int) ($job['cancel_requested'] ?? 0)) === 1,
            'last_processed_id' => isset($job['last_processed_id']) ? (int) $job['last_processed_id'] : null,
            'current_step' => (string) ($job['current_step'] ?? ''),
            'is_stale' => $isStale,
            'duplicate_groups' => (int) ($result['duplicate_groups'] ?? 0),
            'removed_count' => (int) ($result['removed_count'] ?? 0),
            'affected_rows' => (int) ($result['affected_rows'] ?? 0),
            'fetched_count' => (int) ($result['fetched_count'] ?? 0),
            'saved_count' => (int) ($result['saved_count'] ?? 0),
            'matched_count' => (int) ($result['matched_count'] ?? 0),
            'cleaned_count' => (int) ($result['cleaned_count'] ?? 0),
            'retry_count' => (int) ($result['retry_count'] ?? 0),
            'current_page' => (int) ($result['current_page'] ?? 0),
            'next_start' => (string) ($result['next_start'] ?? ''),
        ];
        $payload['data'] = [
            'jobId' => (int) ($payload['job_id'] ?? 0),
            'poolId' => $poolId,
            'status' => $status,
            'processed' => (int) ($payload['processed'] ?? 0),
            'total' => (int) ($payload['total'] ?? 0),
            'percent' => (int) ($payload['percent'] ?? 0),
            'gmailCount' => (int) ($payload['gmail_count'] ?? 0),
            'nonGmailCount' => (int) ($payload['non_gmail_count'] ?? 0),
            'invalidCount' => (int) ($payload['invalid_gmail_count'] ?? 0),
            'duplicateCount' => (int) ($payload['duplicate_count'] ?? 0),
            'downloadUrl' => (string) ($result['download_url'] ?? ''),
            'currentPoolId' => (int) ($result['currentPoolId'] ?? 0),
            'currentPoolName' => (string) ($result['currentPoolName'] ?? ''),
            'processedPools' => (int) ($result['processedPools'] ?? 0),
            'totalPools' => (int) ($result['totalPools'] ?? 0),
            'processedRecords' => (int) ($result['processedRecords'] ?? 0),
            'totalRecords' => (int) ($result['totalRecords'] ?? 0),
            'fetchedCount' => (int) ($result['fetched_count'] ?? 0),
            'savedCount' => (int) ($result['saved_count'] ?? 0),
            'matchedCount' => (int) ($result['matched_count'] ?? 0),
            'cleanedCount' => (int) ($result['cleaned_count'] ?? 0),
            'retryCount' => (int) ($result['retry_count'] ?? 0),
            'currentPage' => (int) ($result['current_page'] ?? 0),
            'nextStart' => (string) ($result['next_start'] ?? ''),
            'workerId' => (string) ($payload['worker_id'] ?? ''),
            'attempts' => (int) ($payload['attempts'] ?? 0),
            'maxAttempts' => (int) ($payload['max_attempts'] ?? 3),
            'heartbeatAt' => $payload['heartbeat_at'] ?? null,
            'isStale' => (bool) ($payload['is_stale'] ?? false),
            'currentStep' => (string) ($payload['current_step'] ?? ''),
        ];

        return $payload;
    }

    private function ensureDataPoolJobTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS data_pool_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                pool_id INT DEFAULT NULL,
                type VARCHAR(50) NOT NULL,
                status VARCHAR(30) NOT NULL DEFAULT 'queued',
                payload JSON DEFAULT NULL,
                total_count BIGINT NOT NULL DEFAULT 0,
                processed_count BIGINT NOT NULL DEFAULT 0,
                success_count BIGINT NOT NULL DEFAULT 0,
                failed_count BIGINT NOT NULL DEFAULT 0,
                progress_percent INT NOT NULL DEFAULT 0,
                result JSON DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                started_at DATETIME DEFAULT NULL,
                finished_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_data_pool_jobs_pool_status (pool_id, status),
                INDEX idx_data_pool_jobs_type_status (type, status),
                INDEX idx_data_pool_jobs_status_created (status, created_at),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $columnExists = static function (string $column) use ($conn): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['data_pool_jobs', $column]
            ) > 0;
        };
        $indexExists = static function (string $indexName) use ($conn): bool {
            return (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
                ['data_pool_jobs', $indexName]
            ) > 0;
        };

        $extraColumns = [
            'locked_by' => 'ALTER TABLE data_pool_jobs ADD COLUMN locked_by VARCHAR(100) DEFAULT NULL AFTER status',
            'locked_at' => 'ALTER TABLE data_pool_jobs ADD COLUMN locked_at DATETIME DEFAULT NULL AFTER locked_by',
            'heartbeat_at' => 'ALTER TABLE data_pool_jobs ADD COLUMN heartbeat_at DATETIME DEFAULT NULL AFTER locked_at',
            'attempts' => 'ALTER TABLE data_pool_jobs ADD COLUMN attempts INT NOT NULL DEFAULT 0 AFTER heartbeat_at',
            'max_attempts' => 'ALTER TABLE data_pool_jobs ADD COLUMN max_attempts INT NOT NULL DEFAULT 3 AFTER attempts',
            'resumable' => 'ALTER TABLE data_pool_jobs ADD COLUMN resumable TINYINT(1) NOT NULL DEFAULT 1 AFTER max_attempts',
            'cancel_requested' => 'ALTER TABLE data_pool_jobs ADD COLUMN cancel_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER resumable',
            'last_processed_id' => 'ALTER TABLE data_pool_jobs ADD COLUMN last_processed_id BIGINT DEFAULT NULL AFTER cancel_requested',
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
        foreach ($extraColumns as $column => $sql) {
            if (!$columnExists($column)) {
                $conn->executeStatement($sql);
            }
        }

        if (!$indexExists('idx_data_pool_jobs_status_next_run')) {
            $conn->executeStatement('CREATE INDEX idx_data_pool_jobs_status_next_run ON data_pool_jobs (status, next_run_at, id)');
        }
        if (!$indexExists('idx_data_pool_jobs_heartbeat')) {
            $conn->executeStatement('CREATE INDEX idx_data_pool_jobs_heartbeat ON data_pool_jobs (status, heartbeat_at)');
        }
        if (!$indexExists('idx_data_pool_jobs_locked_by')) {
            $conn->executeStatement('CREATE INDEX idx_data_pool_jobs_locked_by ON data_pool_jobs (locked_by, locked_at)');
        }
    }

    private function ensureDataPoolStatsTable(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "CREATE TABLE IF NOT EXISTS email_pool_stats (
                id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                pool_id INT NOT NULL,
                total_count BIGINT NOT NULL DEFAULT 0,
                active_count BIGINT NOT NULL DEFAULT 0,
                gmail_count BIGINT NOT NULL DEFAULT 0,
                non_gmail_count BIGINT NOT NULL DEFAULT 0,
                invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                duplicate_count BIGINT NOT NULL DEFAULT 0,
                target_limit BIGINT DEFAULT NULL,
                last_analyzed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE INDEX uq_email_pool_stats_pool_id (pool_id),
                INDEX idx_email_pool_stats_pool_id (pool_id),
                INDEX idx_email_pool_stats_last_analyzed_at (last_analyzed_at),
                PRIMARY KEY(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function ensureAlibabaInvalidTables(): void
    {
        $conn = $this->em->getConnection();
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

    private function processAnalysisJob(int $jobId): void
    {
        $this->ensureAnalysisTables();
        $lockName = 'email_pool_analysis_job_' . $jobId;
        $granted = (int) $this->em->getConnection()->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]);
        if ($granted !== 1) {
            return;
        }

        try {
            $conn = $this->em->getConnection();
            $job = $conn->fetchAssociative('SELECT * FROM email_data_pool_analysis_jobs WHERE id = ?', [$jobId]);
            if (!$job || (string) ($job['status'] ?? '') !== 'running') {
                return;
            }

            $listId = (int) ($job['list_id'] ?? 0);
            $chunkSize = max(2000, min(20000, (int) ($job['chunk_size'] ?? 12000)));
            $lastId = (int) ($job['last_id'] ?? 0);
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email, domain
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                  ORDER BY id ASC
                  LIMIT $chunkSize",
                [$listId, $lastId]
            );
            if ($rows === []) {
                $this->finalizeAnalysisJob($jobId, $job);
                return;
            }

            $processed = (int) ($job['processed_count'] ?? 0);
            $gmailCount = (int) ($job['gmail_count'] ?? 0);
            $nonGmailCount = (int) ($job['non_gmail_count'] ?? 0);
            $invalidCount = (int) ($job['invalid_gmail_count'] ?? 0);
            $normalizedPreview = $this->decodePreview((string) ($job['normalized_preview'] ?? '[]'));
            $nonGmailPreview = $this->decodePreview((string) ($job['non_gmail_preview'] ?? '[]'));

            foreach ($rows as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $domain = strtolower(trim((string) ($row['domain'] ?? $this->extractDomain($email))));
                if ($domain === 'gmail.com') {
                    $gmailCount++;
                } else {
                    $nonGmailCount++;
                    if (count($nonGmailPreview) < 10 && $email !== '') {
                        $nonGmailPreview[] = $email;
                    }
                }
                if (in_array($domain, self::GMAIL_TYPO_DOMAINS, true)) {
                    $invalidCount++;
                    if (count($normalizedPreview) < 10 && str_contains($email, '@')) {
                        [$localPart] = explode('@', $email, 2);
                        $normalizedPreview[] = ['from' => $email, 'to' => $localPart . '@gmail.com'];
                    }
                }
            }

            $processed += count($rows);
            $lastId = (int) ($rows[count($rows) - 1]['id'] ?? $lastId);
            $total = max(1, (int) ($job['total_count'] ?? 0));
            $percent = min(99, (int) floor(($processed / $total) * 100));
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

            $conn->executeStatement(
                'UPDATE email_data_pool_analysis_jobs
                    SET processed_count = ?, last_id = ?, percent = ?, gmail_count = ?, non_gmail_count = ?, invalid_gmail_count = ?, normalized_preview = ?, non_gmail_preview = ?, message = ?, updated_at = ?
                  WHERE id = ?',
                [$processed, $lastId, $percent, $gmailCount, $nonGmailCount, $invalidCount, json_encode($normalizedPreview, JSON_UNESCAPED_UNICODE), json_encode($nonGmailPreview, JSON_UNESCAPED_UNICODE), 'Liste analiz ediliyor...', $now, $jobId]
            );
            if ($processed >= (int) ($job['total_count'] ?? 0)) {
                $fresh = $conn->fetchAssociative('SELECT * FROM email_data_pool_analysis_jobs WHERE id = ?', [$jobId]) ?: $job;
                $this->finalizeAnalysisJob($jobId, $fresh);
            }
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::processAnalysisJob error: ' . $e->getMessage());
            $conn = $this->em->getConnection();
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $conn->executeStatement(
                "UPDATE email_data_pool_analysis_jobs
                    SET status = 'failed', error_message = ?, message = ?, updated_at = ?
                  WHERE id = ?",
                [$e->getMessage(), 'Analiz hata verdi', $now, $jobId]
            );
            $listId = (int) $conn->fetchOne('SELECT list_id FROM email_data_pool_analysis_jobs WHERE id = ?', [$jobId]);
            if ($listId > 0) {
                $this->upsertAnalysisCache([
                    'list_id' => $listId,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'last_analyzed_at' => $now,
                ]);
            }
        } finally {
            $this->em->getConnection()->executeQuery('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * @param array<string, mixed> $job
     */
    private function finalizeAnalysisJob(int $jobId, array $job): void
    {
        $conn = $this->em->getConnection();
        $listId = (int) ($job['list_id'] ?? 0);
        if ($listId < 1) {
            return;
        }
        $hasNorm = $this->hasNormalizationColumns();
        $duplicateSql = $hasNorm
            ? 'SELECT COALESCE(SUM(t.cnt - 1), 0) FROM (SELECT COUNT(*) AS cnt FROM email_data_pool WHERE pool_list_id = ? GROUP BY normalized_email HAVING COUNT(*) > 1) t'
            : 'SELECT COALESCE(SUM(t.cnt - 1), 0) FROM (SELECT COUNT(*) AS cnt FROM email_data_pool WHERE pool_list_id = ? GROUP BY LOWER(TRIM(email)) HAVING COUNT(*) > 1) t';
        $duplicateCount = (int) $conn->fetchOne($duplicateSql, [$listId]);
        $gmailCount = (int) ($job['gmail_count'] ?? 0);
        $nonGmailCount = (int) ($job['non_gmail_count'] ?? 0);
        $invalidCount = (int) ($job['invalid_gmail_count'] ?? 0);
        $totalCount = (int) ($job['total_count'] ?? 0);
        $deletable = max(0, $nonGmailCount + $duplicateCount);
        $gmailRatio = $totalCount > 0 ? round(($gmailCount / $totalCount) * 100, 2) : 0.0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $normalizedPreviewJson = (string) ($job['normalized_preview'] ?? '[]');
        $nonGmailPreviewJson = (string) ($job['non_gmail_preview'] ?? '[]');

        $conn->executeStatement(
            "UPDATE email_data_pool_analysis_jobs
                SET status = 'completed',
                    processed_count = total_count,
                    percent = 100,
                    duplicate_count = ?,
                    deletable_count = ?,
                    gmail_ratio = ?,
                    message = ?,
                    completed_at = ?,
                    updated_at = ?
              WHERE id = ?",
            [$duplicateCount, $deletable, $gmailRatio, 'Analiz tamamlandı', $now, $now, $jobId]
        );

        $this->upsertAnalysisCache([
            'list_id' => $listId,
            'total_count' => $totalCount,
            'gmail_count' => $gmailCount,
            'non_gmail_count' => $nonGmailCount,
            'invalid_gmail_count' => $invalidCount,
            'duplicate_count' => $duplicateCount,
            'deletable_count' => $deletable,
            'gmail_ratio' => $gmailRatio,
            'target_limit' => (int) ($_ENV['EMAIL_POOL_DEFAULT_TARGET_LIMIT'] ?? self::DEFAULT_TARGET_LIMIT),
            'over_limit_count' => 0,
            'missing_count' => 0,
            'normalized_preview' => $normalizedPreviewJson,
            'non_gmail_preview' => $nonGmailPreviewJson,
            'last_analyzed_at' => $now,
            'status' => 'completed',
            'error_message' => null,
        ]);
        $this->syncPoolStatsFromCache($listId);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function upsertAnalysisCache(array $data): void
    {
        $this->ensureAnalysisTables();
        $listId = (int) ($data['list_id'] ?? 0);
        if ($listId < 1) {
            return;
        }
        $conn = $this->em->getConnection();
        $exists = (int) $conn->fetchOne('SELECT COUNT(*) FROM email_data_pool_analysis_cache WHERE list_id = ?', [$listId]) > 0;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $defaults = [
            'total_count' => 0, 'gmail_count' => 0, 'non_gmail_count' => 0, 'invalid_gmail_count' => 0,
            'duplicate_count' => 0, 'deletable_count' => 0, 'gmail_ratio' => 0, 'target_limit' => null,
            'over_limit_count' => 0, 'missing_count' => 0, 'normalized_preview' => '[]', 'non_gmail_preview' => '[]',
            'last_analyzed_at' => null, 'status' => 'idle', 'error_message' => null,
        ];
        $payload = array_merge($defaults, $data);
        if (!$exists) {
            $conn->executeStatement(
                "INSERT INTO email_data_pool_analysis_cache
                    (list_id,total_count,gmail_count,non_gmail_count,invalid_gmail_count,duplicate_count,deletable_count,gmail_ratio,target_limit,over_limit_count,missing_count,normalized_preview,non_gmail_preview,last_analyzed_at,status,error_message,created_at,updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$listId, $payload['total_count'], $payload['gmail_count'], $payload['non_gmail_count'], $payload['invalid_gmail_count'], $payload['duplicate_count'], $payload['deletable_count'], $payload['gmail_ratio'], $payload['target_limit'], $payload['over_limit_count'], $payload['missing_count'], $payload['normalized_preview'], $payload['non_gmail_preview'], $payload['last_analyzed_at'], $payload['status'], $payload['error_message'], $now, $now]
            );
        } else {
            $conn->executeStatement(
                'UPDATE email_data_pool_analysis_cache
                    SET total_count = ?, gmail_count = ?, non_gmail_count = ?, invalid_gmail_count = ?, duplicate_count = ?, deletable_count = ?, gmail_ratio = ?, target_limit = ?, over_limit_count = ?, missing_count = ?, normalized_preview = ?, non_gmail_preview = ?, last_analyzed_at = ?, status = ?, error_message = ?, updated_at = ?
                  WHERE list_id = ?',
                [$payload['total_count'], $payload['gmail_count'], $payload['non_gmail_count'], $payload['invalid_gmail_count'], $payload['duplicate_count'], $payload['deletable_count'], $payload['gmail_ratio'], $payload['target_limit'], $payload['over_limit_count'], $payload['missing_count'], $payload['normalized_preview'], $payload['non_gmail_preview'], $payload['last_analyzed_at'], $payload['status'], $payload['error_message'], $now, $listId]
            );
        }
    }

    private function invalidateAnalysisCache(int $listId): void
    {
        if ($listId < 1) {
            return;
        }
        try {
            $this->ensureAnalysisTables();
            $this->em->getConnection()->executeStatement(
                "UPDATE email_data_pool_analysis_cache
                    SET status = 'idle',
                        error_message = NULL,
                        updated_at = ?
                  WHERE list_id = ?",
                [(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $listId]
            );
        } catch (\Throwable) {
        }
        $this->syncPoolStatsFromCache($listId);
    }

    private function syncPoolStatsFromCache(int $listId): void
    {
        if ($listId < 1) {
            return;
        }
        try {
            $this->ensureDataPoolStatsTable();
            $conn = $this->em->getConnection();
            $list = $conn->fetchAssociative('SELECT total_count, active_count FROM email_data_pool_lists WHERE id = ?', [$listId]) ?: [];
            $cache = [];
            try {
                $cache = $conn->fetchAssociative('SELECT gmail_count, non_gmail_count, invalid_gmail_count, duplicate_count, target_limit, last_analyzed_at FROM email_data_pool_analysis_cache WHERE list_id = ?', [$listId]) ?: [];
            } catch (\Throwable) {
                $cache = [];
            }
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
                    $listId,
                    (int) ($list['total_count'] ?? 0),
                    (int) ($list['active_count'] ?? 0),
                    (int) ($cache['gmail_count'] ?? 0),
                    (int) ($cache['non_gmail_count'] ?? 0),
                    (int) ($cache['invalid_gmail_count'] ?? 0),
                    (int) ($cache['duplicate_count'] ?? 0),
                    isset($cache['target_limit']) ? (int) $cache['target_limit'] : null,
                    isset($cache['last_analyzed_at']) ? (string) $cache['last_analyzed_at'] : null,
                    $now,
                    $now,
                ]
            );
        } catch (\Throwable) {
        }
    }

    private function ensureAnalysisTables(): void
    {
        if ($this->analysisTablesReady === true) {
            return;
        }

        $conn = $this->em->getConnection();
        $db = (string) $conn->getDatabase();

        try {
            $conn->executeStatement(
                "CREATE TABLE IF NOT EXISTS email_data_pool_analysis_cache (
                    list_id INT NOT NULL,
                    total_count BIGINT NOT NULL DEFAULT 0,
                    gmail_count BIGINT NOT NULL DEFAULT 0,
                    non_gmail_count BIGINT NOT NULL DEFAULT 0,
                    invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                    duplicate_count BIGINT NOT NULL DEFAULT 0,
                    deletable_count BIGINT NOT NULL DEFAULT 0,
                    gmail_ratio DECIMAL(6,2) NOT NULL DEFAULT 0,
                    target_limit BIGINT DEFAULT NULL,
                    over_limit_count BIGINT NOT NULL DEFAULT 0,
                    missing_count BIGINT NOT NULL DEFAULT 0,
                    normalized_preview JSON DEFAULT NULL,
                    non_gmail_preview JSON DEFAULT NULL,
                    last_analyzed_at DATETIME DEFAULT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'idle',
                    error_message TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (list_id),
                    CONSTRAINT fk_email_data_pool_analysis_cache_list FOREIGN KEY (list_id) REFERENCES email_data_pool_lists (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $conn->executeStatement(
                "CREATE TABLE IF NOT EXISTS email_data_pool_analysis_jobs (
                    id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
                    list_id INT NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'idle',
                    total_count BIGINT NOT NULL DEFAULT 0,
                    processed_count BIGINT NOT NULL DEFAULT 0,
                    percent INT NOT NULL DEFAULT 0,
                    chunk_size INT NOT NULL DEFAULT 25000,
                    last_id BIGINT NOT NULL DEFAULT 0,
                    gmail_count BIGINT NOT NULL DEFAULT 0,
                    non_gmail_count BIGINT NOT NULL DEFAULT 0,
                    invalid_gmail_count BIGINT NOT NULL DEFAULT 0,
                    duplicate_count BIGINT NOT NULL DEFAULT 0,
                    deletable_count BIGINT NOT NULL DEFAULT 0,
                    gmail_ratio DECIMAL(6,2) NOT NULL DEFAULT 0,
                    normalized_preview JSON DEFAULT NULL,
                    non_gmail_preview JSON DEFAULT NULL,
                    message VARCHAR(255) DEFAULT NULL,
                    error_message TEXT DEFAULT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    completed_at DATETIME DEFAULT NULL,
                    PRIMARY KEY(id),
                    INDEX idx_email_pool_analysis_jobs_list_status (list_id, status),
                    INDEX idx_email_pool_analysis_jobs_status (status),
                    CONSTRAINT fk_email_pool_analysis_jobs_list FOREIGN KEY (list_id) REFERENCES email_data_pool_lists (id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            $cacheExists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db, 'email_data_pool_analysis_cache']
            ) > 0;
            $jobsExists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$db, 'email_data_pool_analysis_jobs']
            ) > 0;
            if (!$cacheExists || !$jobsExists) {
                throw new \RuntimeException('Analiz tabloları bulunamadı.');
            }
            $this->analysisTablesReady = true;
        } catch (\Throwable $e) {
            $this->analysisTablesReady = false;
            throw new \RuntimeException('Analiz tabloları bulunamadı. Lütfen migration çalıştırın. Detay: ' . $e->getMessage());
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function decodePreview(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, array{id:int,email:string,name:string}>
     */
    private function readEmailsFromList(int $listId, int $limit, bool $onlyGmail): array
    {
        $limit = max(1, min(500000, $limit));
        $sql = "SELECT id, email, COALESCE(name, '') AS name FROM email_data_pool WHERE pool_list_id = ?";
        $params = [$listId];
        if ($onlyGmail) {
            $sql .= " AND LOWER(SUBSTRING_INDEX(email, '@', -1)) = 'gmail.com'";
        }
        $sql .= ' ORDER BY id ASC LIMIT ' . $limit;
        $rows = $this->em->getConnection()->fetchAllAssociative($sql, $params);

        return array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'name' => (string) ($row['name'] ?? ''),
        ], $rows);
    }

    /**
     * @return array<int, array{id:int,email:string,name:string}>
     */
    private function readOverflowRows(int $listId, int $overflow): array
    {
        $overflow = max(0, $overflow);
        if ($overflow === 0) {
            return [];
        }
        $sql = 'SELECT id, email, COALESCE(name, "") AS name
                  FROM email_data_pool
                 WHERE pool_list_id = ?
                 ORDER BY id DESC
                 LIMIT ' . $overflow;
        $rows = $this->em->getConnection()->fetchAllAssociative($sql, [$listId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'name' => (string) ($row['name'] ?? ''),
        ], $rows);
    }

    /**
     * @param array<int, string> $emails
     * @return array<string, bool>
     */
    private function fetchExistingEmailSet(int $listId, array $emails): array
    {
        $emails = array_values(array_unique(array_filter(array_map(static fn ($v): string => strtolower(trim((string) $v)), $emails))));
        if ($emails === []) {
            return [];
        }
        $set = [];
        foreach (array_chunk($emails, 2000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->em->getConnection()->fetchFirstColumn(
                "SELECT LOWER(email) FROM email_data_pool WHERE pool_list_id = ? AND LOWER(email) IN ($in)",
                array_merge([$listId], $chunk)
            );
            foreach ($rows as $mail) {
                $set[(string) $mail] = true;
            }
        }

        return $set;
    }

    /**
     * @param array<int, int> $ids
     */
    private function deleteRowsByIds(int $listId, array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }
        $total = 0;
        foreach (array_chunk($ids, 2000) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            $total += $this->em->getConnection()->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($in)",
                array_merge([$listId], $chunk)
            );
        }
        $this->em->clear();

        return $total;
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function normalizeMaybeTypoGmail(string $email, bool $cleanTypos): array
    {
        $email = strtolower(trim($email));
        if (!$cleanTypos || !str_contains($email, '@')) {
            return [$email, false];
        }
        [$local, $domain] = explode('@', $email, 2);
        if ($local === '' || $domain === '') {
            return [$email, false];
        }
        if (in_array($domain, self::GMAIL_TYPO_DOMAINS, true)) {
            return [$local . '@gmail.com', true];
        }

        return [$email, false];
    }

    private function isGmailEmail(string $email): bool
    {
        return str_ends_with(strtolower($email), '@gmail.com');
    }

    private function extractDomain(string $email): string
    {
        $email = strtolower(trim($email));
        if (!str_contains($email, '@')) {
            return '';
        }
        [, $domain] = explode('@', $email, 2);
        return strtolower(trim($domain));
    }

    private function hasNormalizationColumns(): bool
    {
        if ($this->hasNormalizationColumns !== null) {
            return $this->hasNormalizationColumns;
        }
        try {
            $conn = $this->em->getConnection();
            $db = (string) $conn->getDatabase();
            $normalizedExists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$db, 'email_data_pool', 'normalized_email']
            ) > 0;
            $domainExists = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$db, 'email_data_pool', 'domain']
            ) > 0;
            $this->hasNormalizationColumns = $normalizedExists && $domainExists;
        } catch (\Throwable) {
            $this->hasNormalizationColumns = false;
        }

        return $this->hasNormalizationColumns;
    }

    private function getListTotalCountFast(int $listId, ?int $fallbackCount = null): int
    {
        if ($fallbackCount !== null && $fallbackCount > 0) {
            return $fallbackCount;
        }

        $conn = $this->em->getConnection();
        $fromList = $conn->fetchOne(
            'SELECT total_count FROM email_data_pool_lists WHERE id = ?',
            [$listId]
        );
        if ($fromList !== false && $fromList !== null) {
            return max(0, (int) $fromList);
        }

        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
            [$listId]
        );
    }

    private function createPoolList(string $name): EmailDataPoolList
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Yeni liste adı boş olamaz.');
        }
        $list = new EmailDataPoolList();
        $list->setName($name);
        $list->setSortOrder(0);
        $list->setUpdatedCountAt(new \DateTimeImmutable());
        $this->em->persist($list);
        $this->em->flush();

        return $list;
    }

    private function acquireListLock(int $listId, int $timeoutSeconds = 0): string
    {
        $lockName = 'email_pool_list_' . $listId;
        $granted = (int) $this->em->getConnection()->fetchOne('SELECT GET_LOCK(?, ?)', [$lockName, $timeoutSeconds]);
        if ($granted !== 1) {
            throw new \RuntimeException('Liste üzerinde başka bir işlem çalışıyor. Lütfen tekrar deneyin.');
        }

        return $lockName;
    }

    private function releaseListLock(string $lockName): void
    {
        if ($lockName === '') {
            return;
        }
        try {
            $this->em->getConnection()->executeQuery('SELECT RELEASE_LOCK(?)', [$lockName]);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<int, string> $emails
     */
    private function storeLeftoverFile(array $emails, string $prefix): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'edp_leftover');
        if ($tmpPath === false) {
            throw new \RuntimeException('Kalan veriler için geçici dosya oluşturulamadı.');
        }
        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Kalan veri dosyası açılamadı.');
        }
        foreach ($emails as $email) {
            $email = trim((string) $email);
            if ($email !== '') {
                fwrite($fp, $email . PHP_EOL);
            }
        }
        fclose($fp);

        $token = bin2hex(random_bytes(16));
        $files = $_SESSION[self::LEFTOVER_FILES_SESSION_KEY] ?? [];
        if (!is_array($files)) {
            $files = [];
        }
        $files[$token] = [
            'path' => $tmpPath,
            'prefix' => $prefix,
            'expires_at' => time() + 1800,
        ];
        $_SESSION[self::LEFTOVER_FILES_SESSION_KEY] = $files;

        return $token;
    }

    /**
     * @return array{
     *   total: int,
     *   gmail_count: int,
     *   non_gmail_count: int,
     *   typo_gmail_count: int,
     *   duplicate_count: int,
     *   deletable_count: int,
     *   normalized_preview: array<int, array{from: string, to: string}>,
     *   non_gmail_preview: array<int, string>
     * }
     */
    private function buildCleanerStats(int $poolListId): array
    {
        $conn = $this->em->getConnection();
        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?', [$poolListId]);
        $gmailCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ? AND LOWER(SUBSTRING_INDEX(email, '@', -1)) = 'gmail.com'",
            [$poolListId]
        );
        $nonGmailCount = max(0, $total - $gmailCount);

        $typoParams = array_merge([$poolListId], self::GMAIL_TYPO_DOMAINS);
        $typoInPlaceholders = implode(',', array_fill(0, count(self::GMAIL_TYPO_DOMAINS), '?'));
        $typoGmailCount = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM email_data_pool
              WHERE pool_list_id = ?
                AND INSTR(email, '@') > 1
                AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoInPlaceholders)",
            $typoParams
        );

        $duplicateCount = (int) $conn->fetchOne(
            'SELECT COALESCE(SUM(t.cnt - 1), 0) FROM (
                SELECT COUNT(*) AS cnt
                FROM email_data_pool
                WHERE pool_list_id = ?
                GROUP BY LOWER(email)
                HAVING COUNT(*) > 1
            ) AS t',
            [$poolListId]
        );

        $nonGmailPreview = $conn->fetchFirstColumn(
            "SELECT email FROM email_data_pool
              WHERE pool_list_id = ?
                AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
              ORDER BY id ASC
              LIMIT 10",
            [$poolListId]
        );

        $typoRows = $conn->fetchAllAssociative(
            "SELECT email FROM email_data_pool
              WHERE pool_list_id = ?
                AND INSTR(email, '@') > 1
                AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoInPlaceholders)
              ORDER BY id ASC
              LIMIT 10",
            $typoParams
        );

        $normalizedPreview = [];
        foreach ($typoRows as $row) {
            $source = trim((string) ($row['email'] ?? ''));
            if ($source === '' || !str_contains($source, '@')) {
                continue;
            }
            [$localPart] = explode('@', $source, 2);
            $normalizedPreview[] = [
                'from' => $source,
                'to' => $localPart . '@gmail.com',
            ];
        }

        return [
            'total' => $total,
            'gmail_count' => $gmailCount,
            'non_gmail_count' => $nonGmailCount,
            'typo_gmail_count' => $typoGmailCount,
            'duplicate_count' => $duplicateCount,
            'deletable_count' => $nonGmailCount + $duplicateCount,
            'normalized_preview' => $normalizedPreview,
            'non_gmail_preview' => array_values(array_map('strval', $nonGmailPreview)),
        ];
    }

    private function writeNonGmailTxtToTempFile(int $poolListId): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'edpnogmail');
        if ($tmpPath === false) {
            throw new \RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Geçici dosya açılamadı.');
        }

        $conn = $this->em->getConnection();
        $batchSize = max(2000, min(20000, (int) ($_ENV['EMAIL_POOL_TXT_EXPORT_BATCH'] ?? 10000)));
        $lastId = 0;

        do {
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
                  ORDER BY id ASC
                  LIMIT $batchSize",
                [$poolListId, $lastId]
            );
            foreach ($rows as $row) {
                $lastId = (int) ($row['id'] ?? 0);
                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '') {
                    fwrite($fp, $email . PHP_EOL);
                }
            }
        } while (count($rows) === $batchSize);

        if (fclose($fp) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Dosya kapatılamadı.');
        }

        return $tmpPath;
    }

    private function writeTypoGmailTxtToTempFile(int $poolListId): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'edptypogmail');
        if ($tmpPath === false) {
            throw new \RuntimeException('Geçici dosya oluşturulamadı.');
        }

        $fp = fopen($tmpPath, 'wb');
        if ($fp === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Geçici dosya açılamadı.');
        }

        $conn = $this->em->getConnection();
        $batchSize = max(2000, min(20000, (int) ($_ENV['EMAIL_POOL_TXT_EXPORT_BATCH'] ?? 10000)));
        $lastId = 0;
        $typoInPlaceholders = implode(',', array_fill(0, count(self::GMAIL_TYPO_DOMAINS), '?'));
        $baseParams = array_merge([$poolListId], self::GMAIL_TYPO_DOMAINS);

        do {
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoInPlaceholders)
                    AND id > ?
                  ORDER BY id ASC
                  LIMIT $batchSize",
                array_merge($baseParams, [$lastId])
            );
            foreach ($rows as $row) {
                $lastId = (int) ($row['id'] ?? 0);
                $email = trim((string) ($row['email'] ?? ''));
                if ($email !== '') {
                    fwrite($fp, $email . PHP_EOL);
                }
            }
        } while (count($rows) === $batchSize);

        if (fclose($fp) === false) {
            @unlink($tmpPath);
            throw new \RuntimeException('Dosya kapatılamadı.');
        }

        return $tmpPath;
    }

    private function deleteNonGmailForList(int $poolListId): int
    {
        $conn = $this->em->getConnection();
        $batchSize = 5000;
        $deleted = 0;

        while (true) {
            $ids = $conn->fetchFirstColumn(
                "SELECT id
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) <> 'gmail.com'
                  ORDER BY id ASC
                  LIMIT $batchSize",
                [$poolListId]
            );
            if ($ids === []) {
                break;
            }

            $ids = array_values(array_map('intval', $ids));
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$poolListId], $ids);
            $deleted += $conn->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($placeholders)",
                $params
            );
        }

        $this->em->clear();

        return $deleted;
    }

    /**
     * @return array{fixed: int, duplicates_after_fix: int}
     */
    private function fixGmailTyposForList(int $poolListId): array
    {
        $conn = $this->em->getConnection();
        $batchSize = 2000;
        $fixed = 0;
        $duplicatesAfterFix = 0;
        $lastId = 0;
        $typoPlaceholders = implode(',', array_fill(0, count(self::GMAIL_TYPO_DOMAINS), '?'));

        while (true) {
            $params = array_merge([$poolListId, $lastId], self::GMAIL_TYPO_DOMAINS);
            $rows = $conn->fetchAllAssociative(
                "SELECT id, email
                   FROM email_data_pool
                  WHERE pool_list_id = ?
                    AND id > ?
                    AND INSTR(email, '@') > 1
                    AND LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ($typoPlaceholders)
                  ORDER BY id ASC
                  LIMIT $batchSize",
                $params
            );
            if ($rows === []) {
                break;
            }

            $targetNormEmails = [];
            foreach ($rows as $row) {
                $email = trim((string) ($row['email'] ?? ''));
                if ($email === '' || !str_contains($email, '@')) {
                    continue;
                }
                [$localPart] = explode('@', $email, 2);
                if ($localPart === '') {
                    continue;
                }
                $targetNormEmails[] = strtolower($localPart . '@gmail.com');
            }
            $targetNormEmails = array_values(array_unique($targetNormEmails));

            $existingKeepByNorm = [];
            if ($targetNormEmails !== []) {
                $in = implode(',', array_fill(0, count($targetNormEmails), '?'));
                $existsRows = $conn->fetchAllAssociative(
                    "SELECT LOWER(email) AS norm_email, MIN(id) AS keep_id
                       FROM email_data_pool
                      WHERE pool_list_id = ?
                        AND LOWER(email) IN ($in)
                      GROUP BY LOWER(email)",
                    array_merge([$poolListId], $targetNormEmails)
                );
                foreach ($existsRows as $existsRow) {
                    $normEmail = (string) ($existsRow['norm_email'] ?? '');
                    $keepId = (int) ($existsRow['keep_id'] ?? 0);
                    if ($normEmail !== '' && $keepId > 0) {
                        $existingKeepByNorm[$normEmail] = $keepId;
                    }
                }
            }

            $batchKeepByNorm = [];
            $updates = [];
            $deleteIds = [];

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $lastId = max($lastId, $id);
                $email = trim((string) ($row['email'] ?? ''));
                if ($id < 1 || $email === '' || !str_contains($email, '@')) {
                    continue;
                }

                [$localPart] = explode('@', $email, 2);
                if ($localPart === '') {
                    continue;
                }

                $targetEmail = $localPart . '@gmail.com';
                $targetNorm = strtolower($targetEmail);

                $keepId = $existingKeepByNorm[$targetNorm] ?? PHP_INT_MAX;
                if (isset($batchKeepByNorm[$targetNorm])) {
                    $keepId = min($keepId, $batchKeepByNorm[$targetNorm]);
                }

                if ($keepId !== PHP_INT_MAX && $keepId !== $id) {
                    $deleteIds[] = $id;
                    $duplicatesAfterFix++;
                    continue;
                }

                if (strcasecmp($email, $targetEmail) !== 0) {
                    $updates[$id] = $targetEmail;
                    $fixed++;
                }

                $batchKeepByNorm[$targetNorm] = min($batchKeepByNorm[$targetNorm] ?? $id, $id);
                $existingKeepByNorm[$targetNorm] = min($existingKeepByNorm[$targetNorm] ?? $id, $id);
            }

            if ($updates !== []) {
                foreach (array_chunk($updates, 500, true) as $chunk) {
                    $cases = [];
                    $ids = [];
                    $params = [];
                    foreach ($chunk as $id => $newEmail) {
                        $cases[] = 'WHEN ? THEN ?';
                        $params[] = (int) $id;
                        $params[] = (string) $newEmail;
                        $ids[] = (int) $id;
                    }

                    $idPlaceholders = implode(',', array_fill(0, count($ids), '?'));
                    $sql = 'UPDATE email_data_pool
                               SET email = CASE id ' . implode(' ', $cases) . " END,
                                   updated_at = NOW()";
                    if ($this->hasNormalizationColumns()) {
                        $sql .= ',
                                   normalized_email = LOWER(TRIM(email)),
                                   domain = SUBSTRING_INDEX(LOWER(TRIM(email)), \'@\', -1)';
                    }
                    $sql .= "
                             WHERE pool_list_id = ?
                               AND id IN ($idPlaceholders)";
                    $params[] = $poolListId;
                    foreach ($ids as $id) {
                        $params[] = $id;
                    }
                    $conn->executeStatement($sql, $params);
                }
            }

            if ($deleteIds !== []) {
                foreach (array_chunk($deleteIds, 2000) as $chunk) {
                    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                    $conn->executeStatement(
                        "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($placeholders)",
                        array_merge([$poolListId], $chunk)
                    );
                }
            }
        }

        $this->em->clear();

        return [
            'fixed' => $fixed,
            'duplicates_after_fix' => $duplicatesAfterFix,
        ];
    }

    private function removeDuplicatesForList(int $poolListId): int
    {
        $conn = $this->em->getConnection();
        $batchSize = 5000;
        $removed = 0;
        $lastId = 0;

        while (true) {
            $duplicateIds = $conn->fetchFirstColumn(
                "SELECT d1.id
                   FROM email_data_pool d1
                   JOIN email_data_pool d2
                     ON d1.pool_list_id = d2.pool_list_id
                    AND LOWER(d1.email) = LOWER(d2.email)
                    AND d1.id > d2.id
                  WHERE d1.pool_list_id = ?
                    AND d1.id > ?
                  ORDER BY d1.id ASC
                  LIMIT $batchSize",
                [$poolListId, $lastId]
            );
            if ($duplicateIds === []) {
                break;
            }

            $duplicateIds = array_values(array_map('intval', $duplicateIds));
            $lastId = max($duplicateIds);
            $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
            $removed += $conn->executeStatement(
                "DELETE FROM email_data_pool WHERE pool_list_id = ? AND id IN ($placeholders)",
                array_merge([$poolListId], $duplicateIds)
            );
        }

        $this->em->clear();

        return $removed;
    }

    private function getOrCreateCleanerCsrfToken(): string
    {
        $token = (string) ($_SESSION[self::CLEANER_CSRF_SESSION_KEY] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CLEANER_CSRF_SESSION_KEY] = $token;
        }

        return $token;
    }

    private function assertCleanerCsrf(Request $request): void
    {
        $body = $request->getParsedBody();
        $bodyToken = null;
        if (is_array($body)) {
            $bodyToken = $body['_csrf'] ?? null;
        }
        $candidate = trim((string) ($bodyToken ?? $request->getHeaderLine('X-CSRF-Token')));
        $expected = trim((string) ($_SESSION[self::CLEANER_CSRF_SESSION_KEY] ?? ''));
        if ($expected === '' || $candidate === '' || !hash_equals($expected, $candidate)) {
            throw new \RuntimeException('CSRF doğrulaması başarısız.');
        }
    }

    private function resolveExistingPoolListById(int $listId): EmailDataPoolList
    {
        if ($listId < 1) {
            throw new \RuntimeException('Liste bulunamadı.');
        }

        $list = $this->em->find(EmailDataPoolList::class, $listId);
        if (!$list) {
            throw new \RuntimeException('Liste bulunamadı.');
        }

        return $list;
    }

    private function jsonResponse(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }

    public function storeList(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody() ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $_SESSION['error'] = 'Liste adı gerekli';
            return $response->withHeader('Location', $this->poolListRedirect(null))->withStatus(302);
        }
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $list = new EmailDataPoolList();
        $list->setName($name);
        $list->setSortOrder($sortOrder);
        $list->setUpdatedCountAt(new \DateTimeImmutable());
        $this->em->persist($list);
        $this->em->flush();
        $_SESSION['success'] = 'Yeni liste oluşturuldu: ' . $name;

        return $response->withHeader('Location', $this->poolListRedirect($list->getId()))->withStatus(302);
    }

    public function updateList(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $list = $this->em->find(EmailDataPoolList::class, $id);
        if (!$list) {
            $_SESSION['error'] = 'Liste bulunamadı';
            return $response->withHeader('Location', $this->poolListRedirect(null))->withStatus(302);
        }
        $data = $request->getParsedBody() ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name !== '') {
            $list->setName($name);
        }
        if (isset($data['sort_order'])) {
            $list->setSortOrder((int) $data['sort_order']);
        }
        $this->em->flush();
        $_SESSION['success'] = 'Liste güncellendi';

        return $response->withHeader('Location', $this->poolListRedirect($list->getId()))->withStatus(302);
    }

    public function reorderLists(Request $request, Response $response): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $data = $request->getParsedBody();
            if (!is_array($data)) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçersiz istek verisi.'], 422);
            }

            $order = $data['order'] ?? [];
            if (is_string($order)) {
                $decoded = json_decode($order, true);
                $order = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($order) || $order === []) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Sıralama listesi boş.'], 422);
            }

            $ids = array_values(array_filter(array_map('intval', $order), static fn (int $id): bool => $id > 0));
            if ($ids === []) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Geçerli liste bulunamadı.'], 422);
            }

            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            try {
                $sortOrder = 10;
                foreach ($ids as $id) {
                    $conn->executeStatement(
                        'UPDATE email_data_pool_lists SET sort_order = ? WHERE id = ?',
                        [$sortOrder, $id]
                    );
                    $sortOrder += 10;
                }
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Liste sıralaması güncellendi.',
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 422;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::reorderLists error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Liste sıralaması güncellenemedi.'], 500);
        }
    }

    public function deleteList(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $list = $this->em->find(EmailDataPoolList::class, $id);
        if (!$list) {
            $_SESSION['error'] = 'Liste bulunamadı';
            return $response->withHeader('Location', $this->poolListRedirect(null))->withStatus(302);
        }
        $count = (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(EmailDataPool::class, 'd')
            ->where('d.poolList = :l')
            ->setParameter('l', $list)
            ->getQuery()
            ->getSingleScalarResult();
        if ($count > 0) {
            $_SESSION['error'] = 'Bu listede hâlâ kayıt var. Önce kayıtları silin veya başka listeye taşıyın.';

            return $response->withHeader('Location', $this->poolListRedirect($list->getId()))->withStatus(302);
        }
        $allCount = (int) $this->em->createQueryBuilder()
            ->select('COUNT(l.id)')
            ->from(EmailDataPoolList::class, 'l')
            ->getQuery()
            ->getSingleScalarResult();
        if ($allCount <= 1) {
            $_SESSION['error'] = 'Son kalan liste silinemez.';

            return $response->withHeader('Location', $this->poolListRedirect($list->getId()))->withStatus(302);
        }
        $redirectId = null;
        $others = $this->em->getRepository(EmailDataPoolList::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        foreach ($others as $o) {
            if ($o->getId() !== $list->getId()) {
                $redirectId = $o->getId();
                break;
            }
        }
        $this->em->remove($list);
        $this->em->flush();
        $_SESSION['success'] = 'Liste silindi';

        return $response->withHeader('Location', $this->poolListRedirect($redirectId))->withStatus(302);
    }

    private function resolvePoolListById(int $listId): EmailDataPoolList
    {
        if ($listId > 0) {
            $list = $this->em->find(EmailDataPoolList::class, $listId);
            if ($list) {
                return $list;
            }
        }
        $lists = $this->em->getRepository(EmailDataPoolList::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC'], 1);
        if ($lists === []) {
            throw new \RuntimeException('Tanımlı havuz listesi yok. Lütfen migration çalıştırın.');
        }

        return $lists[0];
    }

    private function poolListRedirect(?int $listId): string
    {
        $base = '/admin/email-data-pool';
        if ($listId !== null && $listId > 0) {
            return $base . '?list_id=' . $listId;
        }
        $lists = $this->em->getRepository(EmailDataPoolList::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC'], 1);
        $id = $lists[0]?->getId() ?? 0;

        return $id > 0 ? $base . '?list_id=' . $id : $base;
    }
}

