<?php

declare(strict_types=1);

namespace App\Middlewares;

use Doctrine\ORM\EntityManager;
use App\Domain\Entities\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;
use Twig\Environment;

class PermissionMiddleware implements MiddlewareInterface
{
    private EntityManager $em;
    private Environment $twig;
    private string $permission;
    private string $action;

    public function __construct(EntityManager $em, Environment $twig, string $permission, string $action = 'read')
    {
        $this->em = $em;
        $this->twig = $twig;
        $this->permission = $permission;
        $this->action = $action;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Önce session kontrolü yap
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user'])) {
            // AJAX isteği kontrolü
            $isAjax = $this->isAjaxRequest($request);
            
            $response = new Response();
            
            if ($isAjax) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Oturum süreniz dolmuş. Lütfen tekrar giriş yapın.'
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(401);
            }
            
            return $response
                ->withHeader('Location', '/login')
                ->withStatus(302);
        }
        
        // Kullanıcı bazlı sayfa erişim kontrolü
        $userId = $_SESSION['user']['id'] ?? null;
        if ($userId) {
            $user = $this->em->find(User::class, $userId);
            if ($user) {
                $requestPath = $request->getUri()->getPath();
                
                // Sayfa erişim kontrolü (allowedPages null değilse kontrol et)
                if (!$user->canAccessPage($requestPath)) {
                    $isAjax = $this->isAjaxRequest($request);
                    $response = new Response();
                    
                    if ($isAjax) {
                        $response->getBody()->write(json_encode([
                            'success' => false,
                            'message' => 'Bu sayfaya erişim izniniz yok.'
                        ]));
                        return $response
                            ->withHeader('Content-Type', 'application/json')
                            ->withStatus(403);
                    }
                    
                    try {
                        $html = $this->twig->render('errors/403.twig', [
                            'message' => 'Bu sayfaya erişim izniniz yok. Lütfen yöneticinizle iletişime geçin.',
                            '_session' => $_SESSION ?? [],
                        ]);
                    } catch (\Exception $e) {
                        $html = '<!DOCTYPE html>
                        <html>
                        <head>
                            <title>403 - Yetkisiz Erişim</title>
                            <style>
                                body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
                                .error-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
                                h1 { color: #e74c3c; font-size: 72px; margin: 0; }
                                h2 { color: #333; margin: 20px 0; }
                                p { color: #666; line-height: 1.6; }
                            </style>
                        </head>
                        <body>
                            <div class="error-box">
                                <h1>403</h1>
                                <h2>⚠️ Yetkisiz Erişim</h2>
                                <p>Bu sayfaya erişim izniniz yok. Lütfen yöneticinizle iletişime geçin.</p>
                                <a href="/">🏠 Ana Sayfa</a>
                            </div>
                        </body>
                        </html>';
                    }
                    
                    $response->getBody()->write($html);
                    return $response->withStatus(403);
                }
            }
        }

        // Admin kullanıcılar tüm izinlere sahip
        $isAdmin = $_SESSION['is_admin'] ?? false;
        
        // Session'dan permission kontrolü yap
        $hasPermission = $isAdmin || (
            isset($_SESSION['user_permissions'][$this->permission][$this->action]) 
            && $_SESSION['user_permissions'][$this->permission][$this->action]
        );

        if (!$hasPermission) {
            // AJAX isteği kontrolü
            $isAjax = $this->isAjaxRequest($request);
            
            $response = new Response();
            
            if ($isAjax) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Bu işlemi gerçekleştirmek için yetkiniz yok.'
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(403);
            }
            
            try {
                $html = $this->twig->render('errors/403.twig', [
                    'permission' => $this->permission,
                    'action' => $this->action,
                    '_session' => $_SESSION ?? [],
                ]);
            } catch (\Exception $e) {
                // Twig render hatası durumunda loglayıp basit HTML göster
                error_log("403 Template Render Error: " . $e->getMessage());
                error_log("Trace: " . $e->getTraceAsString());
                
                $html = '<!DOCTYPE html>
                <html>
                <head>
                    <title>403 - Yetkisiz Erişim</title>
                    <style>
                        body { font-family: Arial, sans-serif; text-align: center; padding: 50px; background: #f5f5f5; }
                        .error-box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 500px; margin: 0 auto; }
                        h1 { color: #e74c3c; font-size: 72px; margin: 0; }
                        h2 { color: #333; margin: 20px 0; }
                        p { color: #666; line-height: 1.6; }
                        .btn { display: inline-block; padding: 12px 24px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin: 10px; }
                        .btn:hover { background: #2980b9; }
                        .permission-info { background: #fff3cd; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #ffc107; }
                    </style>
                </head>
                <body>
                    <div class="error-box">
                        <h1>403</h1>
                        <h2>⚠️ Yetkisiz Erişim</h2>
                        <p>Bu işlemi gerçekleştirmek için yetkiniz yok.</p>
                        <div class="permission-info">
                            <strong>Gereken Yetki:</strong><br>
                            <code>' . htmlspecialchars($this->permission) . ' → ' . htmlspecialchars($this->action) . '</code>
                        </div>
                        <a href="/" class="btn">🏠 Ana Sayfa</a>
                        <a href="javascript:history.back()" class="btn">⬅️ Geri Dön</a>
                    </div>
                </body>
                </html>';
            }
            
            $response->getBody()->write($html);
            return $response->withStatus(403);
        }

        return $handler->handle($request);
    }
    
    /**
     * AJAX isteği olup olmadığını kontrol eder
     */
    private function isAjaxRequest(ServerRequestInterface $request): bool
    {
        // XMLHttpRequest header kontrolü
        $requestedWith = $request->getHeaderLine('X-Requested-With');
        if (strtolower($requestedWith) === 'xmlhttprequest') {
            return true;
        }
        
        // Content-Type veya Accept header'ında JSON kontrolü
        $accept = $request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        
        // Fetch API için Sec-Fetch-Dest header kontrolü
        $fetchDest = $request->getHeaderLine('Sec-Fetch-Dest');
        if ($fetchDest === 'empty') {
            return true;
        }
        
        return false;
    }
}

