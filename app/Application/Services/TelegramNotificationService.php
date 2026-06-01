<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\EmailOrder;
use Doctrine\ORM\EntityManagerInterface;

final class TelegramNotificationService
{
    private const SETTINGS_TABLE = 'telegram_notification_settings';
    private const LOGS_TABLE = 'telegram_notification_logs';
    private const MAX_MESSAGE_LENGTH = 4096;

    public const EVENT_PENDING_APPROVAL = 'pending_approval';
    public const EVENT_APPROVED = 'approved';
    public const EVENT_QUEUED = 'queued';
    public const EVENT_PROCESSING = 'processing';
    public const EVENT_COMPLETED = 'completed';
    public const EVENT_FAILED = 'failed';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_PAUSED = 'paused';
    public const EVENT_RESTARTED = 'restarted';
    public const EVENT_WORKER_ERROR = 'worker_error';
    public const EVENT_BALANCE_INSUFFICIENT = 'balance_insufficient';
    public const EVENT_HIGH_ERROR_RATE = 'high_error_rate';
    public const EVENT_SMTP_THROTTLE_WARNING = 'smtp_throttle_warning';

    private const EVENT_LABELS = [
        self::EVENT_PENDING_APPROVAL => 'Onay bekliyor',
        self::EVENT_APPROVED => 'Onaylandı',
        self::EVENT_QUEUED => 'Kuyruğa alındı',
        self::EVENT_PROCESSING => 'İşleniyor',
        self::EVENT_COMPLETED => 'Tamamlandı',
        self::EVENT_FAILED => 'Başarısız oldu',
        self::EVENT_CANCELLED => 'İptal edildi',
        self::EVENT_PAUSED => 'Duraklatıldı',
        self::EVENT_RESTARTED => 'Tekrar başlatıldı',
        self::EVENT_WORKER_ERROR => 'Worker hatası',
        self::EVENT_BALANCE_INSUFFICIENT => 'Bakiye yetersiz',
        self::EVENT_HIGH_ERROR_RATE => 'Yüksek hata oranı',
        self::EVENT_SMTP_THROTTLE_WARNING => 'SMTP limit / throttle uyarısı',
    ];

    private const STATUS_TO_EVENT = [
        'pending_approval' => self::EVENT_PENDING_APPROVAL,
        'pending' => self::EVENT_QUEUED,
        'processing' => self::EVENT_PROCESSING,
        'completed' => self::EVENT_COMPLETED,
        'failed' => self::EVENT_FAILED,
        'cancelled' => self::EVENT_CANCELLED,
    ];

