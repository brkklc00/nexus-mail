<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\User;
use App\Application\Services\AuditLoggerService;
use App\Application\Services\DomainConfigService;
use App\Infrastructure\Security\PasswordHasher;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Twig\Environment;
use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class AuthController
{
    private EntityManager $em;
    private Environment $twig;
    private PasswordHasher $passwordHasher;
    private AuditLoggerService $auditLogger;
    private DomainConfigService $domainConfigService;

    public function __construct(
        EntityManager $em,
        Environment $twig,
        PasswordHasher $passwordHasher,
        AuditLoggerService $auditLogger,
        DomainConfigService $domainConfigService
    ) {
        $this->em = $em;
        $this->twig = $twig;
        $this->passwordHasher = $passwordHasher;
        $this->auditLogger = $auditLogger;
        $this->domainConfigService = $domainConfigService;
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $domainConfig = $this->getDomainConfig($request);
        $html = $this->twig->render('auth/login.twig', $domainConfig);
        $response->getBody()->write($html);
        return $response;
    }

    /**
     * Request'ten domain'i alıp config'i döndürür
     */
    private function getDomainConfig(ServerRequestInterface $request): array
    {
        $host = null;
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        }
        
        // Port numarasını kaldır
        if ($host) {
            $host = preg_replace('/:\d+$/', '', $host);
        }
        
        // Localhost kontrolü
        $isLocalhost = !$host || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1']) 
            || preg_match('/^192\.168\./', $host) 
            || preg_match('/^10\./', $host) 
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host);
        
        // Domain config'i çek
        if (!$isLocalhost && $host) {
            $config = $this->domainConfigService->getDomainConfig($host);
            if ($config) {
                return [
                    'site_title' => $config['site_title'] ?? 'Nexus Panel',
                    'site_logo' => $config['site_logo'] ?? '/assets/images/nexus-logo.png',
                    'site_favicon' => $config['site_favicon'] ?? '/assets/images/default-avatar.png',
                    'site_default_avatar' => $config['site_default_avatar'] ?? '/assets/images/default-avatar.png',
                    'site_description' => $config['site_description'] ?? '',
                ];
            }
        }
        
        // Default değerler
        return [
            'site_title' => $_ENV['SITE_TITLE'] ?? 'Nexus Panel',
            'site_logo' => '/assets/images/nexus-logo.png',
            'site_favicon' => '/assets/images/default-avatar.png',
            'site_default_avatar' => '/assets/images/default-avatar.png',
            'site_description' => '',
        ];
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $request->getParsedBody();
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        /** @var User|null $user */
        // Eager load roles and permissions
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r', 'rp', 'p')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('r.permissions', 'rp')
            ->leftJoin('rp.permission', 'p')
            ->where('u.username = :username')
            ->setParameter('username', $username);
        
        $user = $qb->getQuery()->getOneOrNullResult();

        if (!$user || !$this->passwordHasher->verify($password, $user->getPassword())) {
            if ($user) {
                $this->auditLogger->logLogin($user->getId(), false);
            }
            
            $domainConfig = $this->getDomainConfig($request);
            $domainConfig['error'] = 'Kullanıcı adı veya şifre hatalı';
            $html = $this->twig->render('auth/login.twig', $domainConfig);
            $response->getBody()->write($html);
            return $response->withStatus(401);
        }

        if (!$user->isActive()) {
            $domainConfig = $this->getDomainConfig($request);
            $domainConfig['error'] = 'Hesabınız aktif değil';
            $html = $this->twig->render('auth/login.twig', $domainConfig);
            $response->getBody()->write($html);
            return $response->withStatus(403);
        }

        // Session başlat
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // 2FA kontrolü
        if ($user->has2FA()) {
            $_SESSION['pending_2fa_user_id'] = $user->getId();
            $_SESSION['requires_2fa'] = true;
            
            return $response
                ->withHeader('Location', '/2fa/verify')
                ->withStatus(302);
        }

        // Login başarılı
        $_SESSION['user_id'] = $user->getId();
        $_SESSION['user'] = [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'isAccountManager' => $user->isAccountManager(),
            'isChildAccount' => $user->isChildAccount(),
        ];

        // User permissions'ı session'a kaydet
        $_SESSION['user_permissions'] = [];
        $_SESSION['is_admin'] = false;
        
        foreach ($user->getRoles() as $role) {
            // Admin rolünü kontrol et
            if (strtolower($role->getName()) === 'admin') {
                $_SESSION['is_admin'] = true;
            }
            
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

        $user->setLastLoginAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->auditLogger->logLogin($user->getId(), true);

        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['user_id'] ?? null;
        
        if ($userId) {
            $this->auditLogger->logLogout($userId);
        }

        session_destroy();

        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    public function show2FA(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $domainConfig = $this->getDomainConfig($request);
        $html = $this->twig->render('auth/2fa.twig', $domainConfig);
        $response->getBody()->write($html);
        return $response;
    }

    public function verify2FA(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userId = $_SESSION['pending_2fa_user_id'] ?? null;
        
        if (!$userId) {
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        $data = $request->getParsedBody();
        $code = $data['code'] ?? '';

        /** @var User|null $user */
        // Eager load roles and permissions
        $qb = $this->em->createQueryBuilder();
        $qb->select('u', 'r', 'rp', 'p')
            ->from(User::class, 'u')
            ->leftJoin('u.roles', 'r')
            ->leftJoin('r.permissions', 'rp')
            ->leftJoin('rp.permission', 'p')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId);
        
        $user = $qb->getQuery()->getOneOrNullResult();

        if (!$user) {
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }

        $totp = TOTP::createFromSecret($user->getTwoFactorSecret());
        
        if ($totp->verify($code)) {
            // 2FA başarılı
            unset($_SESSION['pending_2fa_user_id']);
            unset($_SESSION['requires_2fa']);
            
            $_SESSION['user_id'] = $user->getId();
            $_SESSION['user'] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'isAccountManager' => $user->isAccountManager(),
                'isChildAccount' => $user->isChildAccount(),
            ];

            // User permissions'ı session'a kaydet
            $_SESSION['user_permissions'] = [];
            $_SESSION['is_admin'] = false;
            
            foreach ($user->getRoles() as $role) {
                // Admin rolünü kontrol et
                if (strtolower($role->getName()) === 'admin') {
                    $_SESSION['is_admin'] = true;
                }
                
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

            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->em->flush();

            $this->auditLogger->logLogin($user->getId(), true);

            return $response
                ->withHeader('Location', '/')
                ->withStatus(302);
        }

        $domainConfig = $this->getDomainConfig($request);
        $domainConfig['error'] = 'Geçersiz doğrulama kodu';
        $html = $this->twig->render('auth/2fa.twig', $domainConfig);
        $response->getBody()->write($html);
        return $response->withStatus(401);
    }

    public function showRegister(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $domainConfig = $this->getDomainConfig($request);
        $html = $this->twig->render('auth/register.twig', $domainConfig);
        $response->getBody()->write($html);
        return $response;
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Registration logic here
        // This is a placeholder - implement based on your requirements
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    public function showForgotPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $domainConfig = $this->getDomainConfig($request);
        $html = $this->twig->render('auth/forgot-password.twig', $domainConfig);
        $response->getBody()->write($html);
        return $response;
    }

    public function forgotPassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Password reset logic here
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
}

