<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\DomainConfigService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class DomainSettingsController
{
    public function __construct(
        private Environment $twig,
        private DomainConfigService $domainConfigService
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $domains = $this->domainConfigService->getAllDomains();
        
        $html = $this->twig->render('admin/domain-settings/index.twig', [
            'domains' => $domains,
        ]);

        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        $domain = trim($data['domain'] ?? '');
        $siteTitle = trim($data['site_title'] ?? '');
        $siteLogo = trim($data['site_logo'] ?? '');
        $siteFavicon = trim($data['site_favicon'] ?? '');
        $siteDefaultAvatar = trim($data['site_default_avatar'] ?? '');
        $siteDescription = trim($data['site_description'] ?? '');
        
        // Validasyon
        if (empty($domain)) {
            $_SESSION['flash_error'] = 'Domain adı gereklidir.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
        
        // Domain formatını kontrol et
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*\.[a-zA-Z]{2,}$/', $domain)) {
            $_SESSION['flash_error'] = 'Geçersiz domain formatı.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
        
        // Domain zaten varsa
        if ($this->domainConfigService->domainExists($domain)) {
            $_SESSION['flash_error'] = 'Bu domain zaten mevcut.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
        
        $config = [
            'site_title' => $siteTitle,
            'site_logo' => $siteLogo,
            'site_favicon' => $siteFavicon,
            'site_default_avatar' => $siteDefaultAvatar,
            'site_description' => $siteDescription,
        ];
        
        if ($this->domainConfigService->saveDomainConfig($domain, $config)) {
            // Domain config değişti, Twig cache'i temizle
            $this->clearTwigCache();
            $_SESSION['flash_success'] = 'Domain ayarları başarıyla kaydedildi.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        } else {
            $_SESSION['flash_error'] = 'Domain ayarları kaydedilirken bir hata oluştu.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
    }

    public function getConfig(Request $request, Response $response, array $args): Response
    {
        $domain = $args['domain'] ?? '';
        
        if (empty($domain)) {
            $response->getBody()->write(json_encode(['error' => 'Domain bulunamadı.']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }
        
        $config = $this->domainConfigService->getDomainConfig($domain);
        
        if ($config === null) {
            $response->getBody()->write(json_encode(['error' => 'Domain ayarları bulunamadı.']));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(404);
        }
        
        $response->getBody()->write(json_encode([
            'domain' => $domain,
            'config' => $config,
        ]));
        
        return $response
            ->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $domain = $args['domain'] ?? '';
        $data = $request->getParsedBody();
        
        if (empty($domain)) {
            $_SESSION['flash_error'] = 'Domain bulunamadı.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
        
        $siteTitle = trim($data['site_title'] ?? '');
        $siteLogo = trim($data['site_logo'] ?? '');
        $siteFavicon = trim($data['site_favicon'] ?? '');
        $siteDefaultAvatar = trim($data['site_default_avatar'] ?? '');
        $siteDescription = trim($data['site_description'] ?? '');
        
        $config = [
            'site_title' => $siteTitle,
            'site_logo' => $siteLogo,
            'site_favicon' => $siteFavicon,
            'site_default_avatar' => $siteDefaultAvatar,
            'site_description' => $siteDescription,
        ];
        
        if ($this->domainConfigService->saveDomainConfig($domain, $config)) {
            // Domain config değişti, Twig cache'i temizle
            $this->clearTwigCache();
            $_SESSION['flash_success'] = 'Domain ayarları başarıyla güncellendi.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        } else {
            $_SESSION['flash_error'] = 'Domain ayarları güncellenirken bir hata oluştu.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
    }

    public function destroy(Request $request, Response $response, array $args): Response
    {
        $domain = $args['domain'] ?? '';
        
        if (empty($domain)) {
            $_SESSION['flash_error'] = 'Domain bulunamadı.';
            return $response
                ->withHeader('Location', '/admin/domain-settings')
                ->withStatus(302);
        }
        
        if ($this->domainConfigService->deleteDomainConfig($domain)) {
            // Domain config silindi, Twig cache'i temizle
            $this->clearTwigCache();
            $_SESSION['flash_success'] = 'Domain ayarları başarıyla silindi.';
        } else {
            $_SESSION['flash_error'] = 'Domain ayarları silinirken bir hata oluştu.';
        }
        
        return $response
            ->withHeader('Location', '/admin/domain-settings')
            ->withStatus(302);
    }

    /**
     * Twig cache'i temizle (domain config değiştiğinde)
     */
    private function clearTwigCache(): void
    {
        $twigCacheDir = __DIR__ . '/../../../var/cache/twig';
        
        if (!is_dir($twigCacheDir)) {
            return;
        }
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($twigCacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($files as $fileinfo) {
                if ($fileinfo->isFile()) {
                    @unlink($fileinfo->getRealPath());
                } elseif ($fileinfo->isDir()) {
                    @rmdir($fileinfo->getRealPath());
                }
            }
            
            error_log("✅ Twig cache temizlendi (domain config değişti)");
        } catch (\Exception $e) {
            error_log("⚠️ Twig cache temizleme hatası: " . $e->getMessage());
        }
    }
}