    private const DEFAULT_TEMPLATES = [
        self::EVENT_PENDING_APPROVAL => "📩 Yeni mail gönderimi onay bekliyor.\n\n🆔 Sipariş: #{order_id}\n👤 Kullanıcı: {user_name} ({user_email})\n📝 Kampanya: {campaign_subject}\n📄 Şablon: {template_name}\n📦 Gönderim adedi: {send_count}\n📚 Veri listesi: {data_pool_name}\n\nDurum: {status}",
        self::EVENT_APPROVED => "✅ Mail gönderimi onaylandı ve worker kuyruğuna alındı.\n\n🆔 Sipariş: #{order_id}\n👤 Kullanıcı: {user_name}\n📝 Kampanya: {campaign_subject}\n📦 Gönderim adedi: {send_count}\n📚 Veri listesi: {data_pool_name}\n💰 Kalan bakiye: {remaining_balance}",
        self::EVENT_QUEUED => "✅ Mail gönderimi onaylandı ve worker kuyruğuna alındı.\n\n🆔 Sipariş: #{order_id}\n👤 Kullanıcı: {user_name}\n📝 Kampanya: {campaign_subject}\n📦 Gönderim adedi: {send_count}\n📚 Veri listesi: {data_pool_name}\n💰 Kalan bakiye: {remaining_balance}",
        self::EVENT_PROCESSING => "🚀 Mail gönderimi başladı.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n📦 Toplam gönderim: {send_count}\n⚙️ Worker: {worker_name}\n⏱ Başlangıç: {started_at}",
        self::EVENT_COMPLETED => "🎉 Mail gönderimi tamamlandı.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n\n📦 Toplam: {send_count}\n✅ Başarılı: {success_count}\n❌ Hatalı: {failed_count}\n⚠️ Bounce: {bounce_count}\n🚫 Invalid: {invalid_count}\n\n⏱ Bitiş: {completed_at}",
        self::EVENT_FAILED => "❌ Mail gönderimi başarısız oldu.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n📦 Gönderim adedi: {send_count}\n\nHata:\n{error_message}",
        self::EVENT_CANCELLED => "🛑 Mail gönderimi iptal edildi.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n👤 Kullanıcı: {user_name}\nDurum: {status}",
        self::EVENT_PAUSED => "⏸️ Mail gönderimi duraklatıldı.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n⚙️ Worker: {worker_name}\nDurum: {status}",
        self::EVENT_RESTARTED => "🔄 Mail gönderimi tekrar başlatıldı.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n📦 Gönderim adedi: {send_count}\nDurum: {status}",
        self::EVENT_WORKER_ERROR => "⚠️ Worker hata bildirdi.\n\n🆔 Sipariş: #{order_id}\n⚙️ Worker: {worker_name}\nDurum: {status}\n\nHata:\n{error_message}",
        self::EVENT_BALANCE_INSUFFICIENT => "⚠️ Bakiye yetersiz olduğu için gönderim başlatılamadı.\n\n🆔 Sipariş: #{order_id}\n👤 Kullanıcı: {user_name} ({user_email})\n📦 Gerekli bakiye: {send_count}\n💰 Kalan bakiye: {remaining_balance}",
        self::EVENT_HIGH_ERROR_RATE => "⚠️ Gönderim sırasında çok fazla hata oluştu.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n❌ Hata: {failed_count}\n📦 Toplam: {send_count}\nDurum: {status}",
        self::EVENT_SMTP_THROTTLE_WARNING => "⚠️ SMTP limit / throttle uyarısı alındı.\n\n🆔 Sipariş: #{order_id}\n📝 Kampanya: {campaign_subject}\n⚙️ Worker: {worker_name}\nHata:\n{error_message}",
    ];

