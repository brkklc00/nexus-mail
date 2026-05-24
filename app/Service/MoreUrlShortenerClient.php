<?php

declare(strict_types=1);

namespace App\Service;

class MoreUrlShortenerClient
{
    private const API_BASE = 'https://more-url.xyz/api';
    private const API_KEY = 'bIfJTZwFWFeuDCZztkEBmNpnlKPYqOrJ';
    private const TIMEOUT_SECONDS = 12;
    private const DOMAIN_CACHE_TTL = 300;
    private const DOMAIN_CACHE_FILE = '/storage/cache/moreurl-domains-cache.json';

    public function listLinks(int $page = 1, int $limit = 20, string $order = 'date', ?string $q = null): array
    {
        $query = [
            'limit' => max(1, min(100, $limit)),
            'page' => max(1, $page),
            'order' => $order !== '' ? $order : 'date',
        ];

        $res = $this->request('GET', '/urls', null, $query);
        if (!$res['ok']) {
            return $res;
        }

        $items = $res['data']['data']['urls'] ?? [];
        $urls = [];
        foreach ($items as $item) {
            $row = [
                'id' => (int) ($item['id'] ?? 0),
                'alias' => (string) ($item['alias'] ?? ''),
                'shorturl' => (string) ($item['shorturl'] ?? ''),
                'longurl' => (string) ($item['longurl'] ?? ''),
                'clicks' => (int) ($item['clicks'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'description' => (string) ($item['description'] ?? ''),
                'date' => (string) ($item['date'] ?? ''),
            ];
            if ($row['id'] <= 0 || $row['shorturl'] === '') {
                continue;
            }
            $urls[] = $row;
        }

        if ($q !== null && trim($q) !== '') {
            $needle = mb_strtolower(trim($q));
            $urls = array_values(array_filter($urls, static function (array $url) use ($needle): bool {
                $haystack = mb_strtolower(
                    ($url['title'] ?? '') . ' ' .
                    ($url['description'] ?? '') . ' ' .
                    ($url['shorturl'] ?? '') . ' ' .
                    ($url['longurl'] ?? '')
                );
                return str_contains($haystack, $needle);
            }));
        }

        return [
            'ok' => true,
            'message' => '',
            'data' => [
                'urls' => $urls,
                'pagination' => $res['data']['data']['pagination'] ?? null,
            ],
        ];
    }

    public function createLink(array $payload): array
    {
        $allowed = [
            'url' => (string) ($payload['url'] ?? ''),
            'custom' => (string) ($payload['custom'] ?? ''),
            'domain' => (string) ($payload['domain'] ?? ''),
            'metatitle' => (string) ($payload['metatitle'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'private'),
            'type' => (string) ($payload['type'] ?? 'direct'),
        ];

        if ($allowed['url'] === '') {
            return ['ok' => false, 'message' => 'URL alanı zorunludur.', 'data' => []];
        }

        if ($allowed['domain'] !== '') {
            $allowed['domain'] = $this->normalizeDomainUrl($allowed['domain']);
        } else {
            unset($allowed['domain']);
        }
        if ($allowed['custom'] === '') {
            unset($allowed['custom']);
        }
        if ($allowed['metatitle'] === '') {
            unset($allowed['metatitle']);
        }
        if ($allowed['description'] === '') {
            unset($allowed['description']);
        }

        $res = $this->request('POST', '/url/add', $allowed);
        if (!$res['ok']) {
            return $res;
        }

        return [
            'ok' => true,
            'message' => 'MoreURL kısa link oluşturuldu.',
            'data' => [
                'url' => [
                    'id' => (int) ($res['data']['id'] ?? 0),
                    'shorturl' => (string) ($res['data']['shorturl'] ?? ''),
                    'alias' => (string) ($res['data']['alias'] ?? ''),
                    'longurl' => (string) ($allowed['url'] ?? ''),
                ],
                'raw' => $res['data'],
            ],
        ];
    }

    public function listDomains(int $page = 1, int $limit = 100): array
    {
        $cached = $this->getDomainCache();
        if ($cached !== null) {
            return ['ok' => true, 'message' => '', 'data' => ['domains' => $cached, 'cached' => true]];
        }

        $query = [
            'limit' => max(1, min(100, $limit)),
            'page' => max(1, $page),
        ];
        $res = $this->request('GET', '/domains', null, $query);
        if (!$res['ok']) {
            return $res;
        }

        $items = $res['data']['data']['domains'] ?? [];
        $domains = [];
        foreach ($items as $item) {
            $domain = (string) ($item['domain'] ?? '');
            if ($domain === '') {
                continue;
            }
            $normalizedUrl = $this->normalizeDomainUrl($domain);
            $domains[] = [
                'id' => (int) ($item['id'] ?? 0),
                'domain' => $domain,
                'value' => $normalizedUrl,
                'display' => $this->normalizeDomainDisplay($domain),
                'redirectroot' => (string) ($item['redirectroot'] ?? ''),
                'redirect404' => (string) ($item['redirect404'] ?? ''),
            ];
        }

        $this->setDomainCache($domains);

        return [
            'ok' => true,
            'message' => '',
            'data' => ['domains' => $domains, 'cached' => false],
        ];
    }

    public function getLink(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Geçersiz link ID.', 'data' => []];
        }

        $res = $this->request('GET', '/url/' . $id);
        if (!$res['ok']) {
            return $res;
        }

        return [
            'ok' => true,
            'message' => '',
            'data' => [
                'id' => (int) ($res['data']['id'] ?? $id),
                'alias' => (string) ($res['data']['alias'] ?? ''),
                'shorturl' => (string) ($res['data']['shorturl'] ?? ''),
                'longurl' => (string) ($res['data']['longurl'] ?? ''),
                'clicks' => (int) ($res['data']['clicks'] ?? 0),
                'title' => (string) ($res['data']['title'] ?? ''),
                'description' => (string) ($res['data']['description'] ?? ''),
                'date' => (string) ($res['data']['date'] ?? ''),
                'raw' => $res['data'],
            ],
        ];
    }

    public function deleteLink(int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'message' => 'Geçersiz link ID.', 'data' => []];
        }

        $res = $this->request('DELETE', '/url/' . $id . '/delete');
        if (!$res['ok']) {
            return $res;
        }

        return ['ok' => true, 'message' => 'Link silindi.', 'data' => []];
    }

