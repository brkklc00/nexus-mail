<?php

declare(strict_types=1);

namespace App\Controllers;

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
        $type = $params['type'] ?? 'all';
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        // Stats
        $stats = $this->getStats($user, $type, $dateFrom, $dateTo);

        // Build query
        $qb = $this->em->createQueryBuilder();
        $qb->select('t')
            ->from(EmailTransaction::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user);

        // Apply filters
        if ($type !== 'all') {
            $typeEnum = match($type) {
                'credit' => \App\Domain\Enum\EmailTransactionType::CREDIT,
                'debit' => \App\Domain\Enum\EmailTransactionType::DEBIT,
                'refund' => \App\Domain\Enum\EmailTransactionType::REFUND,
                default => null
            };
            if ($typeEnum) {
                $qb->andWhere('t.type = :type')->setParameter('type', $typeEnum);
            }
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
        $transactions = $qb->orderBy('t.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / $perPage);

        $html = $this->twig->render('email-transactions/index.twig', [
            'transactions' => $transactions,
            'stats' => $stats,
            'total' => $total,
            'currentPage' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
            'filters' => [
                'type' => $type,
                'date_from' => $dateFrom,
                'date_to' => $dateTo
            ]
        ]);
        $response->getBody()->write($html);
        return $response;
    }

    private function getStats(User $user, string $type, ?string $dateFrom, ?string $dateTo): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->from(EmailTransaction::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user);

        // Apply same filters
        if ($type !== 'all') {
            $typeEnum = match($type) {
                'credit' => \App\Domain\Enum\EmailTransactionType::CREDIT,
                'debit' => \App\Domain\Enum\EmailTransactionType::DEBIT,
                'refund' => \App\Domain\Enum\EmailTransactionType::REFUND,
                default => null
            };
            if ($typeEnum) {
                $qb->andWhere('t.type = :type')->setParameter('type', $typeEnum);
            }
        }

        if ($dateFrom) {
            $qb->andWhere('t.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }

        if ($dateTo) {
            $qb->andWhere('t.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        // Total Credit In
        $qbIn = clone $qb;
        $creditIn = (float) $qbIn->select('SUM(t.amount)')
            ->andWhere('t.type = :typeCredit')
            ->setParameter('typeCredit', \App\Domain\Enum\EmailTransactionType::CREDIT)
            ->getQuery()
            ->getSingleScalarResult() ?: 0;

        // Total Credit Out
        $qbOut = clone $qb;
        $creditOut = (float) $qbOut->select('SUM(t.amount)')
            ->andWhere('t.type = :typeDebit')
            ->setParameter('typeDebit', \App\Domain\Enum\EmailTransactionType::DEBIT)
            ->getQuery()
            ->getSingleScalarResult() ?: 0;

        // Total Transactions
        $qbCount = clone $qb;
        $totalTransactions = (int) $qbCount->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Refund
        $qbRefund = clone $qb;
        $refund = (float) $qbRefund->select('SUM(t.amount)')
            ->andWhere('t.type = :typeRefund')
            ->setParameter('typeRefund', \App\Domain\Enum\EmailTransactionType::REFUND)
            ->getQuery()
            ->getSingleScalarResult() ?: 0;

        return [
            'credit_in' => $creditIn,
            'credit_out' => $creditOut,
            'total_transactions' => $totalTransactions,
            'refund' => $refund
        ];
    }

    public function export(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        if (!$user) {
            $response->getBody()->write('User bulunamadı');
            return $response->withStatus(403);
        }
        
        $params = $request->getQueryParams();
        
        // Filters
        $type = $params['type'] ?? 'all';
        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;

        // Build query
        $qb = $this->em->createQueryBuilder();
        $qb->select('t')
            ->from(EmailTransaction::class, 't')
            ->where('t.user = :user')
            ->setParameter('user', $user);

        if ($type !== 'all') {
            $typeEnum = match($type) {
                'credit' => \App\Domain\Enum\EmailTransactionType::CREDIT,
                'debit' => \App\Domain\Enum\EmailTransactionType::DEBIT,
                'refund' => \App\Domain\Enum\EmailTransactionType::REFUND,
                default => null
            };
            if ($typeEnum) {
                $qb->andWhere('t.type = :type')->setParameter('type', $typeEnum);
            }
        }

        if ($dateFrom) {
            $qb->andWhere('t.createdAt >= :dateFrom')
                ->setParameter('dateFrom', new \DateTime($dateFrom . ' 00:00:00'));
        }

        if ($dateTo) {
            $qb->andWhere('t.createdAt <= :dateTo')
                ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        $transactions = $qb->orderBy('t.createdAt', 'DESC')->getQuery()->getResult();

        // Generate CSV
        $csv = "ID,Tarih,Saat,Tip,Tutar,Önceki Bakiye,Sonraki Bakiye,Açıklama\n";
        
        foreach ($transactions as $t) {
            $csv .= sprintf(
                "%d,%s,%s,%s,%s,%s,%s,\"%s\"\n",
                $t->getId(),
                $t->getCreatedAt()->format('d.m.Y'),
                $t->getCreatedAt()->format('H:i:s'),
                $this->getTypeLabel($t->getType()->value),
                ($t->getType()->value == 'credit' ? '+' : '-') . $t->getAmount(),
                $t->getBalanceBefore(),
                $t->getBalanceAfter(),
                str_replace('"', '""', $t->getDescription() ?? '')
            );
        }

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="mail-islem-gecmisi-' . date('YmdHis') . '.csv"');
    }

    private function getTypeLabel(string $type): string
    {
        return match($type) {
            'credit' => 'Ekleme',
            'debit' => 'Çıkarma',
            'refund' => 'İade',
            default => 'Diğer'
        };
    }
}
