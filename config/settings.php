<?php

declare(strict_types=1);

// .env dosyasını yükle (eğer yüklenmemişse)
if (!isset($_ENV['APP_ENV']) && file_exists(__DIR__ . '/../.env')) {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->load();
    }
}

// Debug log helper: sadece APP_DEBUG=true iken yazar.
// Üretimde error_log spam'ini önlemek için tüm domain/twig debug loglari bu fonksiyondan geçer.
if (!function_exists('nexus_dbg')) {
    function nexus_dbg(string $message): void {
        $debug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($debug) {
            error_log($message);
        }
    }
}

/**
 * Domain bazlı site ayarlarını yükle
 * Her domain için config/domains/{domain}.php dosyası oluşturun
 */
function loadDomainConfig(): array {
    // Mevcut domain'i algıla - farklı yöntemler dene
    $host = null;
    
    // Cloudflare/Proxy arkasındaysa X-Forwarded-Host kullan (öncelikli)
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
    }
    // Önce HTTP_HOST'u dene
    elseif (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $host = $_SERVER['HTTP_HOST'];
    }
    // Sonra SERVER_NAME'i dene
    elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
        $host = $_SERVER['SERVER_NAME'];
    }
    // Son çare olarak REQUEST_URI'den çıkar
    elseif (isset($_SERVER['REQUEST_URI']) && !empty($_SERVER['REQUEST_URI'])) {
        // Bu durumda localhost olabilir, atla
        $host = 'localhost';
    }
    
    nexus_dbg("DEBUG DOMAIN DETECTION: X-Forwarded-Host = " . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'NULL') .
              ", HTTP_HOST = " . ($_SERVER['HTTP_HOST'] ?? 'NULL') .
              ", SERVER_NAME = " . ($_SERVER['SERVER_NAME'] ?? 'NULL') .
              ", REQUEST_URI = " . ($_SERVER['REQUEST_URI'] ?? 'NULL') .
              ", Detected host = " . ($host ?? 'NULL'));
    
    // Host bulunamadıysa boş döndür
    if (empty($host) || $host === 'localhost') {
        return [];
    }
    
    // Port'u temizle (örn: example.com:8080 -> example.com)
    $host = preg_replace('/:\d+$/', '', $host);
    
    // www. prefix'ini kaldır (veritabanı ile uyumlu olması için)
    $host = preg_replace('/^www\./', '', $host);
    
    // Domain config dosyası yolu
    $domainConfigFile = __DIR__ . '/domains/' . $host . '.php';
    
    // Domain config dosyası varsa yükle
    if (file_exists($domainConfigFile)) {
        try {
            $domainConfig = require $domainConfigFile;
            $result = is_array($domainConfig) ? $domainConfig : [];
            nexus_dbg("Domain config yüklendi: {$host} -> " . ($result['site_title'] ?? 'N/A'));
            return $result;
        } catch (\Exception $e) {
            // Yükleme hatası gerçek bir sorundur; her zaman logla.
            error_log("Domain config yükleme hatası ({$host}): " . $e->getMessage());
            return [];
        }
    }

    nexus_dbg("Domain config bulunamadı: {$host} -> Dosya: {$domainConfigFile}");
    return [];
}

