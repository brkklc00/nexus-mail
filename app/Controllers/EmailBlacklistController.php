<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\EmailBlacklist;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailBlacklistController
{
    private const ALLOWED_PER_PAGE = [20, 50, 100];
    private const ALLOWED_REASON_TYPES = ['bounce', 'invalid_recipient', 'spam', 'complaint', 'manual', 'smtp_error', 'other'];
    private const ALLOWED_DATE_RANGES = ['', 'today', 'last_7_days', 'last_30_days', 'this_month'];
    private const ALLOWED_SORTS = ['newest', 'oldest', 'email_az', 'email_za'];

    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $params = $request->getQueryParams();

        $q = trim((string) ($params['q'] ?? ''));
        $reasonType = trim((string) ($params['reason_type'] ?? ''));
        if (!in_array($reasonType, self::ALLOWED_REASON_TYPES, true)) {
            $reasonType = '';
        }
        $dateRange = trim((string) ($params['date_range'] ?? ''));
        if (!in_array($dateRange, self::ALLOWED_DATE_RANGES, true)) {
            $dateRange = '';
        }
        $sort = trim((string) ($params['sort'] ?? 'newest'));
        if (!in_array($sort, self::ALLOWED_SORTS, true)) {
            $sort = 'newest';
        }
        $perPage = (int) ($params['per_page'] ?? 20);
        if (!in_array($perPage, self::ALLOWED_PER_PAGE, true)) {
            $perPage = 20;
        }
        $page = max(1, (int) ($params['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $qbCount = $this->em->createQueryBuilder();
        $qbCount->select('COUNT(b.id)')
            ->from(EmailBlacklist::class, 'b')
            ->where('b.user = :user')
            ->setParameter('user', $user);
        $this->applyListFilters($qbCount, $q, $reasonType, $dateRange);
        $total = (int) $qbCount->getQuery()->getSingleScalarResult();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $qb = $this->em->createQueryBuilder();
        $qb->select('b')
            ->from(EmailBlacklist::class, 'b')
            ->where('b.user = :user')
            ->setParameter('user', $user);
        $this->applyListFilters($qb, $q, $reasonType, $dateRange);
        $this->applySort($qb, $sort);
        $blacklist = $qb
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $blacklistItems = array_map(function (EmailBlacklist $item): array {
            $reason = $item->getReason();
            $type = $this->detectReasonType($reason);
            return [
                'id' => $item->getId(),
                'email' => $item->getEmail(),
                'reason' => $reason,
                'reason_type' => $type,
                'reason_type_label' => $this->reasonTypeLabel($type),
                'reason_summary' => $this->reasonSummary($reason),
                'reason_hint' => $this->reasonHint($type),
                'created_at' => $item->getCreatedAt(),
            ];
        }, $blacklist);

        $stats = $this->buildStats((int) $user->getId());

        $html = $this->twig->render('email-blacklist/index.twig', [
            'blacklist' => $blacklist,
            'blacklistItems' => $blacklistItems,
            'total' => $total,
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'q' => $q,
            'selected_reason_type' => $reasonType,
            'selected_date_range' => $dateRange,
            'selected_sort' => $sort,
            'stats' => $stats,
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null
        ]);
        
        // Session mesajlarını temizle
        unset($_SESSION['success'], $_SESSION['error']);
        
        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response): Response
    {
        // ULTRA PERFORMANCE - Milyonları saniyeler içinde
        ini_set('memory_limit', '1G');
        ini_set('max_execution_time', '600');
        set_time_limit(600);
        
        $data = $request->getParsedBody();
        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        try {
            $emailsText = $data['emails'] ?? $data['single_email'] ?? '';
            
            if (empty($emailsText)) {
                $_SESSION['error'] = 'Mail adresi girilmedi';
                return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
            }
            
            // HYPER FAST parsing - Regex ile hızlı validation
            $emails = preg_split('/[\r\n]+/', $emailsText, -1, PREG_SPLIT_NO_EMPTY);
            $validEmails = [];
            
            // Basit ama hızlı email regex
            $emailPattern = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
            
            foreach ($emails as $email) {
                $email = strtolower(trim($email));
                if ($email && preg_match($emailPattern, $email)) {
                    $validEmails[] = $email;
                }
            }
            
            // Unique emails (çok hızlı - array flip trick)
            $validEmails = array_keys(array_flip($validEmails));
            
            if (empty($validEmails)) {
                $_SESSION['error'] = 'Geçerli mail adresi bulunamadı';
                return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
            }
            
            $conn = $this->em->getConnection();
            $userId = $user->getId();
            $reason = $this->buildStoreReason($data);
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            
            // HYPER SPEED: INSERT IGNORE + Transaction
            // MySQL UNIQUE constraint duplicate'ları otomatik halleder - 100x hızlı!
            $conn->beginTransaction();
            
            $totalInserted = 0;
            $batchSize = 5000; // Büyük batch'ler
            
            try {
                foreach (array_chunk($validEmails, $batchSize) as $emailBatch) {
                    $values = [];
                    $params = [];
                    
                    foreach ($emailBatch as $email) {
                        $values[] = '(?, ?, ?, ?)';
                        $params[] = $userId;
                        $params[] = $email;
                        $params[] = $reason;
                        $params[] = $now;
                    }
                    
                    // INSERT IGNORE - Duplicate skip, çok hızlı!
                    $sql = "INSERT IGNORE INTO email_blacklist (user_id, email, reason, created_at) VALUES " 
                         . implode(', ', $values);
                    
                    $affectedRows = $conn->executeStatement($sql, $params);
                    $totalInserted += $affectedRows;
                }
                
                $conn->commit();
                
            } catch (\Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            
            $added = $totalInserted;
            $skipped = count($validEmails) - $added;

            if ($added > 0 && $skipped > 0) {
                $_SESSION['success'] = "{$added} mail adresi eklendi, {$skipped} zaten kayıtlıydı";
            } elseif ($added > 0) {
                $_SESSION['success'] = "{$added} mail adresi karalisteye eklendi";
            } else {
                $_SESSION['error'] = 'Tüm mail adresleri zaten kayıtlı veya geçersiz';
            }

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
    }

    private function applyListFilters(\Doctrine\ORM\QueryBuilder $qb, string $q, string $reasonType, string $dateRange): void
    {
        if ($q !== '') {
            $qNormalized = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
            $qb->andWhere('(LOWER(b.email) LIKE :q OR LOWER(COALESCE(b.reason, \'\')) LIKE :q)')
                ->setParameter('q', '%' . $qNormalized . '%');
        }

        if ($reasonType !== '') {
            $patterns = $this->reasonTypePatterns($reasonType);
            if ($reasonType === 'other') {
                $allPatternExpr = [];
                foreach ($this->allReasonPatterns() as $idx => $pattern) {
                    $param = 'reasonPatternNot' . $idx;
                    $allPatternExpr[] = "LOWER(COALESCE(b.reason, '')) NOT LIKE :$param";
                    $qb->setParameter($param, '%' . $pattern . '%');
                }
                $qb->andWhere(implode(' AND ', $allPatternExpr));
            } else {
                $expr = [];
                foreach ($patterns as $idx => $pattern) {
                    $param = 'reasonPattern' . $idx;
                    $expr[] = "LOWER(COALESCE(b.reason, '')) LIKE :$param";
                    $qb->setParameter($param, '%' . $pattern . '%');
                }
                if ($expr !== []) {
                    $qb->andWhere('(' . implode(' OR ', $expr) . ')');
                }
            }
        }

        $startAt = $this->dateRangeStart($dateRange);
        if ($startAt !== null) {
            $qb->andWhere('b.createdAt >= :startAt')->setParameter('startAt', $startAt);
        }
    }

    private function applySort(\Doctrine\ORM\QueryBuilder $qb, string $sort): void
    {
        if ($sort === 'oldest') {
            $qb->orderBy('b.createdAt', 'ASC');
            return;
        }
        if ($sort === 'email_az') {
            $qb->orderBy('b.email', 'ASC');
            return;
        }
        if ($sort === 'email_za') {
            $qb->orderBy('b.email', 'DESC');
            return;
        }
        $qb->orderBy('b.createdAt', 'DESC');
    }

    private function buildStats(int $userId): array
    {
        $conn = $this->em->getConnection();
        $todayStart = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $rows = $conn->fetchAllAssociative(
            'SELECT reason FROM email_blacklist WHERE user_id = ?',
            [$userId]
        );
        $invalidRecipient = 0;
        $bounceSpam = 0;
        foreach ($rows as $row) {
            $type = $this->detectReasonType((string) ($row['reason'] ?? ''));
            if ($type === 'invalid_recipient') {
                $invalidRecipient++;
            }
            if ($type === 'bounce' || $type === 'spam') {
                $bounceSpam++;
            }
        }
        $todayTotal = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM email_blacklist WHERE user_id = ? AND created_at >= ?',
            [$userId, $todayStart]
        );

        return [
            'today_total' => $todayTotal,
            'invalid_recipient_total' => $invalidRecipient,
            'bounce_spam_total' => $bounceSpam,
        ];
    }

    private function dateRangeStart(string $dateRange): ?\DateTimeImmutable
    {
        if ($dateRange === 'today') {
            return new \DateTimeImmutable('today');
        }
        if ($dateRange === 'last_7_days') {
            return new \DateTimeImmutable('-7 days');
        }
        if ($dateRange === 'last_30_days') {
            return new \DateTimeImmutable('-30 days');
        }
        if ($dateRange === 'this_month') {
            return new \DateTimeImmutable('first day of this month 00:00:00');
        }
        return null;
    }

    private function normalizeText(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function detectReasonType(?string $reason): string
    {
        $text = $this->normalizeText(trim((string) $reason));
        if ($text === '') {
            return 'other';
        }
        foreach (self::ALLOWED_REASON_TYPES as $type) {
            if ($type === 'other') {
                continue;
            }
            foreach ($this->reasonTypePatterns($type) as $pattern) {
                if (str_contains($text, $pattern)) {
                    return $type;
                }
            }
        }
        return 'other';
    }

    private function reasonTypePatterns(string $type): array
    {
        return match ($type) {
            'invalid_recipient' => ['559', 'invalid rcptto', 'invalid recipient', 'invaddr', 'rcptto'],
            'bounce' => ['bounce', 'bounced', 'mailbox unavailable', 'user unknown'],
            'spam' => ['spam', 'antispam', 'blocked', 'block list'],
            'complaint' => ['complaint', 'abuse'],
            'manual' => ['manual', 'admin'],
            'smtp_error' => ['smtp', 'connection', 'timeout', 'server error'],
            default => [],
        };
    }

    private function allReasonPatterns(): array
    {
        $patterns = [];
        foreach (self::ALLOWED_REASON_TYPES as $type) {
            if ($type === 'other') {
                continue;
            }
            $patterns = array_merge($patterns, $this->reasonTypePatterns($type));
        }
        return array_values(array_unique($patterns));
    }

    private function reasonTypeLabel(string $type): string
    {
        return match ($type) {
            'invalid_recipient' => 'Invalid recipient',
            'bounce' => 'Bounce',
            'spam' => 'Spam / Block',
            'complaint' => 'Complaint',
            'manual' => 'Manual',
            'smtp_error' => 'SMTP error',
            default => 'Other',
        };
    }

    private function reasonHint(string $type): string
    {
        return match ($type) {
            'invalid_recipient' => 'Alıcı adresi geçersiz veya kabul edilmedi',
            'bounce' => 'Adres teslim edilemedi, alıcıya ulaşılamadı',
            'spam' => 'İleti spam/engelleme politikalarına takıldı',
            'complaint' => 'Alıcı şikayet/abuse bildirimi oluşturdu',
            'manual' => 'Kayıt panel üzerinden manuel eklendi',
            'smtp_error' => 'SMTP bağlantı veya sunucu kaynaklı hata',
            default => 'Sebep otomatik sınıflandırılamadı',
        };
    }

    private function reasonSummary(?string $reason): string
    {
        $text = trim((string) $reason);
        if ($text === '') {
            return 'Sebep belirtilmedi';
        }
        $line = preg_split('/\R+/', $text, 2)[0] ?? $text;
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($line, 'UTF-8') > 68) {
                return mb_substr($line, 0, 68, 'UTF-8') . '...';
            }
            return $line;
        }
        if (strlen($line) > 68) {
            return substr($line, 0, 68) . '...';
        }
        return $line;
    }

    private function buildStoreReason(array $data): ?string
    {
        $custom = trim((string) ($data['reason_custom'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $legacy = trim((string) ($data['reason'] ?? ''));
        if ($legacy !== '') {
            return $legacy;
        }
        $type = trim((string) ($data['reason_type'] ?? 'manual'));
        if (!in_array($type, self::ALLOWED_REASON_TYPES, true)) {
            $type = 'manual';
        }
        return $this->reasonTypeLabel($type);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $blacklist = $this->em->find(EmailBlacklist::class, (int) $args['id']);

        if ($blacklist && $blacklist->getUser()->getId() === $user->getId()) {
            $this->em->remove($blacklist);
            $this->em->flush();
            $_SESSION['success'] = 'Kara listeden çıkarıldı';
        }

        return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
    }

    public function bulkDelete(Request $request, Response $response): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $data = $request->getParsedBody();

        try {
            $ids = $data['ids'] ?? [];
            
            if (empty($ids) || !is_array($ids)) {
                $_SESSION['error'] = 'Geçersiz seçim';
                return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
            }

            // HYPER SPEED DELETE - Transaction + Chunk
            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            
            $deleted = 0;
            $chunkSize = 5000; // Büyük chunk'lar
            
            try {
                foreach (array_chunk($ids, $chunkSize) as $idChunk) {
                    $placeholders = implode(',', array_fill(0, count($idChunk), '?'));
                    $params = array_merge([$user->getId()], array_map('intval', $idChunk));
                    
                    $deleted += $conn->executeStatement(
                        "DELETE FROM email_blacklist WHERE user_id = ? AND id IN ($placeholders)",
                        $params
                    );
                }
                
                $conn->commit();
            } catch (\Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            $_SESSION['success'] = "{$deleted} mail adresi kara listeden çıkarıldı";

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
    }

    public function deleteAll(Request $request, Response $response): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);

        try {
            // HYPER SPEED - Tek query, transaction ile
            $conn = $this->em->getConnection();
            $conn->beginTransaction();
            
            try {
                $deleted = $conn->executeStatement(
                    'DELETE FROM email_blacklist WHERE user_id = ?',
                    [$user->getId()]
                );
                $conn->commit();
            } catch (\Exception $e) {
                $conn->rollBack();
                throw $e;
            }

            $_SESSION['success'] = "Tüm kara liste temizlendi. {$deleted} mail adresi çıkarıldı.";

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/email-blacklist')->withStatus(302);
    }
    
    /**
     * API: TÜM email karalistesini döner (Worker için - Sistem geneli)
     */
    public function apiGetBlacklist(Request $request, Response $response): Response
    {
        // API Token kontrolü
        $headers = $request->getHeaders();
        $apiToken = $headers['X-Api-Token'][0] ?? $request->getQueryParams()['api_token'] ?? null;
        
        $validToken = $_ENV['API_TOKEN']; // Düzeltildi
        
        if ($apiToken !== $validToken) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Unauthorized - Invalid API token'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }
        
        $conn = $this->em->getConnection();
        
        // TÜM kullanıcıların email karalistesini al (sistem geneli blacklist)
        $emails = $conn->fetchFirstColumn('SELECT DISTINCT email FROM email_blacklist');
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'blacklist' => $emails,
            'count' => count($emails)
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
    }
}

