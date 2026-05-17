<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\AuditLog;
use Doctrine\ORM\EntityManager;

class AuditLoggerService
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function log(
        ?int $userId,
        string $event,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $log = new AuditLog();
        $log->setUserId($userId);
        $log->setEvent($event);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setOldValues($oldValues);
        $log->setNewValues($newValues);
        
        // Request bilgilerini al
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $log->setIp($_SERVER['REMOTE_ADDR']);
        }
        
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $log->setUserAgent(substr($_SERVER['HTTP_USER_AGENT'], 0, 500));
        }

        $this->em->persist($log);
        $this->em->flush();
    }

    public function logLogin(int $userId, bool $success): void
    {
        $this->log(
            $userId,
            $success ? 'user.login.success' : 'user.login.failed',
            'User',
            $userId
        );
    }

    public function logLogout(int $userId): void
    {
        $this->log($userId, 'user.logout', 'User', $userId);
    }

    public function log2FAToggle(int $userId, bool $enabled): void
    {
        $event = $enabled ? 'user.2fa.enabled' : 'user.2fa.disabled';
        $this->log($userId, $event, 'User', $userId);
    }

    public function logCreate(int $userId, string $entityType, int $entityId, array $values): void
    {
        $this->log($userId, "{$entityType}.created", $entityType, $entityId, null, $values);
    }

    public function logUpdate(int $userId, string $entityType, int $entityId, array $oldValues, array $newValues): void
    {
        $this->log($userId, "{$entityType}.updated", $entityType, $entityId, $oldValues, $newValues);
    }

    public function logDelete(int $userId, string $entityType, int $entityId, array $values): void
    {
        $this->log($userId, "{$entityType}.deleted", $entityType, $entityId, $values, null);
    }
}

