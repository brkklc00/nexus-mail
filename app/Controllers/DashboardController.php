<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\EmailOrder;
use App\Domain\Entities\EmailSmtpAccount;
use App\Domain\Entities\SupportTicket;
use App\Domain\Entities\User;
use App\Domain\Entities\ShortenedUrl;
use App\Domain\Enum\TicketStatus;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

class DashboardController
{
    private EntityManager $em;
    private Environment $twig;

    public function __construct(
        EntityManager $em,
        Environment $twig
    ) {
        $this->em = $em;
        $this->twig = $twig;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        
        /** @var User|null $user */
        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            session_destroy();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }
        
        // Mail bakiyesi
        $emailCredit = $user->getEmailCredit();
        
        // Son 10 Email siparişi
        $recentEmailOrders = $this->em->getRepository(EmailOrder::class)
            ->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC'],
                10
            );
        
        // Son 10 Kısaltılmış URL
        $recentUrls = $this->em->getRepository(ShortenedUrl::class)
            ->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC'],
                10
            );
        
        // İstatistikler
        $emailStats = $this->getEmailStatistics($userId);
        $urlStats = $this->getUrlShortenerStatistics($userId);
        $smtpStats = $this->getSmtpStatistics();
        $usageStats = $this->getDailyUsageStatistics();
        $supportStats = $this->getSupportStatistics($userId);
        
        // Son 7 günlük grafik verileri
        $chartData = $this->getLast7DaysEmailChartData($userId);
        
        $html = $this->twig->render('dashboard/index.twig', [
            'user' => $user,
            'emailCredit' => $emailCredit,
            'recentEmailOrders' => $recentEmailOrders,
            'recentUrls' => $recentUrls,
            'emailStats' => $emailStats,
            'urlStats' => $urlStats,
            'smtpStats' => $smtpStats,
            'usageStats' => $usageStats,
            'supportStats' => $supportStats,
            'chartData' => $chartData,
            '_session' => $_SESSION,
        ]);
        
        $response->getBody()->write($html);
        return $response;
    }

    private function getEmailStatistics(int $userId): array
    {
        $totals = $this->em->createQueryBuilder()
            ->select(
                'COALESCE(SUM(e.sent), 0) AS totalSent',
                'COALESCE(SUM(e.delivered), 0) AS totalDelivered',
                'COALESCE(SUM(e.failed), 0) AS totalFailed',
                'COALESCE(SUM(e.bounced), 0) AS totalBounced'
            )
            ->from(EmailOrder::class, 'e')
            ->where('e.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleResult();
        
        // Toplam sipariş sayısı
        $totalOrders = $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(EmailOrder::class, 'e')
            ->where('e.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        $totalSent = (int) ($totals['totalSent'] ?? 0);
        $totalDelivered = (int) ($totals['totalDelivered'] ?? 0);
        $totalFailed = (int) ($totals['totalFailed'] ?? 0);
        $totalBounced = (int) ($totals['totalBounced'] ?? 0);

        return [
            'total_sent' => $totalSent,
            'total_delivered' => $totalDelivered,
            'total_failed' => $totalFailed,
            'total_bounced' => $totalBounced,
            'total_orders' => (int)$totalOrders,
            'success_rate' => $totalSent > 0 ? round(($totalDelivered / $totalSent) * 100, 1) : 0,
        ];
    }

    private function getUrlShortenerStatistics(int $userId): array
    {
        $user = $this->em->find(User::class, $userId);
        
        // Toplam kısaltılmış URL sayısı
        $totalUrls = $this->em->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(ShortenedUrl::class, 'u')
            ->where('u.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
        
        // Toplam tıklanma sayısı
        $totalClicks = $this->em->createQueryBuilder()
            ->select('SUM(u.clickCount)')
            ->from(ShortenedUrl::class, 'u')
            ->where('u.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        return [
            'total_urls' => (int)$totalUrls,
            'total_clicks' => (int)$totalClicks,
        ];
    }

    private function getSmtpStatistics(): array
    {
        $active = $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = true')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        $total = $this->em->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(EmailSmtpAccount::class, 's')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        return [
            'active' => (int) $active,
            'total' => (int) $total,
        ];
    }

    private function getDailyUsageStatistics(): array
    {
        $row = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(s.dailySent), 0) AS sentToday', 'COALESCE(SUM(s.dailyLimit), 0) AS limitToday')
            ->from(EmailSmtpAccount::class, 's')
            ->where('s.isActive = true')
            ->getQuery()
            ->getSingleResult();

        $sent = (int) ($row['sentToday'] ?? 0);
        $limit = (int) ($row['limitToday'] ?? 0);

        return [
            'sent_today' => $sent,
            'limit_today' => $limit,
            'percent' => $limit > 0 ? round(($sent / $limit) * 100, 1) : 0.0,
        ];
    }

    private function getSupportStatistics(int $userId): array
    {
        $openTickets = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(SupportTicket::class, 't')
            ->where('t.user = :userId')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('userId', $userId)
            ->setParameter('statuses', [TicketStatus::OPEN->value, TicketStatus::PENDING->value])
            ->getQuery()
            ->getSingleScalarResult() ?? 0;

        return [
            'open_tickets' => (int) $openTickets,
        ];
    }

    private function getLast7DaysEmailChartData(int $userId): array
    {
        $dates = [];
        $emailData = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = new \DateTime();
            $date->modify("-$i days");
            $startDate = clone $date;
            $startDate->setTime(0, 0, 0);
            $endDate = clone $date;
            $endDate->setTime(23, 59, 59);
            
            $dates[] = $date->format('d.m');
            
            // Email verileri
            $emailSent = $this->em->createQueryBuilder()
                ->select('SUM(e.sent)')
                ->from(EmailOrder::class, 'e')
                ->where('e.user = :userId')
                ->andWhere('e.createdAt >= :startDate')
                ->andWhere('e.createdAt <= :endDate')
                ->setParameter('userId', $userId)
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate)
                ->getQuery()
                ->getSingleScalarResult() ?? 0;
            
            $emailData[] = (int)$emailSent;
        }
        
        return [
            'dates' => $dates,
            'email' => $emailData,
        ];
    }
}