// Domain config'ini yükle (lazy loading - sadece gerektiğinde)
$domainConfig = [];
$currentDomain = null;
if (php_sapi_name() !== 'cli') {
    // CLI'da değilsek (web isteği ise) domain config'i yükle
    
    // Önce domain'i algıla - HER ZAMAN DEBUG LOG
    // Öncelik sırası: X-Forwarded-Host > HTTP_HOST > SERVER_NAME > HTTPS header > REQUEST_URI
    $detectedHost = null;
    $detectedFrom = null;
    
    // 1. Cloudflare/Proxy header'ı (en güvenilir)
    if (isset($_SERVER['HTTP_X_FORWARDED_HOST']) && !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $detectedHost = $_SERVER['HTTP_X_FORWARDED_HOST'];
        $detectedFrom = 'HTTP_X_FORWARDED_HOST';
    }
    // 2. HTTP_HOST (Apache UseCanonicalName Off olmalı)
    // CLOUDFLARE FIX: Eğer Cloudflare kullanılıyorsa ve HTTP_HOST yanlışsa, SERVER_NAME'i kullan
    $isCloudflare = isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_VISITOR']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);
    
    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        if ($_SERVER['HTTP_HOST'] === 'hub-nexus.com' && isset($_SERVER['SERVER_NAME']) && $_SERVER['SERVER_NAME'] !== 'hub-nexus.com') {
            $detectedHost = $_SERVER['SERVER_NAME'];
            $detectedFrom = 'SERVER_NAME (HTTP_HOST fix)';
            nexus_dbg("🔧 HTTP_HOST FIX: HTTP_HOST hub-nexus.com ama SERVER_NAME farklı, SERVER_NAME kullanılıyor: " . $_SERVER['SERVER_NAME']);
        } else {
            $detectedHost = $_SERVER['HTTP_HOST'];
            $detectedFrom = 'HTTP_HOST';
        }
    }
    elseif (isset($_SERVER['SERVER_NAME']) && !empty($_SERVER['SERVER_NAME'])) {
        $detectedHost = $_SERVER['SERVER_NAME'];
        $detectedFrom = 'SERVER_NAME';
        nexus_dbg("🔧 SERVER_NAME kullanılıyor (HTTP_HOST yok): " . $_SERVER['SERVER_NAME']);
    }
    // 4. HTTPS header'dan (Cloudflare özel header)
    elseif (isset($_SERVER['HTTP_CF_VISITOR']) && !empty($_SERVER['HTTP_CF_VISITOR'])) {
        $cfVisitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
        if (isset($cfVisitor['scheme']) && $cfVisitor['scheme'] === 'https') {
            // Cloudflare kullanıyoruz ama domain'i başka yerden almalıyız
        }
    }
    // 5. REQUEST_URI'den domain çıkarmayı dene (son çare)
    if (!$detectedHost && isset($_SERVER['REQUEST_URI'])) {
        // Host header'ı yoksa, REQUEST_URI'den çıkaramayız
        // Ama HTTPS ise, SNI (Server Name Indication) kullanılabilir
    }
    
    nexus_dbg(sprintf(
        "🔍 DOMAIN DETECTION | From=%s | Host=%s | X-Forwarded-Host=%s | HTTP_HOST=%s | SERVER_NAME=%s | CF-Visitor=%s | REQUEST_URI=%s",
        $detectedFrom ?? 'NULL',
        $detectedHost ?? 'NULL',
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'NULL',
        $_SERVER['HTTP_HOST'] ?? 'NULL',
        $_SERVER['SERVER_NAME'] ?? 'NULL',
        $_SERVER['HTTP_CF_VISITOR'] ?? 'NULL',
        substr($_SERVER['REQUEST_URI'] ?? 'NULL', 0, 100)
    ));

    $isCloudflare = isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_VISITOR']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);
    if ($isCloudflare) {
        nexus_dbg("⚠️  CLOUDFLARE DETECTED! CF-RAY=" . ($_SERVER['HTTP_CF_RAY'] ?? 'NULL') . ", CF-Visitor=" . ($_SERVER['HTTP_CF_VISITOR'] ?? 'NULL'));
        nexus_dbg("⚠️  HTTP_HOST değiştirilmiş olabilir! Cloudflare'de 'Preserve Host Header' açık olmalı!");

        if (isset($_SERVER['HTTP_CF_VISITOR']) && !$detectedHost) {
            $cfVisitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            nexus_dbg("🔍 CF-Visitor JSON: " . json_encode($cfVisitor));
        }
    }
    
    $currentDomain = $detectedHost;
    
    // Port ve www. prefix'ini temizle
    if ($currentDomain) {
        $originalDomain = $currentDomain; // Debug için sakla
        $currentDomain = preg_replace('/:\d+$/', '', $currentDomain); // Port temizle
        $currentDomain = preg_replace('/^www\./', '', $currentDomain); // www. temizle
        
        if ($originalDomain !== $currentDomain) {
            nexus_dbg("🔧 DOMAIN CLEANUP: '$originalDomain' -> '$currentDomain'");
        }

        nexus_dbg("✅ FINAL DOMAIN: '$currentDomain'");
    } else {
        // Tespit edilemedi: gerçek bir konfig sorunu, her zaman logla.
        nexus_dbg("❌ NO DOMAIN DETECTED!");
    }
    
    // 1. Önce veritabanından dene (PDO ile direkt) - localhost dahil (domain-settings'ten eklenen logolar için)
    // localhost'ta DEFAULT_DOMAIN env ile belirli bir domain config'i kullanılabilir (geliştirme için)
    $domainToFetch = $currentDomain;
    if (empty($domainToFetch) || $domainToFetch === 'localhost' || $domainToFetch === '127.0.0.1') {
        $defaultDomain = trim($_ENV['DEFAULT_DOMAIN'] ?? '');
        if (!empty($defaultDomain)) {
            $domainToFetch = preg_replace('/^www\./', '', $defaultDomain);
        }
    }
    
    if ($domainToFetch && !empty($domainToFetch)) {
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $_ENV['DB_HOST'] ?? 'localhost',
                    $_ENV['DB_PORT'] ?? '3306',
                    $_ENV['DB_NAME'] ?? 'nexus_db'
                ),
                $_ENV['DB_USER'] ?? 'root',
                $_ENV['DB_PASSWORD'] ?? '',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
            
            // Domain eşleşmesi için esnek arama: www. olmadan, www. ile, ve LIKE ile
            $searchDomains = [
                $domainToFetch,                    // numbpanel.com
                'www.' . $domainToFetch,          // www.numbpanel.com
            ];
            
            $row = null;
            $matchedDomain = null;
            
            foreach ($searchDomains as $searchDomain) {
                $stmt = $pdo->prepare('SELECT domain, siteTitle, siteLogo, siteFavicon, siteDefaultAvatar, siteDescription FROM domain_settings WHERE domain = ? AND isActive = 1 LIMIT 1');
                $stmt->execute([$searchDomain]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                
                if ($row) {
                    $matchedDomain = $row['domain'];
                    nexus_dbg("✅ Domain eşleşmesi bulundu: '$searchDomain' -> DB'deki domain: '{$matchedDomain}'");
                    break;
                }
            }

            if (!$row) {
                $stmt = $pdo->prepare('SELECT domain, siteTitle, siteLogo, siteFavicon, siteDefaultAvatar, siteDescription FROM domain_settings WHERE (domain = ? OR domain LIKE ?) AND isActive = 1 LIMIT 1');
                $stmt->execute([$domainToFetch, '%' . $domainToFetch]);
                $row = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($row) {
                    $matchedDomain = $row['domain'];
                    nexus_dbg("✅ Domain LIKE eşleşmesi bulundu: '$domainToFetch' -> DB'deki domain: '{$matchedDomain}'");
                }
            }
            
            if ($row) {
                $domainConfig = [
                    'site_title' => $row['siteTitle'] ?? null,
                    'site_logo' => $row['siteLogo'] ?? null,
                    'site_favicon' => $row['siteFavicon'] ?? null,
                    'site_default_avatar' => $row['siteDefaultAvatar'] ?? null,
                    'site_description' => $row['siteDescription'] ?? null,
                ];
                
                // Boş değerleri temizle
                foreach ($domainConfig as $key => $value) {
                    if (is_string($value) && trim($value) === '') {
                        $domainConfig[$key] = null;
                    }
                }
                
                // DB'de logo, title veya favicon boşsa config/domains/{domain}.php dosyasından doldur
                $fileConfig = loadDomainConfig();
                if (empty($domainConfig['site_logo'])) {
                    $domainConfig['site_logo'] = $fileConfig['site_logo'] ?? null;
                }
                if (empty($domainConfig['site_title'])) {
                    $domainConfig['site_title'] = $fileConfig['site_title'] ?? null;
                }
                if (empty($domainConfig['site_favicon'])) {
                    $domainConfig['site_favicon'] = $fileConfig['site_favicon'] ?? null;
                }

                nexus_dbg("✅ Domain config (DB): $domainToFetch -> Matched: '{$matchedDomain}' -> Title: " . ($domainConfig['site_title'] ?? 'N/A') . ", Logo: " . ($domainConfig['site_logo'] ?? 'N/A'));
            } else {
                nexus_dbg("❌ Domain config (DB): $domainToFetch -> Bulunamadı (tüm varyasyonlar denenmiş), dosya sistemine geçiliyor");
            }
        } catch (\Exception $e) {
            nexus_dbg("Domain config DB hatası, dosya sistemine geçiliyor: " . $e->getMessage());
        }
    }
    
    // 2. Veritabanında yoksa dosya sisteminden yükle (fallback)
    if (empty($domainConfig)) {
        $domainConfig = loadDomainConfig();
    }
}

