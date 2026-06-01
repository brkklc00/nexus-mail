<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\TelegramNotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TelegramNotificationController
{
    private const CSRF_SESSION_KEY = 'telegram_notifications_csrf';

    public function __construct(private TelegramNotificationService $service)
    {
    }

    public function settings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz işlem.'], 403);
        }
        try {
            $data = $this->service->getSettingsForAdmin();
            $data['csrf_token'] = $this->getOrCreateCsrfToken();
            return $this->json($response, ['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Telegram ayarları alınamadı. Migration çalıştırın: bash bin/run-doctrine-migrations.sh'], 500);
        }
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        try {
            $saved = $this->service->saveSettings($this->parseBody($request));
            $saved['csrf_token'] = $this->getOrCreateCsrfToken();
            return $this->json($response, ['success' => true, 'message' => 'Telegram ayarları kaydedildi.', 'data' => $saved]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Telegram ayarları kaydedilemedi: ' . $e->getMessage()], 422);
        }
    }

    public function events(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz işlem.'], 403);
        }
        try {
            return $this->json($response, ['success' => true, 'data' => $this->service->getEventsForAdmin()]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Event listesi alınamadı. Migration çalıştırın.'], 500);
        }
    }

    public function saveEvents(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        try {
            $rows = $this->service->saveEvents($this->parseBody($request));
            return $this->json($response, ['success' => true, 'message' => 'Event ayarları kaydedildi.', 'data' => $rows]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Event ayarları kaydedilemedi: ' . $e->getMessage()], 422);
        }
    }

    public function templates(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz işlem.'], 403);
        }
        try {
            return $this->json($response, ['success' => true, 'data' => $this->service->getTemplatesForAdmin()]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Şablonlar alınamadı. Migration çalıştırın.'], 500);
        }
    }

    public function saveTemplates(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        try {
            $rows = $this->service->saveTemplates($this->parseBody($request));
            return $this->json($response, ['success' => true, 'message' => 'Şablonlar kaydedildi.', 'data' => $rows]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Şablonlar kaydedilemedi: ' . $e->getMessage()], 422);
        }
    }

    public function updateTemplate(Request $request, Response $response, array $args): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        try {
            $id = (int) ($args['id'] ?? 0);
            $row = $this->service->updateTemplateById($id, $this->parseBody($request));
            return $this->json($response, ['success' => true, 'message' => 'Şablon güncellendi.', 'data' => $row]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Şablon güncellenemedi: ' . $e->getMessage()], 422);
        }
    }

    public function loadDefaults(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        try {
            $data = $this->service->loadDefaults();
            $data['csrf_token'] = $this->getOrCreateCsrfToken();
            return $this->json($response, ['success' => true, 'message' => 'Varsayılan şablonlar yüklendi.', 'data' => $data]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Varsayılanlar yüklenemedi: ' . $e->getMessage()], 422);
        }
    }

    public function test(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        $data = $this->parseBody($request);
        $res = $this->service->sendTestMessage(
            isset($data['bot_token']) ? trim((string) $data['bot_token']) : null,
            isset($data['chat_id']) ? trim((string) $data['chat_id']) : null,
            isset($data['message']) ? (string) $data['message'] : null
        );
        $status = !empty($res['success']) ? 200 : 422;
        return $this->json($response, [
            'success' => !empty($res['success']),
            'message' => (string) ($res['message'] ?? ''),
            'data' => [
                'last_test_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'last_test_status' => !empty($res['success']) ? 'success' : 'failed',
                'last_error' => !empty($res['success']) ? '' : (string) ($res['message'] ?? ''),
            ],
        ], $status);
    }

    public function testTemplate(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }
        $data = $this->parseBody($request);
        $key = trim((string) ($data['template_key'] ?? ''));
        $res = $this->service->sendTemplateTest($key, is_array($data['sample_variables'] ?? null) ? $data['sample_variables'] : []);
        return $this->json($response, [
            'success' => !empty($res['success']),
            'message' => (string) ($res['message'] ?? ''),
        ], !empty($res['success']) ? 200 : 422);
    }

    public function logs(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz işlem.'], 403);
        }
        try {
            $limit = max(1, min(500, (int) ($request->getQueryParams()['limit'] ?? 100)));
            return $this->json($response, ['success' => true, 'data' => $this->service->getLogs($limit)]);
        } catch (\Throwable $e) {
            return $this->json($response, ['success' => false, 'message' => 'Loglar alınamadı. Migration çalıştırın.'], 500);
        }
    }

    // Backward compatibility old route
    public function resetTemplate(Request $request, Response $response): Response
    {
        return $this->loadDefaults($request, $response);
    }

    private function parseBody(Request $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isAdmin(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return (bool) ($_SESSION['is_admin'] ?? false);
    }

    private function validateAdminAndCsrf(Request $request): bool
    {
        return $this->isAdmin() && $this->isValidCsrf($request);
    }

    private function isValidCsrf(Request $request): bool
    {
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($token) || $token === '') {
            return false;
        }
        $body = $this->parseBody($request);
        $candidate = (string) ($body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
        return $candidate !== '' && hash_equals($token, $candidate);
    }

    private function getOrCreateCsrfToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = $_SESSION[self::CSRF_SESSION_KEY] ?? '';
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(24));
            $_SESSION[self::CSRF_SESSION_KEY] = $token;
        }
        return $token;
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

