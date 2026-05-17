<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Services\TransactionalEmailService;
use App\Domain\Entities\TransactionalEmail;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class TransactionalEmailController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig,
        private TransactionalEmailService $emailService
    ) {
    }

    /**
     * Ana sayfa - Liste + Gönderim Formu (Email OTP benzeri)
     */
    public function index(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        if (!$user) {
            $response->getBody()->write('User bulunamadı');
            return $response->withStatus(403);
        }
        
        $params = $request->getQueryParams();
        
        // Pagination
        $page = isset($params['page']) ? (int) $params['page'] : 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Filters
        $status = $params['status'] ?? 'all';
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        // Stats
        $stats = $this->emailService->getStats($user, $dateFrom, $dateTo);

        // Build query
        $qb = $this->em->createQueryBuilder();
        $qb->select('t')
            ->from(TransactionalEmail::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user);

        // Apply filters
        if ($status !== 'all') {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }

        if ($dateFrom) {
            $qb->andWhere('t.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }

        if ($dateTo) {
            $qb->andWhere('t.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        // Total count
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        // Get paginated results
        $history = $qb->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / $perPage);

        $html = $this->twig->render('transactional-email/index.twig', [
            'history' => $history,
            'stats' => $stats,
            'total' => $total,
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'user' => $user,
            'filters' => [
                'status' => $status,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ],
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null,
            'flash_icon' => $_SESSION['flash_icon'] ?? null
        ]);
        
        // Flash messages temizle
        unset($_SESSION['success'], $_SESSION['error'], $_SESSION['flash_icon']);
        
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Web'den email gönder
     */
    public function send(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        if (!$user) {
            $_SESSION['error'] = 'User bulunamadı';
            $_SESSION['flash_icon'] = 'alert-circle';
            return $response->withHeader('Location', '/transactional-email')->withStatus(302);
        }

        $data = $request->getParsedBody();
        
        $result = $this->emailService->sendTransactionalEmail(
            $user,
            $data['to_email'] ?? '',
            $data['subject'] ?? '',
            $data['body'] ?? '',
            $data['to_name'] ?? null,
            $data['from_email'] ?? null,
            $data['from_name'] ?? null
        );

        if ($result['success']) {
            $_SESSION['success'] = $result['message'];
            $_SESSION['flash_icon'] = 'check-circle';
        } else {
            $_SESSION['error'] = $result['message'];
            $_SESSION['flash_icon'] = 'x-circle';
        }

        return $response->withHeader('Location', '/transactional-email')->withStatus(302);
    }

    /**
     * API: Email gönder
     */
    public function apiSend(Request $request, Response $response): Response
    {
        // API Key ile user bul
        $apiKey = $request->getHeaderLine('X-API-Key') 
               ?: $request->getHeaderLine('X-Api-Token') 
               ?: $request->getHeaderLine('X-Api-Key');
        
        if (!$apiKey) {
            // Body'den de kontrol et
            $contentType = $request->getHeaderLine('Content-Type');
            if (strpos($contentType, 'application/json') !== false) {
                $rawBody = (string) $request->getBody();
                $bodyData = json_decode($rawBody, true) ?? [];
                $apiKey = $bodyData['api_key'] ?? null;
            }
        }
        
        if (!$apiKey) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'API Key gerekli. Header: X-API-Key veya body: api_key'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        
        $user = $this->em->getRepository(User::class)->findOneBy(['apiKey' => $apiKey]);
        
        if (!$user) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Geçersiz API Key'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        // JSON body'yi parse et
        $contentType = $request->getHeaderLine('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = (string) $request->getBody();
            $data = json_decode($rawBody, true) ?? [];
        } else {
            $data = $request->getParsedBody() ?? [];
        }

        // Validation
        if (empty($data['to_email']) || empty($data['subject']) || empty($data['body'])) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'to_email, subject ve body alanları zorunludur',
                'required_fields' => ['to_email', 'subject', 'body'],
                'optional_fields' => ['to_name', 'from_email', 'from_name']
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $result = $this->emailService->sendTransactionalEmail(
            $user,
            $data['to_email'],
            $data['subject'],
            $data['body'],
            $data['to_name'] ?? null,
            $data['from_email'] ?? null,
            $data['from_name'] ?? null
        );

        $statusCode = $result['success'] ? 200 : 400;
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
    }

    /**
     * API: Email geçmişi
     */
    public function apiHistory(Request $request, Response $response): Response
    {
        // API Key ile user bul
        $apiKey = $request->getHeaderLine('X-API-Key') 
               ?: $request->getHeaderLine('X-Api-Token') 
               ?: $request->getHeaderLine('X-Api-Key')
               ?: ($request->getQueryParams()['api_key'] ?? null);
        
        if (!$apiKey) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'API Key gerekli'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        
        $user = $this->em->getRepository(User::class)->findOneBy(['apiKey' => $apiKey]);
        
        if (!$user) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Geçersiz API Key'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }
        
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $limit = min((int) ($params['limit'] ?? 20), 100);
        $status = $params['status'] ?? 'all';
        $offset = ($page - 1) * $limit;
        
        // Build query
        $qb = $this->em->createQueryBuilder();
        $qb->select('t')
            ->from(TransactionalEmail::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user);
        
        if ($status !== 'all') {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $status);
        }
        
        // Total count
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();
        
        // Get results
        $history = $qb->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        
        // Format data
        $historyData = array_map(function($t) {
            return [
                'id' => $t->getId(),
                'to_email' => $t->getToEmail(),
                'to_name' => $t->getToName(),
                'subject' => $t->getSubject(),
                'status' => $t->getStatus(),
                'message_id' => $t->getMessageId(),
                'error' => $t->getError(),
                'created_at' => $t->getCreatedAt()->format('Y-m-d H:i:s'),
                'sent_at' => $t->getSentAt() ? $t->getSentAt()->format('Y-m-d H:i:s') : null
            ];
        }, $history);
        
        $result = [
            'success' => true,
            'data' => $historyData,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => (int) ceil($total / $limit)
            ]
        ];
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Email detayı göster (Modal için)
     */
    public function details(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        if (!$user) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'User bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }
        
        $email = $this->em->find(TransactionalEmail::class, (int) $args['id']);
        
        if (!$email || $email->getUser()->getId() !== $user->getId()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Email bulunamadı'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        
        $data = [
            'success' => true,
            'email' => [
                'id' => $email->getId(),
                'to_email' => $email->getToEmail(),
                'to_name' => $email->getToName(),
                'subject' => $email->getSubject(),
                'body' => $email->getBody(),
                'from_email' => $email->getFromEmail(),
                'from_name' => $email->getFromName(),
                'status' => $email->getStatus(),
                'status_label' => $email->getStatusLabel(),
                'message_id' => $email->getMessageId(),
                'error' => $email->getError(),
                'smtp_account' => $email->getSmtpAccount() ? $email->getSmtpAccount()->getName() : null,
                'created_at' => $email->getCreatedAt()->format('d.m.Y H:i:s'),
                'sent_at' => $email->getSentAt() ? $email->getSentAt()->format('d.m.Y H:i:s') : null
            ]
        ];
        
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

