<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Services\NotificationService;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

class NotificationController
{
    private EntityManager $em;
    private Environment $twig;
    private NotificationService $notificationService;

    public function __construct(
        EntityManager $em,
        Environment $twig,
        NotificationService $notificationService
    ) {
        $this->em = $em;
        $this->twig = $twig;
        $this->notificationService = $notificationService;
    }

    /**
     * Admin: Bildirim gönderme sayfası
     */
    public function adminIndex(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $users = [];
        $sentNotifications = [];
        $errorMessage = $_SESSION['flash_error'] ?? null;

        try {
            // Tüm aktif kullanıcıları getir
            $users = $this->em->getRepository(User::class)->findBy(['isActive' => true], ['name' => 'ASC']);

            // Son 50 gönderilen bildirimi getir
            $qb = $this->em->createQueryBuilder();
            $qb->select('n', 'u')
                ->from(\App\Domain\Entities\Notification::class, 'n')
                ->leftJoin('n.user', 'u')
                ->orderBy('n.createdAt', 'DESC')
                ->setMaxResults(50);

            $sentNotifications = $qb->getQuery()->getResult();
        } catch (\Throwable $e) {
            error_log('NotificationController::adminIndex error: ' . $e->getMessage());
            if (!$errorMessage) {
                $errorMessage = 'Bildirim sayfası yüklenirken bir hata oluştu. Detaylar loglara yazıldı.';
            }
        }
        
        $html = $this->twig->render('admin/notifications/index.twig', [
            'users' => $users,
            'sentNotifications' => $sentNotifications,
            'success' => $_SESSION['flash_success'] ?? null,
            'error' => $errorMessage,
            '_session' => $_SESSION,
        ]);

        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Admin: Bildirim gönder
     */
    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        $data = is_array($data) ? $data : [];
        
        $title = $data['title'] ?? '';
        $message = $data['message'] ?? '';
        $type = $data['type'] ?? 'info';
        $target = $data['target'] ?? 'all'; // all, selected
        
        if (empty($title) || empty($message)) {
            $_SESSION['flash_error'] = 'Başlık ve mesaj alanları zorunludur.';
            return $response
                ->withHeader('Location', '/admin/notifications')
                ->withStatus(302);
        }
        
        $count = 0;
        
        try {
            if ($target === 'all') {
                // Tüm kullanıcılara gönder
                $count = $this->notificationService->sendToAll($title, $message, $type);
            } elseif ($target === 'selected' && !empty($data['user_ids'])) {
                // Seçili kullanıcılara gönder
                $userIds = is_array($data['user_ids']) ? $data['user_ids'] : [$data['user_ids']];
                $count = $this->notificationService->sendToUsers($userIds, $title, $message, $type);
            }

            $_SESSION['flash_success'] = "{$count} kullanıcıya bildirim gönderildi.";
        } catch (\Throwable $e) {
            error_log('NotificationController::send error: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Bildirim gönderilemedi. Lütfen sistem loglarını kontrol edin.';
        }
        
        return $response
            ->withHeader('Location', '/admin/notifications')
            ->withStatus(302);
    }

    /**
     * Kullanıcı: Bildirimlerini görüntüle
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        $notifications = $this->notificationService->getAllForUser($user, 50);
        
        $html = $this->twig->render('notifications/index.twig', [
            'notifications' => $notifications,
            '_session' => $_SESSION,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Kullanıcı: Okunmamış bildirimleri getir (AJAX)
     */
    public function getUnread(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        $notifications = $this->notificationService->getUnreadForUser($user);
        
        $data = [];
        foreach ($notifications as $notification) {
            $data[] = [
                'id' => $notification->getId(),
                'title' => $notification->getTitle(),
                'message' => $notification->getMessage(),
                'type' => $notification->getType(),
                'createdAt' => $notification->getCreatedAt()->format('d.m.Y H:i'),
            ];
        }
        
        $response->getBody()->write(json_encode([
            'count' => count($data),
            'notifications' => $data,
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Kullanıcı: Bildirimi okundu olarak işaretle
     */
    public function markAsRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $notificationId = (int) $args['id'];
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        
        $notification = $this->em->find(\App\Domain\Entities\Notification::class, $notificationId);
        
        if (!$notification || $notification->getUser()->getId() !== $userId) {
            $response->getBody()->write(json_encode(['success' => false]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        
        $this->notificationService->markAsRead($notification);
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Kullanıcı: Tüm bildirimleri okundu olarak işaretle
     */
    public function markAllAsRead(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        
        $count = $this->notificationService->markAllAsReadForUser($user);
        
        $response->getBody()->write(json_encode([
            'success' => true,
            'count' => $count,
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Admin: Bildirim detayını görüntüle (AJAX)
     */
    public function view(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $notificationId = (int) $args['id'];
        
        $qb = $this->em->createQueryBuilder();
        $qb->select('n', 'u')
            ->from(\App\Domain\Entities\Notification::class, 'n')
            ->leftJoin('n.user', 'u')
            ->where('n.id = :id')
            ->setParameter('id', $notificationId);
        
        $notification = $qb->getQuery()->getOneOrNullResult();
        
        if (!$notification) {
            $response->getBody()->write(json_encode(['error' => 'Bildirim bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        
        $html = $this->twig->render('admin/notifications/_detail.twig', [
            'notification' => $notification,
        ]);
        
        $response->getBody()->write(json_encode(['html' => $html]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Admin: Bildirim sil
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $notificationId = (int) $args['id'];
        
        $notification = $this->em->find(\App\Domain\Entities\Notification::class, $notificationId);
        
        if (!$notification) {
            $response->getBody()->write(json_encode(['success' => false, 'error' => 'Bildirim bulunamadı']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }
        
        $this->em->remove($notification);
        $this->em->flush();
        
        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
