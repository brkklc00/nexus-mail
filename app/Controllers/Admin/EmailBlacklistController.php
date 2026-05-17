<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

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

    /**
     * Admin: Müşteri bazlı kara liste listesi (her müşteri bir satır, tıklanınca modal)
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = trim($params['search'] ?? '');

        // Müşteri bazlı grupla: user_id, user_name, user_email, blacklist_count
        $conn = $this->em->getConnection();
        $sql = "
            SELECT u.id as user_id, u.name as user_name, u.email as user_email,
                   COUNT(b.id) as blacklist_count
            FROM users u
            INNER JOIN email_blacklist b ON b.user_id = u.id
        ";
        $params_sql = [];

        if ($search) {
            $sql .= " WHERE (u.name LIKE ? OR u.email LIKE ?)";
            $params_sql[] = '%' . $search . '%';
            $params_sql[] = '%' . $search . '%';
        }

        $sql .= " GROUP BY u.id, u.name, u.email ORDER BY blacklist_count DESC";
        $userList = $conn->fetchAllAssociative($sql, $params_sql);

        // İstatistikler
        $stats = [
            'total_blacklist' => (int) $conn->fetchOne('SELECT COUNT(*) FROM email_blacklist'),
            'total_users' => count($userList),
        ];

        $html = $this->twig->render('admin/email-blacklist/index.twig', [
            'userList' => $userList,
            'stats' => $stats,
            'search' => $search,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * API: Müşterinin kara liste emaillerini getir (modal için, sayfalı)
     */
    public function getEmails(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['userId'];
        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(10, (int) ($params['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $qbCount = $this->em->createQueryBuilder();
        $total = (int) $qbCount->select('COUNT(b.id)')
            ->from(EmailBlacklist::class, 'b')
            ->where('b.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        $records = $this->em->createQueryBuilder()
            ->select('b')
            ->from(EmailBlacklist::class, 'b')
            ->where('b.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('b.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $emails = array_map(function (EmailBlacklist $b) {
            return [
                'id' => $b->getId(),
                'email' => $b->getEmail(),
                'reason' => $b->getReason(),
                'createdAt' => $b->getCreatedAt()->format('d.m.Y H:i'),
            ];
        }, $records);

        $response->getBody()->write(json_encode([
            'success' => true,
            'emails' => $emails,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int) ceil($total / $limit),
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Kara liste kaydını güncelle
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $body = (string) $request->getBody();
        $data = json_decode($body, true) ?: $request->getParsedBody() ?: [];

        $record = $this->em->find(EmailBlacklist::class, $id);
        if (!$record) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Kayıt bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $email = trim($data['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Geçerli email girin']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $record->setEmail(strtolower($email));
        $record->setReason(!empty($data['reason']) ? trim((string) $data['reason']) : null);
        $this->em->flush();

        $response->getBody()->write(json_encode([
            'success' => true,
            'id' => $record->getId(),
            'email' => $record->getEmail(),
            'reason' => $record->getReason(),
        ], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Kara liste kaydını sil
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $record = $this->em->find(EmailBlacklist::class, $id);
        if (!$record) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Kayıt bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $this->em->remove($record);
        $this->em->flush();

        $response->getBody()->write(json_encode(['success' => true, 'id' => $id]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
