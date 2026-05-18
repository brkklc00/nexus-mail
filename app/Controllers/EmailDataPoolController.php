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

        /** @var EmailDataPoolList[] $allLists */
        $allLists = $this->em->getRepository(EmailDataPoolList::class)->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);
        $currentList = $listIdParam > 0 ? $this->em->find(EmailDataPoolList::class, $listIdParam) : null;
        if (!$currentList && $allLists !== []) {
            $currentList = $allLists[0];
        }

        $aggregates = $this->fetchPoolListEntryAggregates();
        $listSummaries = [];
        foreach ($allLists as $pl) {
            $lid = $pl->getId();
            $listSummaries[] = [
                'id' => $lid,
                'name' => $pl->getName(),
                'sort_order' => $pl->getSortOrder(),
                'entry_count' => $aggregates[$lid]['entry_count'] ?? 0,
                'active_count' => $aggregates[$lid]['active_count'] ?? 0,
            ];
        }

        $total = 0;
        $activeCount = 0;
        $listTotalAll = 0;
        $totalAllPoolsEntries = 0;
        foreach ($aggregates as $agg) {
            $totalAllPoolsEntries += (int) ($agg['entry_count'] ?? 0);
        }
        if ($currentList) {
            $lid = (int) $currentList->getId();
            $listTotalAll = (int) ($aggregates[$lid]['entry_count'] ?? 0);
            $total = $listTotalAll;
            $activeCount = (int) ($aggregates[$lid]['active_count'] ?? 0);
        }

        $html = $this->twig->render('admin/email-data-pool/index.twig', [
            'pool_lists' => $listSummaries,
            'current_list_id' => $currentList?->getId(),
            'current_list_name' => $currentList?->getName(),
            'total' => $total,
            'active_count' => $activeCount,
            'list_total_all' => $listTotalAll,
            'total_all_pools_entries' => $totalAllPoolsEntries,
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
                : 'Import sırasında bir hata oluştu.';
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
        $lines = preg_split('/\R/u', $rawInput) ?: [];
        $lines = array_values(array_filter(array_map(static fn ($l) => rtrim((string) $l), $lines), static fn ($l) => trim($l) !== ''));

        $stats = [
            'total_rows' => 0,
            'valid_email' => 0,
            'duplicate_skipped' => 0,
            'invalid_skipped' => 0,
            'status_skipped' => 0,
        ];
        if ($lines === []) {
            throw new \RuntimeException('Dosya boş. Lütfen import edilecek satırları kontrol edin.');
        }

        $delimiter = $this->detectDelimiter($lines);
        $rows = array_map(static fn ($line) => str_getcsv((string) $line, $delimiter), $lines);
        $rows = array_values(array_filter($rows, static fn ($row) => is_array($row) && count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) > 0));
        if ($rows === []) {
            throw new \RuntimeException('Dosya boş. Lütfen import edilecek satırları kontrol edin.');
        }

        $headerMap = $this->detectHeaderMap($rows[0]);
        $hasHeader = $headerMap['has_header'];
        $emailIdx = $headerMap['email'];
        $nameIdx = $headerMap['name'];
        $statusIdx = $headerMap['status'];

        if ($hasHeader && $requireEmailColumnWhenHeader && $emailIdx === null) {
            throw new \RuntimeException('Mail kolonu bulunamadı. Lütfen Mail Adresi, email veya mail başlıklı kolon kullanın.');
        }

        $startRow = $hasHeader ? 1 : 0;
        $parsed = [];
        $seen = [];

        for ($i = $startRow; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (!is_array($row)) {
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
        $emailAliases = ['mailadresi', 'email', 'e-mail', 'mail', 'eposta', 'e-posta', 'recipient', 'address'];
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

            // Bulk delete ile sil (RAW SQL — listId null ise tüm pool listeleri)
            $removed = $this->bulkDeleteEmails($emailAddresses, $listIdForDelete);

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

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
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
            
            $deleted = $conn->executeStatement('DELETE FROM email_data_pool WHERE id = ?', [$id]);
            
            if ($deleted > 0) {
                $_SESSION['success'] = 'Mail havuzdan çıkarıldı';
            } else {
                $_SESSION['error'] = 'Mail bulunamadı';
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
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
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = "{$deleted} mail {$elapsed}s içinde havuzdan silindi";

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
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
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = sprintf('"%s" listesindeki %d kayıt %ss içinde silindi', $poolList->getName(), (int) $deleted, $elapsed);

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
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
            
            // Raw SQL bulk update
            $sql = "UPDATE email_data_pool SET is_active = 1, updated_at = NOW() WHERE id IN ($placeholders) AND pool_list_id = ?";
            $params = array_merge($ids, [$poolList->getId()]);
            $updated = $conn->executeStatement($sql, $params);
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = "{$updated} mail {$elapsed}s içinde aktif yapıldı";

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
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
            
            // Raw SQL bulk update
            $sql = "UPDATE email_data_pool SET is_active = 0, updated_at = NOW() WHERE id IN ($placeholders) AND pool_list_id = ?";
            $params = array_merge($ids, [$poolList->getId()]);
            $updated = $conn->executeStatement($sql, $params);
            
            $elapsed = round(microtime(true) - $startTime, 2);
            $_SESSION['success'] = "{$updated} mail {$elapsed}s içinde pasif yapıldı";

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
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

    /**
     * @return array<int, array{entry_count: int, active_count: int}>
     */
    private function fetchPoolListEntryAggregates(): array
    {
        $sql = 'SELECT pool_list_id,
                       COUNT(*) AS entry_count,
                       SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count
                FROM email_data_pool
                GROUP BY pool_list_id';

        $rows = $this->em->getConnection()->fetchAllAssociative($sql);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['pool_list_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $out[$id] = [
                'entry_count' => (int) ($row['entry_count'] ?? 0),
                'active_count' => (int) ($row['active_count'] ?? 0),
            ];
        }

        return $out;
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

