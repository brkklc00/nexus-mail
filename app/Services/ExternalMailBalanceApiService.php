<?php

declare(strict_types=1);

namespace App\Services;

class ExternalMailBalanceApiService
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeoutSeconds;

    public function __construct(array $settings = [])
    {
        $externalSettings = $settings['external_api'] ?? [];
        $this->baseUrl = rtrim((string) ($externalSettings['base_url'] ?? getenv('EXTERNAL_API_BASE_URL') ?: 'https://hub-nexus.com'), '/');
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

        return $this->request('GET', '/api/external/users?' . http_build_query($params));
    }

    public function getUser(int $id): array
    {
        return $this->request('GET', '/api/external/users/' . max(1, $id));
    }

    public function subtractBalance(int $userId, int $amount, string $description = ''): array
    {
        return $this->request('POST', '/api/external/users/' . max(1, $userId) . '/mail-balance/subtract', [
            'amount' => max(0, $amount),
            'description' => $description,
        ]);
    }

    public function addBalance(int $userId, int $amount, string $description = ''): array
    {
        return $this->request('POST', '/api/external/users/' . max(1, $userId) . '/mail-balance/add', [
            'amount' => max(0, $amount),
            'description' => $description,
        ]);
    }

    public function setBalance(int $userId, int $amount, string $description = ''): array
    {
        return $this->request('POST', '/api/external/users/' . max(1, $userId) . '/mail-balance/set', [
            'amount' => max(0, $amount),
            'description' => $description,
        ]);
    }

    public function getBalanceLogs(int $userId, int $page = 1, int $limit = 50): array
    {
        return $this->request('GET', '/api/external/users/' . max(1, $userId) . '/mail-balance/logs?' . http_build_query([
            'page' => max(1, $page),
            'limit' => max(1, min(100, $limit)),
        ]));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'status' => 503,
                'message' => 'External API is not configured',
                'data' => [],
            ];
        }

        $url = $this->baseUrl . $path;
        $ch = curl_init($url);

        if ($ch === false) {
            return [
                'success' => false,
                'status' => 500,
                'message' => 'Curl initialization failed',
                'data' => [],
            ];
        }

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-API-Key: ' . $this->apiKey,
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
            return [
                'success' => false,
                'status' => 0,
                'message' => 'Timeout',
                'data' => ['curl_error' => $curlError],
            ];
        }

        if (!is_string($raw) || trim($raw) === '') {
            return [
                'success' => false,
                'status' => $status > 0 ? $status : 500,
                'message' => 'Empty response from external API',
                'data' => [],
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'success' => false,
                'status' => $status > 0 ? $status : 500,
                'message' => 'Invalid JSON response from external API',
                'data' => ['raw' => $raw],
            ];
        }

        $success = (bool) ($decoded['success'] ?? false);
        return [
            'success' => $success,
            'status' => $status > 0 ? $status : ($success ? 200 : 500),
            'message' => (string) ($decoded['message'] ?? ($success ? 'OK' : 'Request failed')),
            'data' => $decoded['data'] ?? [],
            'pagination' => $decoded['pagination'] ?? null,
            'raw' => $decoded,
        ];
    }
}

