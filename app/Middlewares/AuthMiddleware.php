<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Domain\Entities\User;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class AuthMiddleware implements MiddlewareInterface
{
    private EntityManager $em;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Session başlat
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Kullanıcı login olmuş mu?
        if (!isset($_SESSION['user_id'])) {
            $response = new Response();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        // Kullanıcı veritabanında hâlâ mevcut mu? (silinmiş kullanıcı veya eski oturum)
        $user = $this->em->find(User::class, (int) $_SESSION['user_id']);
        if (!$user) {
            session_destroy();
            $response = new Response();
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        // 2FA kontrolü - eğer 2FA aktifse ve doğrulanmamışsa
        if (isset($_SESSION['requires_2fa']) && $_SESSION['requires_2fa'] === true) {
            // 2FA sayfasına yönlendir
            if ($request->getUri()->getPath() !== '/2fa/verify') {
                $response = new Response();
                return $response
                    ->withHeader('Location', '/2fa/verify')
                    ->withStatus(302);
            }
        }

        // User bilgisini request'e ekle
        $request = $request->withAttribute('user_id', $_SESSION['user_id']);
        
        // User'ın yetkilerini session'a kaydet (sidebar için)
        if (!isset($_SESSION['user_permissions'])) {
            // Eager load roles and permissions
            $qb = $this->em->createQueryBuilder();
            $qb->select('u', 'r', 'rp', 'p')
                ->from(User::class, 'u')
                ->leftJoin('u.roles', 'r')
                ->leftJoin('r.permissions', 'rp')
                ->leftJoin('rp.permission', 'p')
                ->where('u.id = :userId')
                ->setParameter('userId', $_SESSION['user_id']);
            
            $user = $qb->getQuery()->getOneOrNullResult();
            
            if ($user) {
                $_SESSION['user_permissions'] = [];
                foreach ($user->getRoles() as $role) {
                    foreach ($role->getPermissions() as $rolePermission) {
                        $permKey = $rolePermission->getPermission()->getKey();
                        if (!isset($_SESSION['user_permissions'][$permKey])) {
                            $_SESSION['user_permissions'][$permKey] = [
                                'read' => false,
                                'create' => false,
                                'update' => false,
                                'delete' => false,
                            ];
                        }
                        $_SESSION['user_permissions'][$permKey]['read'] = $_SESSION['user_permissions'][$permKey]['read'] || $rolePermission->canRead();
                        $_SESSION['user_permissions'][$permKey]['create'] = $_SESSION['user_permissions'][$permKey]['create'] || $rolePermission->canCreate();
                        $_SESSION['user_permissions'][$permKey]['update'] = $_SESSION['user_permissions'][$permKey]['update'] || $rolePermission->canUpdate();
                        $_SESSION['user_permissions'][$permKey]['delete'] = $_SESSION['user_permissions'][$permKey]['delete'] || $rolePermission->canDelete();
                    }
                }
            }
        }

        return $handler->handle($request);
    }
}

