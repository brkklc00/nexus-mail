<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\Entities\EmailTransaction;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class EmailTransactionController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $twig
    ) {
    }

    /**
     * Tüm email işlemlerini listele
     */
    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $search = $params['search'] ?? '';
        $type = $params['type'] ?? '';
        $userId = $params['user_id'] ?? '';
        $page = (int) ($params['page'] ?? 1);
        $perPage = 50;

        $qb = $this->em->createQueryBuilder();
        $qb->select('t', 'u')
            ->from(EmailTransaction::class, 't')
            ->leftJoin('t.user', 'u')
            ->orderBy('t.createdAt', 'DESC');

        // Arama
        if ($search) {
            $qb->andWhere('t.description LIKE :search OR u.name LIKE :search OR u.email LIKE :search')
                ->setParameter('search', "%{$search}%");
        }

        // Type filtresi
        if ($type) {
            $qb->andWhere('t.type = :type')
                ->setParameter('type', $type);
        }

        // User filtresi
        if ($userId) {
            $qb->andWhere('t.user = :userId')
                ->setParameter('userId', $userId);
        }

        // Pagination
        $total = count($qb->getQuery()->getResult());
        $totalPages = ceil($total / $perPage);
        
        $transactions = $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        // İstatistikler
        $stats = $this->getStats();

        // Kullanıcı listesi
        $users = $this->em->createQueryBuilder()
            ->select('u.id', 'u.name', 'u.email')
            ->from(User::class, 'u')
            ->where('u.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('u.name', 'ASC')
            ->getQuery()
            ->getResult();

        $html = $this->twig->render('admin/email-transactions/index.twig', [
            'transactions' => $transactions,
            'stats' => $stats,
            'users' => $users,
            'search' => $search,
            'selected_type' => $type,
            'selected_user' => $userId,
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * İstatistikler
     */
    private function getStats(): array
    {
        // Toplam işlem
        $qb1 = $this->em->createQueryBuilder();
        $total = $qb1->select('COUNT(t.id)')
            ->from(EmailTransaction::class, 't')
            ->getQuery()
            ->getSingleScalarResult();

        // Toplam yükleme
        $qb2 = $this->em->createQueryBuilder();
        $totalDeposit = $qb2->select('SUM(t.amount)')
            ->from(EmailTransaction::class, 't')
            ->where('t.type IN (:types)')
            ->setParameter('types', ['credit', 'admin_credit'])
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Toplam harcama
        $qb3 = $this->em->createQueryBuilder();
        $totalSpent = $qb3->select('SUM(t.amount)')
            ->from(EmailTransaction::class, 't')
            ->where('t.type IN (:types)')
            ->setParameter('types', ['debit', 'campaign', 'order'])
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        // Toplam iade
        $qb4 = $this->em->createQueryBuilder();
        $totalRefund = $qb4->select('SUM(t.amount)')
            ->from(EmailTransaction::class, 't')
            ->where('t.type = :type')
            ->setParameter('type', 'refund')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        return [
            'total' => (int) $total,
            'total_deposit' => (float) $totalDeposit,
            'total_spent' => (float) $totalSpent,
            'total_refund' => (float) $totalRefund
        ];
    }
}

