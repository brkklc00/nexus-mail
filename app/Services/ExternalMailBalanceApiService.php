<?php

declare(strict_types=1);

namespace App\Services;

class ExternalMailBalanceApiService
{
    private string $apiBaseUrl;
    private string $apiKey;
    private int $timeoutSeconds;

    public function __construct(array $settings = [])
    {
        $externalSettings = $settings['external_api'] ?? [];
        $this->apiBaseUrl = $this->resolveApiBaseUrl(
            (string) ($externalSettings['base_url'] ?? getenv('EXTERNAL_API_BASE_URL') ?: 'https://hub-nexus.com')
        );
        $this->apiKey = (string) ($externalSettings['key'] ?? getenv('EXTERNAL_API_KEY') ?: '');
        $this->timeoutSeconds = (int) ($externalSettings['timeout_seconds'] ?? 10);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function listUsers(?string $query = null, int $page = 1, int $limit = 50): array
    {
        $params = [
            'page' => max(1, $page),
            'limit' => max(1, min(100, $limit)),
        ];
        if ($query !== null && trim($query) !== '') {
            $params['q'] = trim($query);
        }

        $result = $this->request('GET', '/users?' . http_build_query($params));
        if (!$result['success']) {
            return $result;
        }

        $usersRaw = $result['data']['users'] ?? $result['data'] ?? [];
        $usersRaw = is_array($usersRaw) ? $usersRaw : [];
        $users = array_map([$this, 'normalizeUserPayload'], $usersRaw);
        $pagination = $this->normalizePaginationPayload($result['data']['pagination'] ?? $result['pagination'] ?? null, $page, $limit);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'OK',
            'users' => $users,
            'pagination' => $pagination,
            'data' => $result['data'],
        ];
    }

    public function getUser(int $id): array
    {
        $result = $this->request('GET', '/users/' . max(1, $id));
        if (!$result['success']) {
            return $result;
        }

        $userRaw = $result['data']['user'] ?? $result['data'] ?? [];
        $userRaw = is_array($userRaw) ? $userRaw : [];
        $user = $this->normalizeUserPayload($userRaw);

        return [
            'success' => true,
            'status' => 200,
            'message' => 'OK',
            'user' => $user,
            'data' => $result['data'],
        ];
    }

    public function subtractBalance(int $userId, int $amount, string $description = ''): array
    {
        return $this->request('POST', '/users/' . max(1, $userId) . '/mail-balance/subtract', [
            'amount' => max(0, $amount),
            'description' => $description,
        ]);
    }

    public function addBalance(int $userId, int $amount, string $description = ''): array
    {
        return $this->request('POST', '/users/' . max(1, $userId) . '/mail-balance/add', [
            'amount' => max(0, $amount),
            'description' => $description,
        ]);
    }

    public function setBalance(int $userId, int $amount, string $description = ''): array
    {
        return $this->request('POST', '/users/' . max(1, $userId) . '/mail-balance/set', [
            'amount' => max(0, $amount),
            'description' => $description,
        ]);
    }

    public function getBalanceLogs(int $userId, int $page = 1, int $limit = 50): array
    {
        return $this->request('GET', '/users/' . max(1, $userId) . '/mail-balance/logs?' . http_build_query([
            'page' => max(1, $page),
            'limit' => max(1, min(100, $limit)),
        ]));
    }

    private function resolveApiBaseUrl(string $rawBaseUrl): string
    {
        $rawBaseUrl = trim($rawBaseUrl);
        if ($rawBaseUrl === '') {
            $rawBaseUrl = 'https://hub-nexus.com';
        }

        $parts = parse_url($rawBaseUrl);
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'https';
        $host = strtolower((string) ($parts['host'] ?? 'hub-nexus.com'));
        if ($host === 'mail.hub-nexus.com') {
            $host = 'hub-nexus.com';
        }

        return $scheme . '://' . $host . '/api/external';
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->isConfigured()) {
            $error = $this->normalizeError(503, 'api_key_missing');
            return $error + [
                'success' => false,
                'data' => [],
            ];
        }

        $url = $this->apiBaseUrl . $path;
        $ch = curl_init($url);

        if ($ch === false) {
            $error = $this->normalizeError(500, 'curl_init_failed');
            return $error + [
                'success' => false,
                'data' => [],
            ];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $token = str_contains(strtolower($curlError), 'timed out') ? 'timeout' : 'network';
            $error = $this->normalizeError(0, $token);
            $this->logApiError($url, 0, ['curl_error' => $curlError]);
            return $error + [
                'success' => false,
                'data' => ['curl_error' => $curlError, 'endpoint' => $url],
            ];
        }

