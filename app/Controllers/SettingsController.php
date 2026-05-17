<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\Entities\User;
use App\Domain\Entities\EmailTransaction;
use App\Infrastructure\Security\PasswordHasher;
use App\Application\Services\AuditLoggerService;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use OTPHP\TOTP;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class SettingsController
{
    private EntityManager $em;
    private Environment $twig;
    private PasswordHasher $passwordHasher;
    private AuditLoggerService $auditLogger;

    public function __construct(
        EntityManager $em,
        Environment $twig,
        PasswordHasher $passwordHasher,
        AuditLoggerService $auditLogger
    ) {
        $this->em = $em;
        $this->twig = $twig;
        $this->passwordHasher = $passwordHasher;
        $this->auditLogger = $auditLogger;
    }

    public function profile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        $html = $this->twig->render('settings/profile.twig', [
            'user' => $user,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function updateProfile(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $data = $request->getParsedBody();

        $user->setName($data['name'] ?? '');
        $user->setEmail($data['email'] ?? '');

        $this->em->flush();

        $_SESSION['flash_success'] = 'Profil güncellendi.';

        return $response
            ->withHeader('Location', '/settings/profile')
            ->withStatus(302);
    }

    public function enable2FA(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        // TOTP oluştur
        $totp = TOTP::create();
        $totp->setLabel($user->getEmail());
        $totp->setIssuer('Nexus');

        // Recovery codes oluştur
        $recoveryCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $recoveryCodes[] = bin2hex(random_bytes(4));
        }

        $user->setTwoFactorSecret($totp->getSecret());
        $user->setTwoFactorRecoveryCodes($recoveryCodes);
        $this->em->flush();

        // QR code oluştur
        $qrCode = QrCode::create($totp->getProvisioningUri());
        $writer = new PngWriter();
        $result = $writer->write($qrCode);
        $qrDataUri = $result->getDataUri();

        $_SESSION['2fa_setup'] = [
            'qr_code' => $qrDataUri,
            'secret' => $totp->getSecret(),
            'recovery_codes' => $recoveryCodes,
        ];

        // AJAX request ise JSON döndür
        $contentType = $request->getHeaderLine('Accept');
        if (strpos($contentType, 'application/json') !== false || $request->getMethod() === 'POST') {
            $response->getBody()->write(json_encode([
                'success' => true,
                'qr_code' => $qrDataUri,
                'secret' => $totp->getSecret(),
                'recovery_codes' => $recoveryCodes,
            ], JSON_UNESCAPED_UNICODE));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Normal sayfa request ise HTML döndür
        $html = $this->twig->render('settings/2fa-setup.twig', [
            'user' => $user,
            'qrCode' => $qrDataUri,
            'secret' => $totp->getSecret(),
            'recoveryCodes' => $recoveryCodes,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function confirm2FA(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $data = $request->getParsedBody();
        
        $code = $data['code'] ?? '';
        
        // Debug log
        error_log("2FA Confirm - User: {$userId}, Code: {$code}, Secret: " . substr($user->getTwoFactorSecret(), 0, 10) . "...");

        $totp = TOTP::createFromSecret($user->getTwoFactorSecret());
        
        // Kod doğrulama (window parametresi ile - zaman toleransı)
        $isValid = $totp->verify($code, null, 2); // 2 window = ±60 saniye tolerans

        if ($isValid) {
            $this->auditLogger->log2FAToggle($userId, true);
            unset($_SESSION['2fa_setup']);
            
            // AJAX request ise JSON döndür
            $contentType = $request->getHeaderLine('Accept');
            if (strpos($contentType, 'application/json') !== false) {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => '2FA başarıyla etkinleştirildi'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }
            
            $_SESSION['flash_success'] = '2FA başarıyla etkinleştirildi.';
            return $response
                ->withHeader('Location', '/dashboard')
                ->withStatus(302);
        }

        error_log("2FA Confirm - Kod doğrulanamadı!");
        
        // AJAX request ise JSON döndür
        $contentType = $request->getHeaderLine('Accept');
        if (strpos($contentType, 'application/json') !== false) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Kod hatalı veya süresi dolmuş. Lütfen yeni bir kod deneyin.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        $_SESSION['flash_error'] = 'Geçersiz doğrulama kodu.';
        return $response
            ->withHeader('Location', '/dashboard')
            ->withStatus(302);
    }

    public function disable2FA(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        $user->setTwoFactorSecret(null);
        $user->setTwoFactorRecoveryCodes(null);
        $this->em->flush();

        $this->auditLogger->log2FAToggle($userId, false);
        
        // AJAX request ise JSON döndür
        $contentType = $request->getHeaderLine('Accept');
        if (strpos($contentType, 'application/json') !== false) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => '2FA devre dışı bırakıldı'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        // Normal request ise redirect
        $_SESSION['flash_success'] = '2FA devre dışı bırakıldı.';
        return $response
            ->withHeader('Location', '/dashboard')
            ->withStatus(302);
    }

    public function updatePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $data = $request->getParsedBody();

        // Mevcut şifre kontrol
        if (!$this->passwordHasher->verify($data['current_password'] ?? '', $user->getPassword())) {
            // AJAX request ise JSON döndür
            $contentType = $request->getHeaderLine('Accept');
            if (strpos($contentType, 'application/json') !== false) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Mevcut şifreniz hatalı'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            
            $_SESSION['flash_error'] = 'Mevcut şifre hatalı.';
            return $response
                ->withHeader('Location', '/dashboard')
                ->withStatus(302);
        }

        // Yeni şifreyi hashle ve kaydet
        $user->setPassword($this->passwordHasher->hash($data['new_password'] ?? ''));
        $this->em->flush();

        $this->auditLogger->log($userId, 'user.password_changed', 'User', $userId);
        
        // Şifre değişti - Oturumu kapat (güvenlik için)
        // Session'ı temizle
        $_SESSION = [];
        
        // Session cookie'yi sil
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Session'ı yok et
        session_destroy();
        
        // AJAX request ise JSON döndür (Frontend logout yapacak)
        $contentType = $request->getHeaderLine('Accept');
        if (strpos($contentType, 'application/json') !== false) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Şifre değiştirildi. Tekrar giriş yapmanız gerekiyor.',
                'require_logout' => true // Frontend bu flag'i görecek
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }
        
        // Normal request ise login'e yönlendir
        return $response
            ->withHeader('Location', '/login?message=password_changed')
            ->withStatus(302);
    }


    /**
     * API Key oluştur
     */
    public function generateApiKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/settings/security')
                ->withStatus(302);
        }

        // Yeni API key oluştur
        $user->generateApiKey();
        $this->em->flush();

        $this->auditLogger->log(
            $userId,
            'user.api_key_generated',
            'User',
            $user->getId(),
            ['action' => 'API key created']
        );

        $_SESSION['flash_success'] = 'API Key başarıyla oluşturuldu';
        
        return $response
            ->withHeader('Location', '/settings/security')
            ->withStatus(302);
    }

    /**
     * API Key iptal et
     */
    public function revokeApiKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            $_SESSION['flash_error'] = 'Kullanıcı bulunamadı';
            return $response
                ->withHeader('Location', '/settings/security')
                ->withStatus(302);
        }

        // API key'i iptal et
        $user->revokeApiKey();
        $this->em->flush();

        $this->auditLogger->log(
            $userId,
            'user.api_key_revoked',
            'User',
            $user->getId(),
            ['action' => 'API key revoked']
        );

        $_SESSION['flash_success'] = 'API Key iptal edildi';
        
        return $response
            ->withHeader('Location', '/settings/security')
            ->withStatus(302);
    }

    /**
     * Bakiye hareketlerini getir (API endpoint)
     */
    public function getBalanceHistory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $userId = $request->getAttribute('user_id') ?? $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
            
            if (!$userId) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Oturum bulunamadı'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
            }
            
            $user = $this->em->find(User::class, $userId);

            if (!$user) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Kullanıcı bulunamadı'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }

            // Email Bakiyesi ve hareketleri
            $emailBalance = $user->getEmailCredit();
            $emailTransactions = $this->em->createQueryBuilder()
                ->select('e')
                ->from(EmailTransaction::class, 'e')
                ->where('e.user = :user')
                ->setParameter('user', $user)
                ->orderBy('e.createdAt', 'DESC')
                ->setMaxResults(50)
                ->getQuery()
                ->getResult();

            // Email transaction'ları formatlayalım
            $emailData = array_map(function($t) {
                $type = $t->getType();
                $typeLabels = [
                    'credit' => 'Bakiye Ekleme',
                    'debit' => 'Sipariş Kesinti',
                    'refund' => 'İade',
                ];
                $typeLabel = $typeLabels[$type->value] ?? ucfirst($type->value);
                
                // Debit (kesinti) ise negatif yap
                $amount = $t->getAmount();
                if ($type->value === 'debit') {
                    $amount = -abs($amount);
                }
                
                return [
                    'type' => $typeLabel,
                    'amount' => $amount,
                    'description' => $t->getDescription() ?: $typeLabel,
                    'balance_after' => $t->getBalanceAfter(),
                    'created_at' => $t->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }, $emailTransactions);

            $responseData = [
                'success' => true,
                'email_balance' => $emailBalance,
                'email_transactions' => $emailData,
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log('Balance history error: ' . $e->getMessage());
            error_log('Trace: ' . $e->getTraceAsString());
            
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Bakiye hareketleri yüklenirken hata oluştu: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

}

