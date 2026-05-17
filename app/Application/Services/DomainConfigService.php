<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\DomainSetting;
use Doctrine\ORM\EntityManager;

class DomainConfigService
{
    private string $domainsDir;
    private EntityManager $em;
    private bool $useDatabase = true; // Veritabanı modu (default: true)

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
        $this->domainsDir = __DIR__ . '/../../../config/domains';
    }

    /**
     * Tüm domain'leri listele (veritabanından)
     */
    public function getAllDomains(): array
    {
        if ($this->useDatabase) {
            try {
                $domainSettings = $this->em->getRepository(DomainSetting::class)->findAll();
                
                $domains = [];
                foreach ($domainSettings as $setting) {
                    $domains[] = [
                        'id' => $setting->getId(),
                        'domain' => $setting->getDomain(),
                        'config' => $setting->toArray(),
                        'is_active' => $setting->isActive(),
                        'created_at' => $setting->getCreatedAt(),
                        'updated_at' => $setting->getUpdatedAt(),
                    ];
                }
                
                return $domains;
            } catch (\Exception $e) {
                error_log("Domain settings veritabanı hatası, dosya sistemine geçiliyor: " . $e->getMessage());
                // Fallback: dosya sistemini kullan
            }
        }
        
        // Fallback: Config dosyalarından oku (eski yöntem)
        $domains = [];
        $files = glob($this->domainsDir . '/*.php');
        
        foreach ($files as $file) {
            $domain = basename($file, '.php');
            // example.com gibi dosyaları atla
            if ($domain === 'example.com' || $domain === '.gitkeep') {
                continue;
            }
            
            try {
                $config = require $file;
                $domains[] = [
                    'domain' => $domain,
                    'config' => $config,
                    'file' => $file,
                    'is_active' => true,
                ];
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return $domains;
    }

    /**
     * Belirli bir domain config'ini getir (veritabanından)
     */
    public function getDomainConfig(string $domain): ?array
    {
        if ($this->useDatabase) {
            try {
                $setting = $this->em->getRepository(DomainSetting::class)
                    ->findOneBy(['domain' => $domain, 'isActive' => true]);
                
                return $setting ? $setting->toArray() : null;
            } catch (\Exception $e) {
                error_log("Domain config DB hatası ($domain): " . $e->getMessage());
                // Fallback: dosya sistemini kullan
            }
        }
        
        // Fallback: Config dosyasından oku
        $file = $this->domainsDir . '/' . $domain . '.php';
        
        if (!file_exists($file)) {
            return null;
        }
        
        try {
            $config = require $file;
            return is_array($config) ? $config : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Domain config kaydet (veritabanına)
     */
    public function saveDomainConfig(string $domain, array $config): bool
    {
        // Domain adını temizle (güvenlik için)
        $domain = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $domain);
        
        if (empty($domain)) {
            return false;
        }
        
        if ($this->useDatabase) {
            try {
                // Mevcut kayıt var mı?
                $setting = $this->em->getRepository(DomainSetting::class)
                    ->findOneBy(['domain' => $domain]);
                
                if ($setting) {
                    // Güncelle
                    $setting->setSiteTitle($config['site_title'] ?? '');
                    $setting->setSiteLogo($config['site_logo'] ?? null);
                    $setting->setSiteFavicon($config['site_favicon'] ?? null);
                    $setting->setSiteDefaultAvatar($config['site_default_avatar'] ?? null);
                    $setting->setSiteDescription($config['site_description'] ?? null);
                } else {
                    // Yeni oluştur
                    $setting = new DomainSetting();
                    $setting->setDomain($domain);
                    $setting->setSiteTitle($config['site_title'] ?? '');
                    $setting->setSiteLogo($config['site_logo'] ?? null);
                    $setting->setSiteFavicon($config['site_favicon'] ?? null);
                    $setting->setSiteDefaultAvatar($config['site_default_avatar'] ?? null);
                    $setting->setSiteDescription($config['site_description'] ?? null);
                    $setting->setIsActive(true);
                    
                    $this->em->persist($setting);
                }
                
                $this->em->flush();
                return true;
            } catch (\Exception $e) {
                error_log("Domain config DB kaydetme hatası: " . $e->getMessage());
                return false;
            }
        }
        
        // Fallback: Dosya sistemine kaydet (eski yöntem)
        $file = $this->domainsDir . '/' . $domain . '.php';
        
        $content = "<?php\n\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "/**\n";
        $content .= " * Domain: {$domain}\n";
        $content .= " * Site ayarları\n";
        $content .= " */\n\n";
        $content .= "return [\n";
        $content .= "    'site_title' => " . var_export($config['site_title'] ?? '', true) . ",\n";
        $content .= "    'site_logo' => " . var_export($config['site_logo'] ?? '', true) . ",\n";
        $content .= "    'site_favicon' => " . var_export($config['site_favicon'] ?? '', true) . ",\n";
        $content .= "    'site_default_avatar' => " . var_export($config['site_default_avatar'] ?? '', true) . ",\n";
        $content .= "    'site_description' => " . var_export($config['site_description'] ?? '', true) . ",\n";
        $content .= "];\n\n";
        
        try {
            if (!is_dir($this->domainsDir)) {
                mkdir($this->domainsDir, 0755, true);
            }
            
            file_put_contents($file, $content);
            return true;
        } catch (\Exception $e) {
            error_log("Domain config kaydetme hatası: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Domain config sil (veritabanından)
     */
    public function deleteDomainConfig(string $domain): bool
    {
        $domain = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $domain);
        
        if (empty($domain) || $domain === 'example.com') {
            return false;
        }
        
        if ($this->useDatabase) {
            try {
                $setting = $this->em->getRepository(DomainSetting::class)
                    ->findOneBy(['domain' => $domain]);
                
                if ($setting) {
                    $this->em->remove($setting);
                    $this->em->flush();
                    return true;
                }
                return false;
            } catch (\Exception $e) {
                error_log("Domain config DB silme hatası: " . $e->getMessage());
                return false;
            }
        }
        
        // Fallback: Dosyadan sil
        $file = $this->domainsDir . '/' . $domain . '.php';
        
        if (!file_exists($file)) {
            return false;
        }
        
        try {
            unlink($file);
            return true;
        } catch (\Exception $e) {
            error_log("Domain config silme hatası: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Domain var mı kontrol et (veritabanında)
     */
    public function domainExists(string $domain): bool
    {
        $domain = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $domain);
        
        if ($this->useDatabase) {
            try {
                $count = $this->em->createQueryBuilder()
                    ->select('COUNT(d.id)')
                    ->from(DomainSetting::class, 'd')
                    ->where('d.domain = :domain')
                    ->setParameter('domain', $domain)
                    ->getQuery()
                    ->getSingleScalarResult();
                
                return $count > 0;
            } catch (\Exception $e) {
                // Fallback
            }
        }
        
        // Fallback: Dosya kontrolü
        $file = $this->domainsDir . '/' . $domain . '.php';
        return file_exists($file);
    }
}