        if (!is_string($raw) || trim($raw) === '') {
            $error = $this->normalizeError($status > 0 ? $status : 500, 'empty_response');
            $this->logApiError($url, $status, ['raw' => '']);
            return $error + [
                'success' => false,
                'data' => ['endpoint' => $url],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $error = $this->normalizeError($status > 0 ? $status : 500, 'invalid_json');
            $this->logApiError($url, $status, ['raw' => $this->safeSubstr((string) $raw, 300)]);
            return $error + [
                'success' => false,
                'data' => ['endpoint' => $url, 'raw' => $this->safeSubstr($raw, 500)],
            ];
        }

        $success = array_key_exists('success', $decoded)
            ? (bool) $decoded['success']
            : ($status >= 200 && $status < 300);

        if (!$success) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'request_failed');
            $error = $this->normalizeError($status > 0 ? $status : 500, $message);
            $this->logApiError($url, $status, $decoded);
            return $error + [
                'success' => false,
                'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
                'raw' => $decoded,
            ];
        }

        return [
            'success' => true,
            'status' => $status > 0 ? $status : 200,
            'message' => (string) ($decoded['message'] ?? 'OK'),
            'data' => $decoded['data'] ?? [],
            'pagination' => $decoded['pagination'] ?? null,
            'raw' => $decoded,
        ];
    }

    private function normalizeUserPayload(array $user): array
    {
        return [
            'id' => (int) ($user['id'] ?? 0),
            'name' => trim((string) ($user['name'] ?? $user['full_name'] ?? '')),
            'email' => trim((string) ($user['email'] ?? '')),
            'mail_balance' => (int) ($user['mail_balance'] ?? $user['balance'] ?? 0),
        ];
    }

    private function normalizePaginationPayload(mixed $rawPagination, int $page, int $limit): array
    {
        $pagination = is_array($rawPagination) ? $rawPagination : [];
        $total = (int) ($pagination['total'] ?? $pagination['count'] ?? count((array) ($pagination['items'] ?? [])));
        $normalizedPage = max(1, (int) ($pagination['page'] ?? $page));
        $normalizedLimit = max(1, (int) ($pagination['limit'] ?? $limit));
        $totalPages = max(1, (int) ($pagination['total_pages'] ?? ceil(max(1, $total) / $normalizedLimit)));

        return [
            'page' => $normalizedPage,
            'limit' => $normalizedLimit,
            'total' => $total,
            'has_next' => $normalizedPage < $totalPages,
            'total_pages' => $totalPages,
        ];
    }

    private function normalizeError(int $status, string $message): array
    {
        $msg = strtolower(trim($message));
        $userMessage = 'Bakiye API işleminde hata oluştu.';
        if ($status === 401 || str_contains($msg, 'unauthorized') || str_contains($msg, 'invalid token')) {
            $userMessage = 'Bakiye API anahtarı geçersiz.';
        } elseif ($status === 404 || str_contains($msg, 'not found')) {
            $userMessage = 'Müşteri bulunamadı.';
        } elseif ($status === 422 || str_contains($msg, 'validation')) {
            if (str_contains($msg, 'insufficient')) {
                $userMessage = 'Yetersiz mail bakiyesi.';
            } else {
                $userMessage = 'Bakiye işlemi geçersiz.';
            }
        } elseif ($status >= 500) {
            $userMessage = 'Bakiye API tarafında sunucu hatası oluştu.';
        } elseif ($status === 0 && ($msg === 'timeout' || str_contains($msg, 'timed out'))) {
            $userMessage = 'Bakiye API zaman aşımına uğradı.';
        } elseif ($status === 0) {
            $userMessage = 'Bakiye API bağlantısı kurulamadı.';
        }

        return [
            'status' => $status > 0 ? $status : 500,
            'message' => $message,
            'user_message' => $userMessage,
        ];
    }

    private function logApiError(string $endpoint, int $status, array $payload): void
    {
        $safePayload = $payload;
        if (isset($safePayload['token'])) {
            unset($safePayload['token']);
        }
        error_log('ExternalMailBalanceApiService error status=' . $status . ' endpoint=' . $endpoint . ' payload=' . json_encode($safePayload, JSON_UNESCAPED_UNICODE));
    }

    private function safeSubstr(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return (string) mb_substr($value, 0, $length, 'UTF-8');
        }
        return substr($value, 0, $length);
    }
}

