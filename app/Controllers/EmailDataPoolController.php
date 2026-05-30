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
        $listPage = max(1, (int) ($params['list_page'] ?? 1));
        $listPerPage = 50;

        $listQb = $this->em->createQueryBuilder()
            ->select('l')
            ->from(EmailDataPoolList::class, 'l');
        if ($listSearch !== '') {
            $listQb->where('l.name LIKE :listSearch')
                ->setParameter('listSearch', '%' . $listSearch . '%');
        }
        $totalLists = (int) (clone $listQb)->select('COUNT(l.id)')->getQuery()->getSingleScalarResult();
        $listOffset = ($listPage - 1) * $listPerPage;

        /** @var EmailDataPoolList[] $visibleLists */
        $visibleLists = $listQb
            ->orderBy('l.sortOrder', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->setFirstResult($listOffset)
            ->setMaxResults($listPerPage)
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
        $listSummaries = [];
        foreach ($visibleLists as $pl) {
            $lid = $pl->getId();
            $listSummaries[] = [
                'id' => $lid,
                'name' => $pl->getName(),
                'sort_order' => $pl->getSortOrder(),
                'entry_count' => $pl->getTotalCount(),
                'active_count' => $pl->getActiveCount(),
                'passive_count' => $pl->getPassiveCount(),
                'updated_count_at' => $pl->getUpdatedCountAt()?->format('Y-m-d H:i:s'),
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
            'list_page' => $listPage,
            'list_per_page' => $listPerPage,
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
            
            foreach ($batch as $item) {
                $values[] = "(?, ?, ?, ?, ?, ?)";
                $params[] = $item['email'];
                $params[] = $item['name'];
                $params[] = 1;
                $params[] = $now;
                $params[] = $now;
                $params[] = $poolListId;
                $types[] = \PDO::PARAM_STR;
                $types[] = $item['name'] ? \PDO::PARAM_STR : \PDO::PARAM_NULL;
                $types[] = \PDO::PARAM_INT;
                $types[] = \PDO::PARAM_STR;
                $types[] = \PDO::PARAM_STR;
                $types[] = \PDO::PARAM_INT;
            }
            
            $sql = "INSERT INTO email_data_pool (email, name, is_active, created_at, updated_at, pool_list_id) VALUES " . implode(", ", $values);
            
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
            @ini_set('memory_limit', '512M');
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
            @ini_set('memory_limit', '512M');
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
        $limit = max(1, min(20000, $limit));
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

        $batchSize = max(2000, min(20000, (int) ($_ENV['EMAIL_POOL_TXT_EXPORT_BATCH'] ?? 10000)));
        $lastId = 0;
        do {
            $batch = $this->fetchPoolExportBatch($poolListId, $search, $lastId, $batchSize);
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

    public function cleanerExportNonGmail(Request $request, Response $response, array $args): Response
    {
        @set_time_limit(0);
        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', '0');
            @ini_set('memory_limit', '512M');
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

    public function cleanerDeleteNonGmail(Request $request, Response $response, array $args): Response
    {
        try {
            $this->assertCleanerCsrf($request);
            $listId = (int) ($args['listId'] ?? 0);
            $poolList = $this->resolveExistingPoolListById($listId);
            $deleted = $this->deleteNonGmailForList((int) $poolList->getId());
            if ($deleted > 0) {
                $this->recalculateListCounts([(int) $poolList->getId()]);
            }
            $remainingTotal = (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
                [(int) $poolList->getId()]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'deleted' => $deleted,
                'remaining_total' => $remainingTotal,
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
            $result = $this->fixGmailTyposForList((int) $poolList->getId());
            if (($result['fixed'] ?? 0) > 0 || ($result['duplicates_after_fix'] ?? 0) > 0) {
                $this->recalculateListCounts([(int) $poolList->getId()]);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'fixed' => (int) ($result['fixed'] ?? 0),
                'duplicates_after_fix' => (int) ($result['duplicates_after_fix'] ?? 0),
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
            $removed = $this->removeDuplicatesForList((int) $poolList->getId());
            if ($removed > 0) {
                $this->recalculateListCounts([(int) $poolList->getId()]);
            }
            $remainingTotal = (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
                [(int) $poolList->getId()]
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'removed' => $removed,
                'remaining_total' => $remainingTotal,
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
            $stats = $this->buildListStats((int) $poolList->getId(), $targetLimit);

            return $this->jsonResponse($response, $stats);
        } catch (\RuntimeException $e) {
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], 404);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::stats error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'İstatistikler yüklenemedi.'], 500);
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

    public function toolsFillToTarget(Request $request, Response $response): Response
    {
        $data = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $locks = [];
        try {
            $this->assertCleanerCsrf($request);
            $targetListId = (int) ($data['target_list_id'] ?? 0);
            $targetList = $this->resolveExistingPoolListById($targetListId);
            $sourceType = (string) ($data['source_type'] ?? 'list');
            $mode = (string) ($data['mode'] ?? 'copy');
            $targetCount = max(0, (int) ($data['target_count'] ?? 0));
            if ($targetCount < 1) {
                throw new \RuntimeException('Hedef adet zorunludur.');
            }

            $locks[] = $this->acquireListLock((int) $targetList->getId());
            $sourceList = null;
            if ($sourceType === 'list') {
                $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
                if ((int) $sourceList->getId() === (int) $targetList->getId()) {
                    throw new \RuntimeException('Kaynak ve hedef liste aynı olamaz.');
                }
                $locks[] = $this->acquireListLock((int) $sourceList->getId());
            }

            $currentTotal = (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
                [(int) $targetList->getId()]
            );
            $missing = max(0, $targetCount - $currentTotal);
            if ($missing < 1) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'summary' => [
                        'operation_type' => 'fill_to_target',
                        'source_list' => $sourceList?->getName(),
                        'target_list' => $targetList->getName(),
                        'read_records' => 0,
                        'added_records' => 0,
                        'moved_records' => 0,
                        'deleted_records' => 0,
                        'duplicate_skipped' => 0,
                        'non_gmail_skipped' => 0,
                        'typo_fixed' => 0,
                        'leftover_records' => 0,
                        'download_url' => null,
                        'message' => 'Hedef liste zaten hedef dolulukta veya üzerinde.',
                    ],
                ]);
            }

            $onlyGmail = filter_var((string) ($data['only_gmail'] ?? '0'), FILTER_VALIDATE_BOOLEAN);
            $cleanTypos = filter_var((string) ($data['clean_gmail_typos'] ?? '0'), FILTER_VALIDATE_BOOLEAN);
            $removeDuplicates = filter_var((string) ($data['remove_duplicates'] ?? '1'), FILTER_VALIDATE_BOOLEAN);
            $leftoverAction = (string) ($data['leftover_action'] ?? 'ignore');
            $newListName = trim((string) ($data['new_list_name'] ?? ''));

            $sourceEmails = [];
            $sourceRowsForMove = [];
            if ($sourceType === 'list' && $sourceList) {
                $sourceEmails = $this->readEmailsFromList((int) $sourceList->getId(), max($missing * 2, $missing + 5000), $onlyGmail);
                if ($mode === 'move') {
                    $sourceRowsForMove = $sourceEmails;
                }
            } else {
                $raw = (string) ($data['source_payload'] ?? $data['emails'] ?? '');
                $parsed = $this->parseImportInput($raw, false);
                foreach ($parsed['records'] as $record) {
                    $sourceEmails[] = ['id' => 0, 'email' => (string) ($record['email'] ?? ''), 'name' => (string) ($record['name'] ?? '')];
                }
            }

            $readRecords = count($sourceEmails);
            $prepared = [];
            $typoFixed = 0;
            $nonGmailSkipped = 0;
            $seenSource = [];
            foreach ($sourceEmails as $row) {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    continue;
                }
                [$normalized, $fixed] = $this->normalizeMaybeTypoGmail($email, $cleanTypos);
                if ($fixed) {
                    $typoFixed++;
                }
                if ($onlyGmail && !$this->isGmailEmail($normalized)) {
                    $nonGmailSkipped++;
                    continue;
                }
                if ($removeDuplicates && isset($seenSource[$normalized])) {
                    continue;
                }
                $seenSource[$normalized] = true;
                $prepared[] = [
                    'source_id' => (int) ($row['id'] ?? 0),
                    'email' => $normalized,
                    'name' => (string) ($row['name'] ?? ''),
                ];
            }

            $availableEmails = array_column($prepared, 'email');
            $existingTargetSet = $this->fetchExistingEmailSet((int) $targetList->getId(), $availableEmails);
            $selected = [];
            $duplicateSkipped = 0;
            $selectedSourceIds = [];
            foreach ($prepared as $item) {
                if (isset($existingTargetSet[$item['email']])) {
                    $duplicateSkipped++;
                    continue;
                }
                $selected[] = ['email' => $item['email'], 'name' => $item['name'] !== '' ? $item['name'] : null];
                if ($item['source_id'] > 0) {
                    $selectedSourceIds[] = $item['source_id'];
                }
                if (count($selected) >= $missing) {
                    break;
                }
            }

            $added = $selected === [] ? 0 : $this->bulkInsertEmails($selected, (int) $targetList->getId());
            if ($added > 0) {
                $this->incrementListCounts((int) $targetList->getId(), $added, $added, 0);
            }

            $moved = 0;
            if ($mode === 'move' && $sourceType === 'list' && $selectedSourceIds !== [] && $sourceList) {
                $moved = $this->deleteRowsByIds((int) $sourceList->getId(), $selectedSourceIds);
                if ($moved > 0) {
                    $this->recalculateListCounts([(int) $sourceList->getId()]);
                }
            }

            $leftovers = array_slice($prepared, count($selected));
            $leftoverEmails = array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['email'] ?? ''), $leftovers)));
            $downloadUrl = null;
            if ($leftoverEmails !== []) {
                if ($leftoverAction === 'download') {
                    $token = $this->storeLeftoverFile($leftoverEmails, 'fill-to-target');
                    $downloadUrl = '/admin/email-data-pool/tools/leftovers/' . $token;
                } elseif ($leftoverAction === 'create_list' && $newListName !== '') {
                    $newList = $this->createPoolList($newListName);
                    $rows = array_map(static fn (string $mail): array => ['email' => $mail, 'name' => null], $leftoverEmails);
                    $inserted = $rows === [] ? 0 : $this->bulkInsertEmails($rows, (int) $newList->getId());
                    if ($inserted > 0) {
                        $this->incrementListCounts((int) $newList->getId(), $inserted, $inserted, 0);
                    }
                }
            }

            $this->recalculateListCounts([(int) $targetList->getId()]);

            return $this->jsonResponse($response, [
                'success' => true,
                'summary' => [
                    'operation_type' => 'fill_to_target',
                    'source_list' => $sourceList?->getName() ?? strtoupper($sourceType),
                    'target_list' => $targetList->getName(),
                    'read_records' => $readRecords,
                    'added_records' => $added,
                    'moved_records' => $moved,
                    'deleted_records' => $moved,
                    'duplicate_skipped' => $duplicateSkipped,
                    'non_gmail_skipped' => $nonGmailSkipped,
                    'typo_fixed' => $typoFixed,
                    'leftover_records' => count($leftoverEmails),
                    'download_url' => $downloadUrl,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsFillToTarget error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Tamamlama işlemi başarısız oldu.'], 500);
        } finally {
            foreach ($locks as $lockName) {
                $this->releaseListLock($lockName);
            }
        }
    }

    public function toolsMoveOverflow(Request $request, Response $response): Response
    {
        $locks = [];
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

            $locks[] = $this->acquireListLock((int) $sourceList->getId());
            $locks[] = $this->acquireListLock((int) $targetList->getId());

            $currentTotal = (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
                [(int) $sourceList->getId()]
            );
            $overflow = max(0, $currentTotal - $targetCount);
            if ($overflow < 1) {
                return $this->jsonResponse($response, [
                    'success' => true,
                    'summary' => [
                        'operation_type' => 'move_overflow',
                        'source_list' => $sourceList->getName(),
                        'target_list' => $targetList->getName(),
                        'read_records' => 0,
                        'added_records' => 0,
                        'moved_records' => 0,
                        'deleted_records' => 0,
                        'duplicate_skipped' => 0,
                        'non_gmail_skipped' => 0,
                        'typo_fixed' => 0,
                        'leftover_records' => 0,
                        'download_url' => null,
                        'message' => 'Kaynak listede taşınacak fazla kayıt yok.',
                    ],
                ]);
            }

            $removeDuplicates = filter_var((string) ($data['remove_duplicates'] ?? '1'), FILTER_VALIDATE_BOOLEAN);
            $remaining = $overflow;
            $lastId = PHP_INT_MAX;
            $batchSize = 5000;
            $readRecords = 0;
            $added = 0;
            $moved = 0;
            $duplicateSkipped = 0;
            while ($remaining > 0) {
                $limit = min($batchSize, $remaining);
                $rows = $this->em->getConnection()->fetchAllAssociative(
                    "SELECT id, email, COALESCE(name, '') AS name
                       FROM email_data_pool
                      WHERE pool_list_id = ?
                        AND id < ?
                      ORDER BY id DESC
                      LIMIT $limit",
                    [(int) $sourceList->getId(), $lastId]
                );
                if ($rows === []) {
                    break;
                }
                $readRecords += count($rows);
                $lastId = (int) ($rows[count($rows) - 1]['id'] ?? $lastId);
                $existingSet = $this->fetchExistingEmailSet((int) $targetList->getId(), array_column($rows, 'email'));
                $insertRows = [];
                $moveIds = [];
                foreach ($rows as $row) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '') {
                        continue;
                    }
                    if ($removeDuplicates && isset($existingSet[$email])) {
                        $duplicateSkipped++;
                        continue;
                    }
                    $insertRows[] = ['email' => $email, 'name' => (string) ($row['name'] ?? '')];
                    $moveIds[] = (int) ($row['id'] ?? 0);
                    $existingSet[$email] = true;
                }
                if ($insertRows !== []) {
                    $added += $this->bulkInsertEmails($insertRows, (int) $targetList->getId());
                }
                if ($moveIds !== []) {
                    $moved += $this->deleteRowsByIds((int) $sourceList->getId(), $moveIds);
                }
                $remaining -= count($rows);
            }

            if ($added > 0) {
                $this->incrementListCounts((int) $targetList->getId(), $added, $added, 0);
            }
            if ($moved > 0) {
                $this->recalculateListCounts([(int) $sourceList->getId()]);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'summary' => [
                    'operation_type' => 'move_overflow',
                    'source_list' => $sourceList->getName(),
                    'target_list' => $targetList->getName(),
                    'read_records' => $readRecords,
                    'added_records' => $added,
                    'moved_records' => $moved,
                    'deleted_records' => $moved,
                    'duplicate_skipped' => $duplicateSkipped,
                    'non_gmail_skipped' => 0,
                    'typo_fixed' => 0,
                    'leftover_records' => max(0, $overflow - $moved),
                    'download_url' => null,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsMoveOverflow error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Fazla aktarım işlemi başarısız oldu.'], 500);
        } finally {
            foreach ($locks as $lockName) {
                $this->releaseListLock($lockName);
            }
        }
    }

    public function toolsSplitList(Request $request, Response $response): Response
    {
        $locks = [];
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

            $locks[] = $this->acquireListLock((int) $sourceList->getId());
            $conn = $this->em->getConnection();
            $batchSize = 3000;
            $lastId = 0;
            $partNo = 0;
            $currentTargetList = null;
            $currentCount = 0;
            $totalRead = 0;
            $totalAdded = 0;
            $totalMoved = 0;
            $duplicateSkipped = 0;
            $nonGmailSkipped = 0;
            $typoFixed = 0;
            $sourceDeleteIds = [];
            $seenForCurrentList = [];
            $targetListNames = [];
            $pendingInsertRows = [];
            $pendingInsertListId = 0;

            $flushPendingInsert = function () use (&$pendingInsertRows, &$pendingInsertListId, &$totalAdded, &$currentCount): void {
                if ($pendingInsertRows === [] || $pendingInsertListId < 1) {
                    return;
                }
                $addedNow = $this->bulkInsertEmails($pendingInsertRows, $pendingInsertListId);
                if ($addedNow > 0) {
                    $totalAdded += $addedNow;
                    $currentCount += $addedNow;
                    $this->incrementListCounts($pendingInsertListId, $addedNow, $addedNow, 0);
                }
                $pendingInsertRows = [];
                $pendingInsertListId = 0;
            };

            while (true) {
                $rows = $conn->fetchAllAssociative(
                    "SELECT id, email, name
                       FROM email_data_pool
                      WHERE pool_list_id = ?
                        AND id > ?
                      ORDER BY id ASC
                      LIMIT $batchSize",
                    [(int) $sourceList->getId(), $lastId]
                );
                if ($rows === []) {
                    break;
                }

                $prepared = [];
                foreach ($rows as $row) {
                    $totalRead++;
                    $id = (int) ($row['id'] ?? 0);
                    $lastId = max($lastId, $id);
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '') {
                        continue;
                    }
                    [$email, $fixed] = $this->normalizeMaybeTypoGmail($email, $cleanTypos);
                    if ($fixed) {
                        $typoFixed++;
                    }
                    if ($onlyGmail && !$this->isGmailEmail($email)) {
                        $nonGmailSkipped++;
                        continue;
                    }
                    $prepared[] = ['id' => $id, 'email' => $email, 'name' => (string) ($row['name'] ?? '')];
                }

                foreach ($prepared as $row) {
                    if ($currentTargetList === null || $currentCount >= $chunkSize) {
                        $flushPendingInsert();
                        $partNo++;
                        $currentTargetList = $this->createPoolList($prefix . ' ' . $partNo);
                        $targetListNames[] = $currentTargetList->getName();
                        $currentCount = 0;
                        $seenForCurrentList = [];
                    }

                    if ($removeDuplicates && isset($seenForCurrentList[$row['email']])) {
                        $duplicateSkipped++;
                        continue;
                    }
                    $seenForCurrentList[$row['email']] = true;
                    $pendingInsertRows[] = ['email' => $row['email'], 'name' => $row['name'] !== '' ? $row['name'] : null];
                    $pendingInsertListId = (int) $currentTargetList->getId();
                    if (count($pendingInsertRows) >= 1000) {
                        $flushPendingInsert();
                    }
                    if ($mode === 'move') {
                        $sourceDeleteIds[] = $row['id'];
                        if (count($sourceDeleteIds) >= 2000) {
                            $totalMoved += $this->deleteRowsByIds((int) $sourceList->getId(), $sourceDeleteIds);
                            $sourceDeleteIds = [];
                        }
                    }
                }
            }

            $flushPendingInsert();

            if ($mode === 'move' && $sourceDeleteIds !== []) {
                $totalMoved += $this->deleteRowsByIds((int) $sourceList->getId(), $sourceDeleteIds);
            }
            if ($mode === 'move' && $totalMoved > 0) {
                $this->recalculateListCounts([(int) $sourceList->getId()]);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'summary' => [
                    'operation_type' => 'split_list',
                    'source_list' => $sourceList->getName(),
                    'target_list' => implode(', ', $targetListNames),
                    'read_records' => $totalRead,
                    'added_records' => $totalAdded,
                    'moved_records' => $totalMoved,
                    'deleted_records' => $totalMoved,
                    'duplicate_skipped' => $duplicateSkipped,
                    'non_gmail_skipped' => $nonGmailSkipped,
                    'typo_fixed' => $typoFixed,
                    'leftover_records' => 0,
                    'download_url' => null,
                ],
            ]);
        } catch (\RuntimeException $e) {
            $status = str_contains($e->getMessage(), 'CSRF') ? 419 : 400;
            return $this->jsonResponse($response, ['success' => false, 'message' => $e->getMessage()], $status);
        } catch (\Throwable $e) {
            error_log('EmailDataPoolController::toolsSplitList error: ' . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Liste bölme işlemi başarısız oldu.'], 500);
        } finally {
            foreach ($locks as $lockName) {
                $this->releaseListLock($lockName);
            }
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
        $current = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
            [(int) $targetList->getId()]
        );
        $need = max(0, $targetCount - $current);
        $sourceType = (string) ($data['source_type'] ?? 'list');
        $available = 0;
        if ($sourceType === 'list') {
            $sourceList = $this->resolveExistingPoolListById((int) ($data['source_list_id'] ?? 0));
            $available = (int) $this->em->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
                [(int) $sourceList->getId()]
            );
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
        $current = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
            [(int) $sourceList->getId()]
        );
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
        $total = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM email_data_pool WHERE pool_list_id = ?',
            [(int) $sourceList->getId()]
        );
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

    /**
     * @return array{
     *  total:int,gmail_count:int,non_gmail_count:int,duplicate_count:int,typo_gmail_count:int,
     *  target_limit:?int,missing_to_target:int,over_target:int,updated_at:?string,gmail_ratio:float
     * }
     */
    private function buildListStats(int $poolListId, int $targetLimit): array
    {
        $cleaner = $this->buildCleanerStats($poolListId);
        $total = (int) ($cleaner['total'] ?? 0);
        $gmailCount = (int) ($cleaner['gmail_count'] ?? 0);
        $missing = 0;
        $over = 0;
        $target = $targetLimit > 0 ? $targetLimit : null;
        if ($target !== null) {
            $missing = max(0, $target - $total);
            $over = max(0, $total - $target);
        }
        $updatedAt = $this->em->getConnection()->fetchOne(
            'SELECT updated_count_at FROM email_data_pool_lists WHERE id = ?',
            [$poolListId]
        );

        return [
            'total' => $total,
            'gmail_count' => $gmailCount,
            'non_gmail_count' => (int) ($cleaner['non_gmail_count'] ?? 0),
            'duplicate_count' => (int) ($cleaner['duplicate_count'] ?? 0),
            'typo_gmail_count' => (int) ($cleaner['typo_gmail_count'] ?? 0),
            'target_limit' => $target,
            'missing_to_target' => $missing,
            'over_target' => $over,
            'updated_at' => $updatedAt ? (string) $updatedAt : null,
            'gmail_ratio' => $total > 0 ? round(($gmailCount / $total) * 100, 2) : 0.0,
        ];
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
                                   updated_at = NOW()
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