    public function account(): array
    {
        return $this->request('GET', '/account');
    }

    public function clearDomainCache(): void
    {
        $path = $this->getDomainCachePath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function request(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $url = rtrim(self::API_BASE, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'message' => 'HTTP istemcisi başlatılamadı.', 'data' => []];
        }

        $headers = [
            'Authorization: Bearer ' . self::API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $methodUpper = strtoupper($method);
        if ($methodUpper === 'POST') {
            $options[CURLOPT_POST] = true;
        } elseif ($methodUpper !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $methodUpper;
        }
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false || $curlError !== '') {
            return ['ok' => false, 'message' => 'MoreURL API erişilemiyor.', 'data' => []];
        }
        if ($httpCode === 401 || $httpCode === 403) {
            return ['ok' => false, 'message' => 'MoreURL API kimlik doğrulama hatası.', 'data' => []];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            return ['ok' => false, 'message' => 'MoreURL API hatası (HTTP ' . $httpCode . ').', 'data' => []];
        }

        $decoded = json_decode($result, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'MoreURL API geçersiz yanıt döndürdü.', 'data' => []];
        }

        $errorValue = $decoded['error'] ?? 0;
        $isOk = ($errorValue === 0 || $errorValue === '0' || $errorValue === false || $errorValue === null);
        if (!$isOk) {
            $message = (string) ($decoded['message'] ?? 'MoreURL API işlem hatası.');
            return ['ok' => false, 'message' => $message, 'data' => []];
        }

        return ['ok' => true, 'message' => '', 'data' => $decoded];
    }

    private function normalizeDomainUrl(string $domain): string
    {
        $normalized = trim($domain);
        if ($normalized === '') {
            return '';
        }
        if (!str_starts_with($normalized, 'http://') && !str_starts_with($normalized, 'https://')) {
            $normalized = 'https://' . $normalized;
        }
        return rtrim($normalized, '/');
    }

    private function normalizeDomainDisplay(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', trim($domain)) ?? '';
        return rtrim($domain, '/');
    }

    private function getDomainCachePath(): string
    {
        return dirname(__DIR__, 2) . self::DOMAIN_CACHE_FILE;
    }

    private function getDomainCache(): ?array
    {
        $path = $this->getDomainCachePath();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        $expiresAt = (int) ($data['expires_at'] ?? 0);
        if ($expiresAt < time()) {
            return null;
        }
        $domains = $data['domains'] ?? null;
        return is_array($domains) ? $domains : null;
    }

    private function setDomainCache(array $domains): void
    {
        $path = $this->getDomainCachePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $payload = [
            'expires_at' => time() + self::DOMAIN_CACHE_TTL,
            'domains' => array_values($domains),
        ];
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}

