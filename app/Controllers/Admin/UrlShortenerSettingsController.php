<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\Entities\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;
use Doctrine\ORM\EntityManager;

class UrlShortenerSettingsController
{
    public function __construct(
        private Environment $twig,
        private EntityManager $entityManager
    ) {
    }

    /**
     * URL Shortener ayarları listesi (tüm kullanıcılar)
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $search = $queryParams['search'] ?? '';
        $statusFilter = $queryParams['status_filter'] ?? 'all';
        $currentPage = (int) ($queryParams['page'] ?? 1);
        $perPage = 20;

        // İstatistikler
        $totalUsersWithShortener = $this->entityManager
            ->createQuery('SELECT COUNT(u.id) FROM App\Domain\Entities\User u WHERE u.urlShortenerEnabled = true')
            ->getSingleScalarResult();

        $unlimitedUsers = $this->entityManager
            ->createQuery('SELECT COUNT(u.id) FROM App\Domain\Entities\User u WHERE u.urlShortenerEnabled = true AND u.urlShortenerMaxUrls IS NULL')
            ->getSingleScalarResult();

        $limitedUsers = $this->entityManager
            ->createQuery('SELECT COUNT(u.id) FROM App\Domain\Entities\User u WHERE u.urlShortenerEnabled = true AND u.urlShortenerMaxUrls IS NOT NULL')
            ->getSingleScalarResult();

        $totalShortUrls = $this->entityManager
            ->createQuery('SELECT COUNT(s.id) FROM App\Domain\Entities\ShortenedUrl s')
            ->getSingleScalarResult();

        // Kullanıcıları getir
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('u')
            ->from('App\Domain\Entities\User', 'u')
            ->orderBy('u.createdAt', 'DESC');

        // Arama
        if ($search) {
            $qb->andWhere('u.name LIKE :search OR u.email LIKE :search OR u.username LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Durum filtre
        if ($statusFilter === 'enabled') {
            $qb->andWhere('u.urlShortenerEnabled = true');
        } elseif ($statusFilter === 'disabled') {
            $qb->andWhere('u.urlShortenerEnabled = false');
        } elseif ($statusFilter === 'unlimited') {
            $qb->andWhere('u.urlShortenerEnabled = true')
                ->andWhere('u.urlShortenerMaxUrls IS NULL');
        } elseif ($statusFilter === 'limited') {
            $qb->andWhere('u.urlShortenerEnabled = true')
                ->andWhere('u.urlShortenerMaxUrls IS NOT NULL');
        }

        // Pagination
        $totalUsers = count($qb->getQuery()->getResult());
        $totalPages = ceil($totalUsers / $perPage);
        
        $users = $qb->setFirstResult(($currentPage - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        // Her kullanıcının URL sayısını getir
        $userUrlCounts = [];
        foreach ($users as $user) {
            $count = $this->entityManager
                ->createQuery('SELECT COUNT(s.id) FROM App\Domain\Entities\ShortenedUrl s WHERE s.user = :user')
                ->setParameter('user', $user)
                ->getSingleScalarResult();
            $userUrlCounts[$user->getId()] = $count;
        }

        $html = $this->twig->render('admin/url-shortener-settings/index.twig', [
            'users' => $users,
            'search' => $search,
            'statusFilter' => $statusFilter,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'userUrlCounts' => $userUrlCounts,
            'stats' => [
                'total_users' => $totalUsersWithShortener,
                'unlimited_users' => $unlimitedUsers,
                'limited_users' => $limitedUsers,
                'total_urls' => $totalShortUrls,
            ],
            'availableDomains' => ['shrt-link.com', 'clicky.cx', 'shrtlink.io'],
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Kullanıcı ayarlarını güncelle
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = (int) $args['id'];
        $user = $this->entityManager->find(User::class, $userId);

        if (!$user) {
            $_SESSION['error'] = 'Kullanıcı bulunamadı';
            return $response->withHeader('Location', '/admin/url-shortener-settings')->withStatus(302);
        }

        $data = $request->getParsedBody();

        // Ayarları güncelle
        $user->setUrlShortenerEnabled(isset($data['enabled']));
        
        // Max URL limiti
        $maxUrls = $data['max_urls'] ?? null;
        if ($maxUrls === '' || $maxUrls === 'unlimited') {
            $user->setUrlShortenerMaxUrls(null);
        } else {
            $user->setUrlShortenerMaxUrls((int) $maxUrls);
        }

        // İzin verilen domainler (JSON array olarak sakla)
        $allowedDomains = $data['allowed_domains'] ?? [];
        if (empty($allowedDomains)) {
            $user->setUrlShortenerAllowedDomains(null);
        } else {
            $user->setUrlShortenerAllowedDomains($allowedDomains);
        }

        $this->entityManager->flush();

        $_SESSION['success'] = 'Ayarlar güncellendi';
        return $response->withHeader('Location', '/admin/url-shortener-settings')->withStatus(302);
    }

    /**
     * Toplu aktif/pasif yapma
     */
    public function bulkToggle(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $userIds = $data['user_ids'] ?? [];
        $action = $data['action'] ?? null;

        if (empty($userIds) || !$action) {
            $_SESSION['error'] = 'Geçersiz işlem';
            return $response->withHeader('Location', '/admin/url-shortener-settings')->withStatus(302);
        }

        $enabled = ($action === 'enable');

        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, (int) $userId);
            if ($user) {
                $user->setUrlShortenerEnabled($enabled);
            }
        }

        $this->entityManager->flush();

        $_SESSION['success'] = count($userIds) . ' kullanıcı güncellendi';
        return $response->withHeader('Location', '/admin/url-shortener-settings')->withStatus(302);
    }

    /**
     * Toplu limit güncelleme
     */
    public function bulkUpdate(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $userIds = $data['user_ids'] ?? [];
        $maxUrls = $data['max_urls'] ?? null;
        $allowedDomains = $data['allowed_domains'] ?? [];

        if (empty($userIds)) {
            $_SESSION['error'] = 'Kullanıcı seçilmedi';
            return $response->withHeader('Location', '/admin/url-shortener-settings')->withStatus(302);
        }

        foreach ($userIds as $userId) {
            $user = $this->entityManager->find(User::class, (int) $userId);
            if ($user) {
                // Max URL
                if ($maxUrls === '' || $maxUrls === 'unlimited') {
                    $user->setUrlShortenerMaxUrls(null);
                } elseif ($maxUrls !== null) {
                    $user->setUrlShortenerMaxUrls((int) $maxUrls);
                }

                // İzin verilen domainler
                if (!empty($allowedDomains)) {
                    $user->setUrlShortenerAllowedDomains($allowedDomains);
                }
            }
        }

        $this->entityManager->flush();

        $_SESSION['success'] = count($userIds) . ' kullanıcı güncellendi';
        return $response->withHeader('Location', '/admin/url-shortener-settings')->withStatus(302);
    }
}
