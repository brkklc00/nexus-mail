<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Application\Services\DomainConfigService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Twig\Environment;

class DomainConfigMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Environment $twig,
        private DomainConfigService $domainConfigService
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Domain'i tespit et
        $host = null;
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        }
        
        // Port numarasını kaldır
        if ($host) {
            $host = preg_replace('/:\d+$/', '', $host);
            // www. prefix'ini kaldır
            $host = preg_replace('/^www\./', '', $host);
        }
        
        // Localhost kontrolü
        $isLocalhost = !$host || in_array(strtolower($host), ['localhost', '127.0.0.1', '::1']) 
            || preg_match('/^192\.168\./', $host) 
            || preg_match('/^10\./', $host) 
            || preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $host);
        
        // Domain config'i yükle
        $domainConfig = null;
        if (!$isLocalhost && $host) {
            $domainConfig = $this->domainConfigService->getDomainConfig($host);
        }
        
        // Localhost'ta DEFAULT_DOMAIN env ile belirli bir domain config'i kullanılabilir
        if ($isLocalhost && empty($domainConfig)) {
            $defaultDomain = trim($_ENV['DEFAULT_DOMAIN'] ?? '');
            if (!empty($defaultDomain)) {
                $defaultDomain = preg_replace('/^www\./', '', $defaultDomain);
                $domainConfig = $this->domainConfigService->getDomainConfig($defaultDomain);
            }
        }
        
        // Twig globals'a domain config'i set et (her request'te yenilenir)
        if ($domainConfig) {
            $this->twig->addGlobal('site_title', $domainConfig['site_title'] ?? 'Nexus Panel');
            $this->twig->addGlobal('site_logo', $domainConfig['site_logo'] ?? '/assets/images/nexus-logo.png');
            $this->twig->addGlobal('site_favicon', $domainConfig['site_favicon'] ?? '/assets/images/default-avatar.png');
            $this->twig->addGlobal('site_default_avatar', $domainConfig['site_default_avatar'] ?? '/assets/images/default-avatar.png');
            $this->twig->addGlobal('site_description', $domainConfig['site_description'] ?? '');
        } else {
            // Default değerler
            $this->twig->addGlobal('site_title', $_ENV['SITE_TITLE'] ?? 'Nexus Panel');
            $this->twig->addGlobal('site_logo', $_ENV['SITE_LOGO'] ?? '/assets/images/nexus-logo.png');
            $this->twig->addGlobal('site_favicon', $_ENV['SITE_FAVICON'] ?? '/assets/images/default-avatar.png');
            $this->twig->addGlobal('site_default_avatar', $_ENV['SITE_DEFAULT_AVATAR'] ?? '/assets/images/default-avatar.png');
            $this->twig->addGlobal('site_description', '');
        }

        /** @see templates/_layouts/main.twig — false: mobil drawer (overlay + nx-mobile-nav.js) kapalı; nexus-theme.css her zaman yüklenir */
        $this->twig->addGlobal('use_nexus_theme', $this->resolveUseNexusTheme());

        return $handler->handle($request);
    }

    /**
     * Mobil drawer / gelişmiş menü JS (nx-mobile-nav.js, overlay, X).
     * false: Dastone hamburger (enlarge-menu) kalır; nexus-theme.css kapatılmaz (şablonlar bozulmasın).
     * .env: USE_NEXUS_THEME=false veya ENABLE_NEW_UI=false
     */
    private function resolveUseNexusTheme(): bool
    {
        $raw = $this->readEnvValue('USE_NEXUS_THEME') ?? $this->readEnvValue('ENABLE_NEW_UI');
        if ($raw === null || $raw === '') {
            return true;
        }
        if (is_string($raw)) {
            $s = strtolower(trim($raw));
            if ($s === 'legacy' || $s === '0' || $s === 'off' || $s === 'no') {
                return false;
            }
        }
        $parsed = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $parsed ?? true;
    }

    private function readEnvValue(string $key): ?string
    {
        if (array_key_exists($key, $_ENV)) {
            $v = $_ENV[$key];
            if ($v === null || $v === '') {
                return null;
            }

            return is_scalar($v) ? (string) $v : null;
        }
        $g = getenv($key);
        if ($g === false || $g === '') {
            return null;
        }

        return (string) $g;
    }
}
