<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Service\MoreUrlShortenerClient;
use App\Domain\Entities\ShortenedUrl;
use App\Domain\Entities\User;
use Doctrine\ORM\EntityManager;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class UrlShortenerController
{
    private EntityManager $em;
    private Environment $twig;
    private MoreUrlShortenerClient $moreUrlClient;
    private array $availableDomains = [
        'shrt-link.com',
        'clicky.cx',
        'shrtlink.io'
    ];
    
    /**
     * Domain'e göre API endpoint'i döndürür
     */
    private function getApiEndpoint(string $domain): string
    {
        // Her domain kendi API endpoint'ini kullanır
        return "https://{$domain}/api/url";
    }
    
    /**
     * Domain'e göre API key döndürür
     */
    private function getApiKey(string $domain): string
    {
        $apiKeys = [
            'shrt-link.com' => 'KtnPwLEBLnJwQrkIdLLxsVmpyQsIEoLS',
            'clicky.cx' => '9176a02c98ffc5317d3f6d25937f8b28',
            'shrtlink.io' => 'f8d623eba773639e1a1564e147990ec1',
        ];
        
        return $apiKeys[$domain] ?? $apiKeys['shrt-link.com'];
    }

    public function __construct(EntityManager $em, Environment $twig, array $settings, MoreUrlShortenerClient $moreUrlClient)
    {
        $this->em = $em;
        $this->twig = $twig;
        $this->moreUrlClient = $moreUrlClient;
    }

    public function index(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            return $response->withStatus(403);
        }

        // Pagination
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        // Kullanıcının URL'lerini getir
        $qb = $this->em->createQueryBuilder();
        $total = (int) $qb->select('COUNT(u.id)')
            ->from(ShortenedUrl::class, 'u')
            ->where('u.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        $urls = $this->em->createQueryBuilder()
            ->select('u')
            ->from(ShortenedUrl::class, 'u')
            ->where('u.user = :user')
            ->setParameter('user', $user)
            ->orderBy('u.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $totalPages = (int) ceil($total / $perPage);

        // API'den tıklama bilgilerini güncelle
        $this->updateClickCountsFromApi($urls);

        // Stats
        $stats = $this->em->createQueryBuilder()
            ->select('COUNT(u.id) as total_urls', 'SUM(u.clickCount) as total_clicks')
            ->from(ShortenedUrl::class, 'u')
            ->where('u.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleResult();

        $moreUrlLinks = [];
        $moreUrlDomains = [];
        $moreUrlError = null;
        try {
            $morePage = 1;
            $morePerPage = 100;
            $moreMaxPages = 10;
            $moreMerged = [];
            $moreLoadOk = true;

            while ($morePage <= $moreMaxPages) {
                $moreLinksResponse = $this->moreUrlClient->listLinks($morePage, $morePerPage, 'date');
                if (!$moreLinksResponse['ok']) {
                    if ($morePage === 1) {
                        $moreLoadOk = false;
                        $moreUrlError = $moreLinksResponse['message'] ?: 'MoreURL bağlantısı kurulamadı.';
                    }
                    break;
                }

                $batch = $moreLinksResponse['data']['urls'] ?? [];
                if (!empty($batch)) {
                    $moreMerged = array_merge($moreMerged, $batch);
                }

                if (count($batch) < $morePerPage) {
                    break;
                }
                $morePage++;
            }

            if ($moreLoadOk) {
                $moreUrlLinks = $moreMerged;
            }

            $domainResponse = $this->moreUrlClient->listDomains(1, 100);
            if ($domainResponse['ok']) {
                $moreUrlDomains = $domainResponse['data']['domains'] ?? [];
            } elseif ($moreUrlError === null) {
                $moreUrlError = $domainResponse['message'] ?: 'Domain listesi alınamadı.';
            }
        } catch (\Throwable $e) {
            $moreUrlError = 'MoreURL bağlantısı kurulamadı. API servisini kontrol edin.';
        }

        $html = $this->twig->render('url-shortener/index.twig', [
            'urls' => $urls,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'perPage' => $perPage,
            'stats' => $stats,
            'domains' => $this->availableDomains,
            'moreUrlLinks' => $moreUrlLinks,
            'moreUrlDomains' => $moreUrlDomains,
            'moreUrlError' => $moreUrlError,
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null,
            'moreUrlSuccess' => $_SESSION['more_url_success'] ?? null,
            'moreUrlErrorFlash' => $_SESSION['more_url_error'] ?? null,
            'moreUrlCreated' => $_SESSION['more_url_created'] ?? null,
        ]);

        unset($_SESSION['success'], $_SESSION['error'], $_SESSION['more_url_success'], $_SESSION['more_url_error'], $_SESSION['more_url_created']);

        $response->getBody()->write($html);
        return $response;
    }

    public function store(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $data = $request->getParsedBody();

        try {
            $originalUrl = trim($data['url'] ?? '');
            $title = trim($data['title'] ?? '');
            $customAlias = trim($data['custom_alias'] ?? '');
            $selectedDomain = trim($data['domain'] ?? 'shrt-link.com');

            if (empty($originalUrl)) {
                $_SESSION['error'] = 'URL adresi girilmedi';
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }

            // URL validation
            if (!filter_var($originalUrl, FILTER_VALIDATE_URL)) {
                $_SESSION['error'] = 'Geçersiz URL adresi';
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }
            
            // Domain validation
            if (!in_array($selectedDomain, $this->availableDomains)) {
                $_SESSION['error'] = 'Geçersiz domain seçimi';
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }
            
            // Custom alias validation (opsiyonel)
            if (!empty($customAlias) && !preg_match('/^[a-zA-Z0-9_-]+$/', $customAlias)) {
                $_SESSION['error'] = 'Özel alias sadece harf, rakam, - ve _ içerebilir';
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }

            // Shrt-link.com API ile kısalt
            $shortResult = $this->shortenWithShrtLink($originalUrl, $customAlias, $selectedDomain);

            if (!$shortResult['success']) {
                $_SESSION['error'] = 'URL kısaltılamadı: ' . ($shortResult['error'] ?? 'Bilinmeyen hata');
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }

            // Veritabanına kaydet
            $shortenedUrl = new ShortenedUrl();
            $shortenedUrl->setUser($user);
            $shortenedUrl->setOriginalUrl($originalUrl);
            $shortenedUrl->setShortUrl($shortResult['short_url']);
            $shortenedUrl->setShortCode($shortResult['short_code']);
            $shortenedUrl->setApiId($shortResult['api_id'] ?? null);
            $shortenedUrl->setTitle($title ?: null);

            $this->em->persist($shortenedUrl);
            $this->em->flush();

            $_SESSION['success'] = 'URL başarıyla kısaltıldı!';

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/url-shortener')->withStatus(302);
    }

    public function createMoreUrl(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            return $response->withStatus(403);
        }

        $data = (array) ($request->getParsedBody() ?? []);
        $longUrl = trim((string) ($data['long_url'] ?? $data['url'] ?? ''));
        $domain = trim((string) ($data['more_domain'] ?? $data['domain'] ?? ''));
        $custom = trim((string) ($data['more_custom_alias'] ?? $data['custom_alias'] ?? ''));
        $title = trim((string) ($data['more_title'] ?? $data['title'] ?? ''));
        $description = trim((string) ($data['more_description'] ?? $data['description'] ?? ''));
        $status = trim((string) ($data['more_status'] ?? $data['status'] ?? 'private'));
        $type = trim((string) ($data['more_type'] ?? $data['type'] ?? 'direct'));

        if ($longUrl === '' || !filter_var($longUrl, FILTER_VALIDATE_URL)) {
            $_SESSION['more_url_error'] = 'Geçerli bir uzun URL giriniz.';
            return $response->withHeader('Location', '/url-shortener')->withStatus(302);
        }

        if ($custom !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $custom)) {
            $_SESSION['more_url_error'] = 'Özel alias sadece harf, rakam, - ve _ içerebilir.';
            return $response->withHeader('Location', '/url-shortener')->withStatus(302);
        }

        $allowedStatus = ['private', 'public'];
        $allowedTypes = ['direct', 'frame', 'splash'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'private';
        }
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'direct';
        }

        $payload = [
            'url' => $longUrl,
            'status' => $status,
            'type' => $type,
        ];
        if ($domain !== '') {
            $payload['domain'] = $domain;
        }
        if ($custom !== '') {
            $payload['custom'] = $custom;
        }
        if ($title !== '') {
            $payload['metatitle'] = $title;
        }
        if ($description !== '') {
            $payload['description'] = $description;
        }

        $create = $this->moreUrlClient->createLink($payload);
        if (!$create['ok']) {
            $_SESSION['more_url_error'] = $create['message'] ?: 'MoreURL kısa link oluşturulamadı.';
            return $response->withHeader('Location', '/url-shortener')->withStatus(302);
        }

        $created = $create['data']['url'] ?? [];
        $_SESSION['more_url_success'] = 'MoreURL kısa link oluşturuldu.';
        $_SESSION['more_url_created'] = $created['shorturl'] ?? null;

        return $response->withHeader('Location', '/url-shortener')->withStatus(302);
    }

    public function deleteMoreUrl(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            return $response->withStatus(403);
        }

        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['more_url_error'] = 'Geçersiz MoreURL ID.';
            return $response->withHeader('Location', '/url-shortener')->withStatus(302);
        }

        $result = $this->moreUrlClient->deleteLink($id);
        if (!$result['ok']) {
            $_SESSION['more_url_error'] = $result['message'] ?: 'MoreURL link silinemedi.';
        } else {
            $_SESSION['more_url_success'] = 'MoreURL link silindi.';
        }

        return $response->withHeader('Location', '/url-shortener')->withStatus(302);
    }

    public function moreUrlDetail(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            $response->getBody()->write(json_encode(['ok' => false, 'message' => 'Yetkisiz']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $id = (int) ($args['id'] ?? 0);
        $detail = $this->moreUrlClient->getLink($id);

        $response->getBody()->write(json_encode($detail));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function refreshMoreUrlDomains(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            $response->getBody()->write(json_encode(['ok' => false, 'message' => 'Yetkisiz']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $this->moreUrlClient->clearDomainCache();
        $domains = $this->moreUrlClient->listDomains(1, 100);

        $response->getBody()->write(json_encode($domains));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $url = $this->em->find(ShortenedUrl::class, (int) $args['id']);
        $data = $request->getParsedBody();

        if (!$url || $url->getUser()->getId() !== $user->getId()) {
            $_SESSION['error'] = 'URL bulunamadı veya yetkiniz yok';
            return $response->withHeader('Location', '/url-shortener')->withStatus(302);
        }

        try {
            $newUrl = trim($data['url'] ?? '');
            $newTitle = trim($data['title'] ?? '');
            $newSlug = trim($data['slug'] ?? '');

            if (empty($newUrl) || !filter_var($newUrl, FILTER_VALIDATE_URL)) {
                $_SESSION['error'] = 'Geçersiz URL adresi';
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }
            
            // Slug validation (opsiyonel)
            if (!empty($newSlug)) {
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $newSlug)) {
                    $_SESSION['error'] = 'Geçersiz slug. Sadece harf, rakam, - ve _ kullanın';
                    return $response->withHeader('Location', '/url-shortener')->withStatus(302);
                }
            }

            // Domain API'de URL ve slug'ı birlikte güncelle
            $updateResult = $this->updateShrtLinkUrl($url, $newUrl, $newSlug);
            
            if (!$updateResult['success']) {
                $_SESSION['error'] = 'URL güncellemesi başarısız: ' . ($updateResult['error'] ?? 'Bilinmeyen hata');
                return $response->withHeader('Location', '/url-shortener')->withStatus(302);
            }

            // Local veritabanında güncelle
            $url->setOriginalUrl($newUrl);
            $url->setTitle($newTitle ?: null);
            
            // Slug güncellendiyse short_url ve short_code'u güncelle
            if (!empty($newSlug) && isset($updateResult['new_short_url'])) {
                $url->setShortUrl($updateResult['new_short_url']);
                $url->setShortCode($updateResult['new_short_code'] ?? $newSlug);
            }
            
            $this->em->flush();

            $_SESSION['success'] = 'URL başarıyla güncellendi';

        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/url-shortener')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $url = $this->em->find(ShortenedUrl::class, (int) $args['id']);

        if (!$url || $url->getUser()->getId() !== $user->getId()) {
            $_SESSION['error'] = 'URL bulunamadı veya yetkiniz yok';
            return $response->withHeader('Location', '/url-shortener')->withStatus(302);
        }

        try {
            // Domain API'den sil
            $deleteResult = $this->deleteFromShrtLink($url);
            
            // API'den silinmese bile local'den silelim
            if (!$deleteResult['success']) {
                error_log('Domain API silme uyarısı: ' . ($deleteResult['error'] ?? 'Bilinmeyen hata'));
            }

            // Local veritabanından sil
            $this->em->remove($url);
            $this->em->flush();

            $_SESSION['success'] = 'URL başarıyla silindi!';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Hata: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/url-shortener')->withStatus(302);
    }

    /**
     * Domain API ile URL kısalt
     */
    private function shortenWithShrtLink(string $url, ?string $customAlias = null, string $domain = 'shrt-link.com'): array
    {
        try {
            $payload = [
                'url' => $url,
                'domain' => $domain
            ];
            
            // Custom alias varsa ekle
            if (!empty($customAlias)) {
                $payload['custom'] = $customAlias;
            }
            
            // Domain'e göre API endpoint'i ve API key'i al
            $apiEndpoint = $this->getApiEndpoint($domain);
            $apiKey = $this->getApiKey($domain);
            $ch = curl_init($apiEndpoint . '/add');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false, // SSL sertifika hatası için
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => 'cURL Hatası: ' . $error];
            }

            $data = json_decode($result, true);
            
            if ($httpCode !== 200) {
                $errorMsg = $data['message'] ?? 'HTTP ' . $httpCode;
                return ['success' => false, 'error' => $errorMsg];
            }

            // API response: {"error": 0, "id": 1, "shorturl": "https://domain.com/abc123"}
            // veya {"error": "0", "id": 1, "shorturl": "https://domain.com/abc123"}
            $errorValue = $data['error'] ?? null;
            $isSuccess = ($errorValue === 0 || $errorValue === "0" || $errorValue === false);
            
            if ($isSuccess && isset($data['shorturl'])) {
                // Short code'u URL'den çıkar
                $shortCode = basename(parse_url($data['shorturl'], PHP_URL_PATH));
                
                return [
                    'success' => true,
                    'short_url' => $data['shorturl'],
                    'short_code' => $shortCode,
                    'api_id' => $data['id'] ?? $shortCode, // API ID'yi sakla
                ];
            }

            // Hata yanıtı
            $errorMsg = $data['message'] ?? 'Geçersiz API yanıtı';
            return ['success' => false, 'error' => $errorMsg];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * API'den short code'a göre ID'yi bul
     */
    private function getApiIdByShortCode(string $domain, string $shortCode): ?int
    {
        try {
            $apiEndpoint = $this->getApiEndpoint($domain);
            $apiKey = $this->getApiKey($domain);
            
            // List endpoint'inden tüm URL'leri al ve short code'a göre ID'yi bul
            $ch = curl_init($apiEndpoint . 's?limit=100&page=1&order=date');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($result, true);
                $errorValue = $data['error'] ?? null;
                $isSuccess = ($errorValue === 0 || $errorValue === "0" || $errorValue === false);
                
                if ($isSuccess && isset($data['data']['urls'])) {
                    foreach ($data['data']['urls'] as $url) {
                        // alias veya shorturl'den short code'u çıkar
                        $alias = $url['alias'] ?? '';
                        $shortUrl = $url['shorturl'] ?? '';
                        $urlShortCode = $alias ?: basename(parse_url($shortUrl, PHP_URL_PATH));
                        
                        if ($urlShortCode === $shortCode) {
                            return (int)($url['id'] ?? null);
                        }
                    }
                }
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Domain API'de URL güncelle
     */
    private function updateShrtLinkUrl(ShortenedUrl $shortenedUrl, string $newUrl, ?string $newSlug = null): array
    {
        try {
            // Short URL'den domain'i çıkar
            $shortUrl = $shortenedUrl->getShortUrl();
            $parsedUrl = parse_url($shortUrl);
            $domain = $parsedUrl['host'] ?? 'shrt-link.com';
            
            $payload = ['url' => $newUrl];
            
            // Slug güncelleme varsa custom parametresi ekle
            if (!empty($newSlug)) {
                $payload['custom'] = $newSlug;
            }
            
            // Domain'e göre API endpoint'i ve API key'i al
            $apiEndpoint = $this->getApiEndpoint($domain);
            $apiKey = $this->getApiKey($domain);
            
            // API endpoint formatı: /api/url/{id}/update
            // Önce short_code'dan ID'yi bulmaya çalış, bulamazsan short_code'u ID olarak kullan
            $apiId = $this->getApiIdByShortCode($domain, $shortenedUrl->getShortCode());
            if ($apiId === null) {
                // ID bulunamadıysa short_code'u ID olarak kullanmayı dene
                $apiId = $shortenedUrl->getShortCode();
            }
            
            $ch = curl_init($apiEndpoint . '/' . urlencode((string)$apiId) . '/update');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'PUT',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => 'cURL Hatası: ' . $error];
            }

            $data = json_decode($result, true);

            // API yanıtını kontrol et - error: 0 veya error: "0" formatları
            $errorValue = $data['error'] ?? null;
            $isSuccess = ($errorValue === 0 || $errorValue === "0" || $errorValue === false);
            
            if ($httpCode === 200 && $isSuccess) {
                // Slug güncellendiyse yeni short URL'i döndür
                if (!empty($newSlug)) {
                    $protocol = parse_url($shortUrl, PHP_URL_SCHEME) ?? 'https';
                    $newShortUrl = $protocol . '://' . $domain . '/' . $newSlug;
                    
                    return [
                        'success' => true,
                        'new_short_url' => $newShortUrl,
                        'new_short_code' => $newSlug
                    ];
                }
                
                return ['success' => true];
            }

            $errorMsg = $data['message'] ?? ($data['error'] ?? 'HTTP ' . $httpCode);
            return ['success' => false, 'error' => $errorMsg];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    

    /**
     * Domain API'den URL sil
     */
    private function deleteFromShrtLink(ShortenedUrl $shortenedUrl): array
    {
        try {
            // Short URL'den domain'i çıkar
            $shortUrl = $shortenedUrl->getShortUrl();
            $parsedUrl = parse_url($shortUrl);
            $domain = $parsedUrl['host'] ?? 'shrt-link.com';
            
            // Domain'e göre API endpoint'i ve API key'i al
            $apiEndpoint = $this->getApiEndpoint($domain);
            $apiKey = $this->getApiKey($domain);
            
            // API endpoint formatı: /api/url/{id}/delete
            // Önce short_code'dan ID'yi bulmaya çalış, bulamazsan short_code'u ID olarak kullan
            $apiId = $this->getApiIdByShortCode($domain, $shortenedUrl->getShortCode());
            if ($apiId === null) {
                // ID bulunamadıysa short_code'u ID olarak kullanmayı dene
                $apiId = $shortenedUrl->getShortCode();
            }
            
            $ch = curl_init($apiEndpoint . '/' . urlencode((string)$apiId) . '/delete');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => 'cURL Hatası: ' . $error];
            }

            $data = json_decode($result, true);

            // API yanıtını kontrol et - error: 0 veya error: "0" formatları
            $errorValue = $data['error'] ?? null;
            $isSuccess = ($errorValue === 0 || $errorValue === "0" || $errorValue === false);

            if ($httpCode === 200 && $isSuccess) {
                return ['success' => true];
            }

            // Hata olsa bile devam et (limit yok)
            return ['success' => true, 'warning' => $data['message'] ?? 'API silme hatası'];

        } catch (\Exception $e) {
            // Exception olsa bile devam et (limit yok)
            return ['success' => true, 'warning' => $e->getMessage()];
        }
    }

    /**
     * API'den tıklama bilgilerini çekip veritabanını güncelle
     */
    private function updateClickCountsFromApi(array $urls): void
    {
        if (empty($urls)) {
            return;
        }

        // Domain'lere göre grupla
        $urlsByDomain = [];
        foreach ($urls as $url) {
            $shortUrl = $url->getShortUrl();
            $parsedUrl = parse_url($shortUrl);
            $domain = $parsedUrl['host'] ?? 'shrt-link.com';
            
            if (!isset($urlsByDomain[$domain])) {
                $urlsByDomain[$domain] = [];
            }
            $urlsByDomain[$domain][] = $url;
        }

        // Her domain için API'den tıklama bilgilerini çek
        foreach ($urlsByDomain as $domain => $domainUrls) {
            try {
                $apiEndpoint = $this->getApiEndpoint($domain);
                $apiKey = $this->getApiKey($domain);
                
                // API'den tüm URL'leri çek (limit yüksek tutulabilir)
                $ch = curl_init($apiEndpoint . 's?limit=1000&page=1&order=date');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $apiKey,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false,
                ]);

                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $data = json_decode($result, true);
                    $errorValue = $data['error'] ?? null;
                    $isSuccess = ($errorValue === 0 || $errorValue === "0" || $errorValue === false);
                    
                    if ($isSuccess && isset($data['data']['urls'])) {
                        // API'den gelen URL'leri short code'a göre map'le
                        $apiUrlsMap = [];
                        foreach ($data['data']['urls'] as $apiUrl) {
                            $alias = $apiUrl['alias'] ?? '';
                            $shortUrl = $apiUrl['shorturl'] ?? '';
                            $shortCode = $alias ?: basename(parse_url($shortUrl, PHP_URL_PATH));
                            $clicks = (int)($apiUrl['clicks'] ?? 0);
                            
                            $apiUrlsMap[$shortCode] = $clicks;
                        }
                        
                        // Veritabanındaki URL'leri güncelle
                        $updated = false;
                        foreach ($domainUrls as $url) {
                            $shortCode = $url->getShortCode();
                            if (isset($apiUrlsMap[$shortCode])) {
                                $newClickCount = $apiUrlsMap[$shortCode];
                                if ($url->getClickCount() !== $newClickCount) {
                                    $url->setClickCount($newClickCount);
                                    $updated = true;
                                }
                            }
                        }
                        
                        if ($updated) {
                            $this->em->flush();
                        }
                    }
                }
            } catch (\Exception $e) {
                // Hata durumunda sessizce devam et
                error_log('API click count update error: ' . $e->getMessage());
            }
        }
    }

    /**
     * AJAX endpoint: Tıklama bilgilerini güncelle
     */
    public function updateClickCounts(Request $request, Response $response): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);

        if (!$user) {
            return $response->withJson(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        // Kullanıcının tüm URL'lerini getir
        $urls = $this->em->createQueryBuilder()
            ->select('u')
            ->from(ShortenedUrl::class, 'u')
            ->where('u.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        // API'den tıklama bilgilerini güncelle
        $this->updateClickCountsFromApi($urls);

        // Güncellenmiş tıklama sayılarını döndür
        $clickCounts = [];
        foreach ($urls as $url) {
            $clickCounts[$url->getId()] = $url->getClickCount();
        }

        // Toplam tıklama sayısını hesapla
        $totalClicks = array_sum($clickCounts);

        $responseData = [
            'success' => true,
            'clickCounts' => $clickCounts,
            'totalClicks' => $totalClicks
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Link detaylarını JSON olarak döndür (AJAX endpoint)
     */
    public function getDetails(Request $request, Response $response, array $args): Response
    {
        $userId = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? null;
        $user = $this->em->find(User::class, $userId);
        $url = $this->em->find(ShortenedUrl::class, (int) $args['id']);

        if (!$url || $url->getUser()->getId() !== $user->getId()) {
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'URL bulunamadı veya yetkiniz yok'
            ]));
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(403);
        }

        // API'den detaylı bilgileri çek
        $apiDetails = $this->getUrlDetailsFromApi($url);
        
        // Click stats'ı güncelle
        if ($apiDetails['success'] && isset($apiDetails['data'])) {
            $url->setClickCount($apiDetails['data']['clicks'] ?? $url->getClickCount());
            $url->setClickStats($apiDetails['data']);
            $this->em->flush();
        }

        $responseData = [
            'success' => true,
            'url' => [
                'id' => $url->getId(),
                'title' => $url->getTitle(),
                'original_url' => $url->getOriginalUrl(),
                'short_url' => $url->getShortUrl(),
                'short_code' => $url->getShortCode(),
                'click_count' => $url->getClickCount(),
                'created_at' => $url->getCreatedAt()->format('d.m.Y H:i'),
            ],
            'details' => $apiDetails['data'] ?? null,
        ];

        $response->getBody()->write(json_encode($responseData));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API'den URL detaylarını çek
     */
    private function getUrlDetailsFromApi(ShortenedUrl $url): array
    {
        try {
            $shortUrl = $url->getShortUrl();
            $parsedUrl = parse_url($shortUrl);
            $domain = $parsedUrl['host'] ?? 'shrt-link.com';
            
            $apiEndpoint = $this->getApiEndpoint($domain);
            $apiKey = $this->getApiKey($domain);
            
            // API ID'yi bul
            $apiId = $url->getApiId();
            if (!$apiId) {
                $apiId = $this->getApiIdByShortCode($domain, $url->getShortCode());
                if ($apiId) {
                    $url->setApiId((string)$apiId);
                    $this->em->flush();
                }
            }
            
            if (!$apiId) {
                return ['success' => false, 'error' => 'API ID bulunamadı'];
            }
            
            $ch = curl_init($apiEndpoint . '/' . urlencode((string)$apiId));
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
            ]);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($result, true);
                $errorValue = $data['error'] ?? null;
                $isSuccess = ($errorValue === 0 || $errorValue === "0" || $errorValue === false);
                
                if ($isSuccess && isset($data['details'])) {
                    return ['success' => true, 'data' => $data['details']];
                }
            }
            
            return ['success' => false, 'error' => 'API hatası'];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