return [
    'settings' => [
        'app' => [
            'name' => 'Nexus Mail',
            'env' => $_ENV['APP_ENV'] ?? 'production',
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'url' => $_ENV['APP_URL'] ?? 'http://localhost',
            'timezone' => $_ENV['APP_TIMEZONE'] ?? 'UTC',
            'locale' => $_ENV['DEFAULT_LOCALE'] ?? 'tr',
        ],
        'api' => [
            'token' => $_ENV['API_TOKEN'] ?? 'nexus_secure_api_token_2025_' . md5('nexus'),
        ],
        'external_api' => [
            'base_url' => $_ENV['EXTERNAL_API_BASE_URL'] ?? 'https://hub-nexus.com',
            'key' => $_ENV['EXTERNAL_API_KEY'] ?? '',
            'key_name' => $_ENV['EXTERNAL_API_KEY_NAME'] ?? 'partner_site',
            'rate_limit' => (int) ($_ENV['EXTERNAL_API_RATE_LIMIT'] ?? 120),
            'rate_window' => (int) ($_ENV['EXTERNAL_API_RATE_WINDOW'] ?? 60),
            'timeout_seconds' => 10,
        ],
        'database' => [
            'driver' => $_ENV['DB_DRIVER'] ?? 'pdo_mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => (int)($_ENV['DB_PORT'] ?? 3306),
            'dbname' => $_ENV['DB_NAME'] ?? 'new',
            'user' => $_ENV['DB_USER'] ?? 'root',
            // Boş string kontrolü: Eğer DB_PASSWORD boş string ise, null kullan (şifre yok demektir)
            'password' => (!empty($_ENV['DB_PASSWORD']) && trim($_ENV['DB_PASSWORD']) !== '') 
                ? trim($_ENV['DB_PASSWORD']) 
                : (isset($_ENV['DB_PASSWORD']) && $_ENV['DB_PASSWORD'] === '0' ? '0' : ''),
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
        ],
        'redis' => [
            'host' => $_ENV['REDIS_HOST'] ?? '127.0.0.1',
            'port' => (int)($_ENV['REDIS_PORT'] ?? 6379),
            'password' => $_ENV['REDIS_PASSWORD'] ?? null,
            'database' => (int)($_ENV['REDIS_DB'] ?? 0),
        ],
        'messenger' => [
            'transport_dsn' => $_ENV['MESSENGER_TRANSPORT_DSN'] ?? 'redis://127.0.0.1:6379/messages',
        ],
        'session' => [
            'name' => 'NEXUS_SESSION',
            'lifetime' => 7200,
            'path' => '/',
            'domain' => '',
            'secure' => filter_var($_ENV['SESSION_SECURE'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'httponly' => filter_var($_ENV['SESSION_HTTPONLY'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'samesite' => $_ENV['SESSION_SAMESITE'] ?? 'Lax',
        ],
        'mail' => [
            'provider' => 'disabled',
            'unit_price' => 0.0,
            'default_country' => $_ENV['MAIL_DEFAULT_COUNTRY'] ?? 'TR',
            'message_max_length' => 1000,
        ],
        'upload' => [
            'max_size' => (int)($_ENV['MAX_UPLOAD_SIZE'] ?? 26214400), // 25MB
            'allowed_extensions' => explode(',', $_ENV['ALLOWED_EXTENSIONS'] ?? 'csv,xls,xlsx,txt'),
            'path' => __DIR__ . '/../storage/uploads',
        ],
        'rate_limit' => [
            'order' => (int)($_ENV['RATE_LIMIT_ORDER'] ?? 10),
            'import' => (int)($_ENV['RATE_LIMIT_IMPORT'] ?? 5),
            'window' => (int)($_ENV['RATE_LIMIT_WINDOW'] ?? 60),
        ],
        'logging' => [
            'level' => $_ENV['LOG_LEVEL'] ?? 'error', // Sadece kritik hatalar (yerelde minimal log)
            'path' => $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs/app.log',
        ],
        'twig' => [
            'paths' => [
                __DIR__ . '/../templates',
            ],
            'cache' => $_ENV['APP_ENV'] === 'production' 
                ? __DIR__ . '/../var/cache/twig' 
                : false,
            'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // Domain config değiştiğinde cache'i otomatik yenile (production'da bile)
            'auto_reload' => true,
            'globals' => [
                // Domain config varsa onu kullan, yoksa .env'den, yoksa default değer
                // NOT: $domainConfig değişkeni yukarıda loadDomainConfig() ile yükleniyor
                'site_title' => (function() use ($domainConfig) {
                    $value = (isset($domainConfig['site_title']) && !empty(trim($domainConfig['site_title'])))
                        ? trim($domainConfig['site_title'])
                        : (isset($_ENV['SITE_TITLE']) && !empty(trim($_ENV['SITE_TITLE']))
                            ? trim($_ENV['SITE_TITLE'])
                            : 'Nexus Panel');
                    nexus_dbg("🔍 TWIG GLOBAL site_title: " . ($domainConfig['site_title'] ?? 'NULL') . " -> " . $value);
                    return $value;
                })(),
                'site_logo' => (function() use ($domainConfig) {
                    $value = (isset($domainConfig['site_logo']) && !empty(trim($domainConfig['site_logo'])))
                        ? trim($domainConfig['site_logo'])
                        : (isset($_ENV['SITE_LOGO']) && !empty(trim($_ENV['SITE_LOGO']))
                            ? trim($_ENV['SITE_LOGO'])
                            : '/assets/images/default-avatar.png');
                    nexus_dbg("🔍 TWIG GLOBAL site_logo: " . ($domainConfig['site_logo'] ?? 'NULL') . " -> " . $value);
                    return $value;
                })(),
                'site_favicon' => (function() use ($domainConfig) {
                    $value = (isset($domainConfig['site_favicon']) && !empty(trim($domainConfig['site_favicon'])))
                        ? trim($domainConfig['site_favicon'])
                        : (isset($_ENV['SITE_FAVICON']) && !empty(trim($_ENV['SITE_FAVICON']))
                            ? trim($_ENV['SITE_FAVICON'])
                            : '/assets/images/default-avatar.png');
                    nexus_dbg("🔍 TWIG GLOBAL site_favicon: " . ($domainConfig['site_favicon'] ?? 'NULL') . " -> " . $value);
                    return $value;
                })(),
                'site_default_avatar' => (isset($domainConfig['site_default_avatar']) && !empty(trim($domainConfig['site_default_avatar']))) 
                    ? trim($domainConfig['site_default_avatar']) 
                    : (isset($_ENV['SITE_DEFAULT_AVATAR']) && !empty(trim($_ENV['SITE_DEFAULT_AVATAR'])) 
                        ? trim($_ENV['SITE_DEFAULT_AVATAR']) 
                        : '/assets/images/default-avatar.png'),
                'site_description' => (isset($domainConfig['site_description']) && !empty(trim($domainConfig['site_description']))) 
                    ? trim($domainConfig['site_description']) 
                    : (isset($_ENV['SITE_DESCRIPTION']) && !empty(trim($_ENV['SITE_DESCRIPTION'])) 
                        ? trim($_ENV['SITE_DESCRIPTION']) 
                        : 'Nexus Mail - Mail gönderim ve yönetim platformu'),
            ],
        ],
    ],
];

