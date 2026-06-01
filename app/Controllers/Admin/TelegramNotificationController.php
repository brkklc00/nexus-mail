<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Application\Services\TelegramNotificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class TelegramNotificationController
{
    private const CSRF_SESSION_KEY = 'telegram_notifications_csrf';

    public function __construct(private TelegramNotificationService $telegramService)
    {
    }

    public function settings(Request $request, Response $response): Response
    {
        if (!$this->isAdmin()) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz işlem.'], 403);
        }

        $responsePayload = $this->telegramService->getSettingsForAdmin();
        $responsePayload['csrf_token'] = $this->getOrCreateCsrfToken();
        $responsePayload['event_labels'] = $this->telegramService->getEventLabels();

        return $this->json($response, [
            'success' => true,
            'data' => $responsePayload,
        ]);
    }

    public function saveSettings(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }

        $data = $this->parseBody($request);
        $saved = $this->telegramService->saveSettings($data);
        $saved['csrf_token'] = $this->getOrCreateCsrfToken();
        $saved['event_labels'] = $this->telegramService->getEventLabels();

        return $this->json($response, [
            'success' => true,
            'message' => 'Telegram bildirim ayarları kaydedildi.',
            'data' => $saved,
        ]);
    }

    public function test(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }

        $data = $this->parseBody($request);
        $token = isset($data['bot_token']) ? trim((string) $data['bot_token']) : null;
        $chatId = isset($data['chat_id']) ? trim((string) $data['chat_id']) : null;
        $result = $this->telegramService->sendTestMessage($token ?: null, $chatId ?: null);

        if (!$result['success']) {
            return $this->json($response, [
                'success' => false,
                'message' => (string) ($result['message'] ?? 'Test mesajı gönderilemedi.'),
            ], 422);
        }

        return $this->json($response, [
            'success' => true,
            'message' => 'Test mesajı Telegram grubuna gönderildi.',
        ]);
    }

    public function resetTemplate(Request $request, Response $response): Response
    {
        if (!$this->validateAdminAndCsrf($request)) {
            return $this->json($response, ['success' => false, 'message' => 'Yetkisiz veya geçersiz güvenlik tokenı.'], 403);
        }

        $settings = $this->telegramService->resetTemplates();
        $settings['csrf_token'] = $this->getOrCreateCsrfToken();
        $settings['event_labels'] = $this->telegramService->getEventLabels();

        return $this->json($response, [
            'success' => true,
            'message' => 'Varsayılan şablonlar geri yüklendi.',
            'data' => $settings,
        ]);
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
        $sessionToken = $_SESSION[self::CSRF_SESSION_KEY] ?? null;
        if (!is_string($sessionToken) || $sessionToken === '') {
            return false;
        }

        $body = $this->parseBody($request);
        $candidate = (string) ($body['_csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
        if ($candidate === '') {
            return false;
        }

        return hash_equals($sessionToken, $candidate);
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