    private string $encryptionKey;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->encryptionKey = (string) ($_ENV['APP_SECRET_KEY'] ?? getenv('APP_SECRET_KEY') ?: 'nexus-mail-panel-secret-key-2024');
    }

    public function getEventLabels(): array
    {
        return self::EVENT_LABELS;
    }

    public function getDefaultTemplates(): array
    {
        return self::DEFAULT_TEMPLATES;
    }

    public function getSettingsForAdmin(): array
    {
        $row = $this->ensureSettingsRow();
        $events = $this->decodeJsonMap($row['events'] ?? null, $this->defaultEvents());
        $templates = $this->decodeJsonMap($row['templates'] ?? null, self::DEFAULT_TEMPLATES);

        return [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'chat_id' => (string) ($row['chat_id'] ?? ''),
            'events' => $this->mergeEventsWithDefaults($events),
            'templates' => $this->mergeTemplatesWithDefaults($templates),
            'has_bot_token' => !empty($row['bot_token']),
            'masked_bot_token' => $this->maskToken($this->decryptToken((string) ($row['bot_token'] ?? ''))),
            'last_test_at' => $row['last_test_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
        ];
    }

    public function saveSettings(array $payload): array
    {
        $conn = $this->em->getConnection();
        $row = $this->ensureSettingsRow();
        $id = (int) ($row['id'] ?? 1);

        $enabled = !empty($payload['enabled']) ? 1 : 0;
        $chatId = trim((string) ($payload['chat_id'] ?? ''));
        $events = $this->normalizeEventSelection($payload['events'] ?? []);
        $templates = $this->normalizeTemplates($payload['templates'] ?? []);

        $encryptedToken = (string) ($row['bot_token'] ?? '');
        $newToken = trim((string) ($payload['bot_token'] ?? ''));
        if ($newToken !== '') {
            $encryptedToken = $this->encryptToken($newToken);
        }

        $conn->update(
            self::SETTINGS_TABLE,
            [
                'enabled' => $enabled,
                'chat_id' => $chatId,
                'bot_token' => $encryptedToken,
                'events' => json_encode($events, JSON_UNESCAPED_UNICODE),
                'templates' => json_encode($templates, JSON_UNESCAPED_UNICODE),
                'updated_at' => $this->now(),
            ],
            ['id' => $id]
        );

        return $this->getSettingsForAdmin();
    }

    public function resetTemplates(): array
    {
        $conn = $this->em->getConnection();
        $row = $this->ensureSettingsRow();
        $id = (int) ($row['id'] ?? 1);

        $conn->update(
            self::SETTINGS_TABLE,
            [
                'templates' => json_encode(self::DEFAULT_TEMPLATES, JSON_UNESCAPED_UNICODE),
                'updated_at' => $this->now(),
            ],
            ['id' => $id]
        );

        return $this->getSettingsForAdmin();
    }

    public function sendTestMessage(?string $tokenOverride = null, ?string $chatIdOverride = null): array
    {
        $settings = $this->getSettingsRuntime();
        $token = trim((string) ($tokenOverride ?? $settings['bot_token'] ?? ''));
        $chatId = trim((string) ($chatIdOverride ?? $settings['chat_id'] ?? ''));

        if ($token === '' || $chatId === '') {
            return ['success' => false, 'message' => 'Bot token ve Chat ID zorunludur.'];
        }

        $result = $this->sendTelegramRequest($token, $chatId, '✅ Nexus Mail Telegram bildirimi başarıyla çalışıyor.');
        $this->updateLastTestResult($result['success'], $result['message']);

        return $result;
    }

    public function notifyEmailOrderStatusChanged(EmailOrder $order, string $status, array $context = []): bool
    {
        $event = self::STATUS_TO_EVENT[strtolower(trim($status))] ?? null;
        if ($event === null) {
            return false;
        }

        return $this->notifyEvent($event, $order, $context);
    }

    public function notifyEvent(string $eventType, ?EmailOrder $order = null, array $context = []): bool
    {
        $eventType = strtolower(trim($eventType));
        $settings = $this->getSettingsRuntime();
        if (!(bool) ($settings['enabled'] ?? false)) {
            return false;
        }

        $events = $settings['events'];
        if (empty($events[$eventType])) {
            return false;
        }

        $orderId = $order?->getId();
        $status = $context['status'] ?? $order?->getStatus()?->value ?? null;
        if ($this->wasAlreadySent($eventType, $orderId, $status)) {
            return false;
        }

        $template = (string) ($settings['templates'][$eventType] ?? self::DEFAULT_TEMPLATES[$eventType] ?? '');
        if ($template === '') {
            $template = (string) (self::EVENT_LABELS[$eventType] ?? 'Telegram Bildirimi');
        }

        $variables = $this->buildTemplateVariables($order, $context);
        $message = $this->renderTemplate($template, $variables);
        $result = $this->sendTelegramRequest(
            (string) $settings['bot_token'],
            (string) $settings['chat_id'],
            $message
        );

        $this->logNotificationAttempt($eventType, $orderId, $status, $result, $variables['error_message'] ?? null);
        if (!$result['success']) {
            $this->updateLastError($result['message']);
        }

        return $result['success'];
    }

    private function getSettingsRuntime(): array
    {
        $row = $this->ensureSettingsRow();
        $token = $this->decryptToken((string) ($row['bot_token'] ?? ''));

        return [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'chat_id' => trim((string) ($row['chat_id'] ?? '')),
            'bot_token' => $token,
            'events' => $this->mergeEventsWithDefaults($this->decodeJsonMap($row['events'] ?? null, $this->defaultEvents())),
            'templates' => $this->mergeTemplatesWithDefaults($this->decodeJsonMap($row['templates'] ?? null, self::DEFAULT_TEMPLATES)),
        ];
    }

    private function ensureSettingsRow(): array
    {
        $conn = $this->em->getConnection();
        $row = $conn->fetchAssociative('SELECT * FROM ' . self::SETTINGS_TABLE . ' ORDER BY id ASC LIMIT 1');
        if (is_array($row) && !empty($row)) {
            return $row;
        }

        $conn->insert(self::SETTINGS_TABLE, [
            'enabled' => 0,
            'bot_token' => null,
            'chat_id' => null,
            'events' => json_encode($this->defaultEvents(), JSON_UNESCAPED_UNICODE),
            'templates' => json_encode(self::DEFAULT_TEMPLATES, JSON_UNESCAPED_UNICODE),
            'last_test_at' => null,
            'last_error' => null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return $conn->fetchAssociative('SELECT * FROM ' . self::SETTINGS_TABLE . ' ORDER BY id ASC LIMIT 1') ?: [];
    }

    private function sendTelegramRequest(string $token, string $chatId, string $message): array
    {
        $token = trim($token);
        $chatId = trim($chatId);
        if ($token === '' || $chatId === '') {
            return ['success' => false, 'message' => 'Telegram bot token veya chat ID eksik.'];
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $payload = [
            'chat_id' => $chatId,
            'text' => $this->truncateMessage($message),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'message' => 'Telegram isteği başlatılamadı.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['success' => false, 'message' => 'Telegram bağlantı hatası: ' . $this->safeError($err)];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'message' => 'Telegram geçersiz yanıt döndü (HTTP ' . $status . ').'];
        }

        $ok = !empty($decoded['ok']);
        if (!$ok) {
            $desc = (string) ($decoded['description'] ?? 'Telegram API hatası');
            return ['success' => false, 'message' => $this->safeError($desc)];
        }

        return [
            'success' => true,
            'message' => 'Telegram mesajı gönderildi.',
            'telegram_message_id' => (string) ($decoded['result']['message_id'] ?? ''),
        ];
    }

    private function buildTemplateVariables(?EmailOrder $order, array $context): array
    {
        $status = (string) ($context['status'] ?? $order?->getStatus()?->value ?? '-');
        $orderTotal = (int) ($context['send_count'] ?? $order?->getTotal() ?? 0);
        $failedCount = (int) ($context['failed_count'] ?? $order?->getFailed() ?? 0);
        $deliveredCount = (int) ($context['success_count'] ?? $context['delivered_count'] ?? $order?->getDelivered() ?? 0);
        $bounceCount = (int) ($context['bounce_count'] ?? $order?->getBounced() ?? 0);
        $invalidCount = (int) ($context['invalid_count'] ?? 0);

        $values = [
            '{order_id}' => (string) ($order?->getId() ?? $context['order_id'] ?? '-'),
            '{campaign_subject}' => (string) ($context['campaign_subject'] ?? $order?->getSubject() ?? '-'),
            '{template_name}' => (string) ($context['template_name'] ?? $order?->getTemplate()?->getName() ?? '-'),
            '{user_name}' => (string) ($context['user_name'] ?? $order?->getUser()?->getName() ?? '-'),
            '{user_email}' => (string) ($context['user_email'] ?? $order?->getUser()?->getEmail() ?? '-'),
            '{send_count}' => (string) $orderTotal,
            '{success_count}' => (string) $deliveredCount,
            '{failed_count}' => (string) $failedCount,
            '{bounce_count}' => (string) $bounceCount,
            '{invalid_count}' => (string) $invalidCount,
            '{status}' => $status,
            '{data_pool_name}' => (string) ($context['data_pool_name'] ?? $order?->getPoolList()?->getName() ?? '-'),
            '{created_at}' => $this->formatDate($context['created_at'] ?? $order?->getCreatedAt() ?? null),
            '{started_at}' => $this->formatDate($context['started_at'] ?? null),
            '{completed_at}' => $this->formatDate($context['completed_at'] ?? $order?->getCompletedAt() ?? null),
            '{error_message}' => (string) ($context['error_message'] ?? '-'),
            '{worker_name}' => (string) ($context['worker_name'] ?? $context['worker_id'] ?? '-'),
            '{remaining_balance}' => (string) ($context['remaining_balance'] ?? '-'),
        ];

        $escaped = [];
        foreach ($values as $key => $value) {
            $escaped[$key] = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return $escaped;
    }

    private function renderTemplate(string $template, array $variables): string
    {
        return strtr($template, $variables);
    }

    private function wasAlreadySent(string $eventType, ?int $orderId, ?string $status): bool
    {
        if ($orderId === null || $orderId < 1) {
            return false;
        }

        $conn = $this->em->getConnection();
        $sent = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM ' . self::LOGS_TABLE . ' WHERE event_type = ? AND order_id = ? AND status = ? AND sent = 1',
            [$eventType, $orderId, (string) ($status ?? '')]
        );

        return $sent > 0;
    }

    private function logNotificationAttempt(string $eventType, ?int $orderId, ?string $status, array $result, ?string $errorMessage): void
    {
        $conn = $this->em->getConnection();
        $conn->insert(self::LOGS_TABLE, [
            'event_type' => $eventType,
            'order_id' => $orderId,
            'status' => (string) ($status ?? ''),
            'sent' => !empty($result['success']) ? 1 : 0,
            'error_message' => !empty($result['success']) ? null : (string) ($result['message'] ?? $errorMessage ?? ''),
            'telegram_message_id' => (string) ($result['telegram_message_id'] ?? ''),
            'created_at' => $this->now(),
        ]);
    }

    private function updateLastError(string $message): void
    {
        $row = $this->ensureSettingsRow();
        $this->em->getConnection()->update(
            self::SETTINGS_TABLE,
            [
                'last_error' => $message,
                'updated_at' => $this->now(),
            ],
            ['id' => (int) ($row['id'] ?? 1)]
        );
    }

    private function updateLastTestResult(bool $success, string $message): void
    {
        $row = $this->ensureSettingsRow();
        $this->em->getConnection()->update(
            self::SETTINGS_TABLE,
            [
                'last_test_at' => $this->now(),
                'last_error' => $success ? null : $message,
                'updated_at' => $this->now(),
            ],
            ['id' => (int) ($row['id'] ?? 1)]
        );
    }

    private function defaultEvents(): array
    {
        $events = [];
        foreach (array_keys(self::EVENT_LABELS) as $eventKey) {
            $events[$eventKey] = true;
        }
        return $events;
    }

    private function mergeEventsWithDefaults(array $events): array
    {
        return array_merge($this->defaultEvents(), $events);
    }

    private function mergeTemplatesWithDefaults(array $templates): array
    {
        return array_merge(self::DEFAULT_TEMPLATES, $templates);
    }

    private function normalizeEventSelection(mixed $eventsRaw): array
    {
        $normalized = [];
        $source = is_array($eventsRaw) ? $eventsRaw : [];
        foreach ($this->defaultEvents() as $eventKey => $defaultValue) {
            $normalized[$eventKey] = !empty($source[$eventKey]);
        }
        return $normalized;
    }

    private function normalizeTemplates(mixed $templatesRaw): array
    {
        $source = is_array($templatesRaw) ? $templatesRaw : [];
        $normalized = [];
        foreach (self::DEFAULT_TEMPLATES as $eventKey => $defaultTemplate) {
            $value = trim((string) ($source[$eventKey] ?? ''));
            $normalized[$eventKey] = $value !== '' ? $value : $defaultTemplate;
        }
        return $normalized;
    }

    private function decodeJsonMap(mixed $raw, array $fallback): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return $fallback;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $fallback;
        }

        return $decoded;
    }

    private function encryptToken(string $token): string
    {
        $iv = substr(hash('sha256', $this->encryptionKey), 0, 16);
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        if ($encrypted === false) {
            throw new \RuntimeException('Telegram token şifrelenemedi.');
        }
        return base64_encode($encrypted);
    }

    private function decryptToken(string $encryptedToken): string
    {
        if (trim($encryptedToken) === '') {
            return '';
        }

        try {
            $iv = substr(hash('sha256', $this->encryptionKey), 0, 16);
            $decoded = base64_decode($encryptedToken, true);
            if ($decoded === false) {
                return '';
            }
            $decrypted = openssl_decrypt($decoded, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
            return $decrypted === false ? '' : $decrypted;
        } catch (\Throwable) {
            return '';
        }
    }

    private function maskToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($token, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($token, -4);
    }

    private function truncateMessage(string $message): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($message, 'UTF-8') <= self::MAX_MESSAGE_LENGTH) {
                return $message;
            }
            return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 10, 'UTF-8') . "\n\n…";
        }

        if (strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return $message;
        }
        return substr($message, 0, self::MAX_MESSAGE_LENGTH - 10) . "\n\n...";
    }

    private function safeError(string $message): string
    {
        $safe = str_replace(["\n", "\r"], ' ', trim($message));
        return preg_replace('/\b(\d{6,}:[A-Za-z0-9_\-]{20,})\b/', '[MASKED_TOKEN]', $safe) ?: 'Bilinmeyen hata';
    }

    private function formatDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        return '-';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}

