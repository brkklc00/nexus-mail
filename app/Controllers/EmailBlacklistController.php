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
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $this->em->find(User::class, $_SESSION['user']['id']);
        $params = $request->getQueryParams();
        
        // Pagination
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Total count
        $qbCount = $this->em->createQueryBuilder();
        $total = $qbCount->select('COUNT(b.id)')
            ->from(EmailBlacklist::class, 'b')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        // Get paginated results
        $qb = $this->em->createQueryBuilder();
        $blacklist = $qb->select('b')
            ->from(EmailBlacklist::class, 'b')
            ->where('b.user = :user')
            ->setParameter('user', $user)
            ->orderBy('b.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / $perPage);

        $html = $this->twig->render('email-blacklist/index.twig', [
            'blacklist' => $blacklist,
            'total' => $total,
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
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
            $reason = $data['reason'] ?? null;
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

