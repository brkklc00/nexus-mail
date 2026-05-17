<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\Notification;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManager;

class NotificationService
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * Belirli bir kullanıcıya bildirim gönder
     */
    public function sendToUser(User $user, string $title, string $message, string $type = 'info'): Notification
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setTitle($title);
        $notification->setMessage($message);
        $notification->setType($type);

        $this->em->persist($notification);
        $this->em->flush();

        return $notification;
    }

    /**
     * Tüm kullanıcılara bildirim gönder
     */
    public function sendToAll(string $title, string $message, string $type = 'info'): int
    {
        $users = $this->em->getRepository(User::class)->findBy(['isActive' => true]);
        
        $count = 0;
        foreach ($users as $user) {
            $notification = new Notification();
            $notification->setUser($user);
            $notification->setTitle($title);
            $notification->setMessage($message);
            $notification->setType($type);
            
            $this->em->persist($notification);
            $count++;
        }
        
        $this->em->flush();
        
        return $count;
    }

    /**
     * Seçili kullanıcılara bildirim gönder
     */
    public function sendToUsers(array $userIds, string $title, string $message, string $type = 'info'): int
    {
        $count = 0;
        foreach ($userIds as $userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user && $user->isActive()) {
                $notification = new Notification();
                $notification->setUser($user);
                $notification->setTitle($title);
                $notification->setMessage($message);
                $notification->setType($type);
                
                $this->em->persist($notification);
                $count++;
            }
        }
        
        $this->em->flush();
        
        return $count;
    }

    /**
     * Kullanıcının okunmamış bildirimlerini getir
     */
    public function getUnreadForUser(User $user): array
    {
        return $this->em->getRepository(Notification::class)
            ->findBy(
                ['user' => $user, 'isRead' => false],
                ['createdAt' => 'DESC']
            );
    }

    /**
     * Kullanıcının tüm bildirimlerini getir
     */
    public function getAllForUser(User $user, int $limit = 50): array
    {
        return $this->em->getRepository(Notification::class)
            ->findBy(
                ['user' => $user],
                ['createdAt' => 'DESC'],
                $limit
            );
    }

    /**
     * Bildirimi okundu olarak işaretle
     */
    public function markAsRead(Notification $notification): void
    {
        $notification->markAsRead();
        $this->em->flush();
    }

    /**
     * Kullanıcının tüm bildirimlerini okundu olarak işaretle
     */
    public function markAllAsReadForUser(User $user): int
    {
        $qb = $this->em->createQueryBuilder();
        $qb->update(Notification::class, 'n')
            ->set('n.isRead', ':isRead')
            ->set('n.readAt', ':readAt')
            ->where('n.user = :user')
            ->andWhere('n.isRead = false')
            ->setParameter('isRead', true)
            ->setParameter('readAt', new \DateTimeImmutable())
            ->setParameter('user', $user);

        return $qb->getQuery()->execute();
    }
}
