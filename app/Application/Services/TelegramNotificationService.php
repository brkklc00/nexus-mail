<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Entities\EmailOrder;
use Doctrine\ORM\EntityManagerInterface;

final class TelegramNotificationService
{
    private const SETTINGS_TABLE = 'telegram_notification_settings';
    private const EVENTS_TABLE = 'telegram_notification_events';
    private const TEMPLATES_TABLE = 'telegram_notification_templates';
    private const LOGS_TABLE = 'telegram_notification_logs';
    private const MAX_MESSAGE_LENGTH = 4096;

    public const EVENT_PENDING_APPROVAL = 'campaign_created';
    public const EVENT_APPROVED = 'campaign_approved';
    public const EVENT_QUEUED = 'campaign_queued';
    public const EVENT_PROCESSING = 'campaign_started';
    public const EVENT_PROGRESS = 'campaign_progress';
    public const EVENT_COMPLETED = 'campaign_completed';
    public const EVENT_FAILED = 'campaign_failed';
    public const EVENT_PAUSED = 'campaign_paused';
    public const EVENT_CANCELLED = 'campaign_cancelled';
    public const EVENT_RESTARTED = 'campaign_restarted';
    public const EVENT_WORKER_STARTED = 'worker_started';
    public const EVENT_WORKER_STOPPED = 'worker_stopped';
    public const EVENT_WORKER_ERROR = 'worker_error';
    public const EVENT_BALANCE_INSUFFICIENT = 'balance_empty';
    public const EVENT_BALANCE_LOW = 'balance_low';
    public const EVENT_HIGH_ERROR_RATE = 'smtp_bounce_high';
    public const EVENT_SMTP_THROTTLE_WARNING = 'daily_limit_warning';
    public const EVENT_DAILY_LIMIT_REACHED = 'daily_limit_reached';
    public const EVENT_SMTP_ERROR = 'smtp_error';
    public const EVENT_SYSTEM_ERROR = 'system_error';
    public const EVENT_API_ERROR = 'api_error';

    /** Sipariş başına en fazla bir kez (campaign_started claim tarafında yönetilir) */
    private const ONCE_PER_ORDER_EVENTS = [
        'campaign_completed',
        'campaign_failed',
        'campaign_queued',
        'campaign_cancelled',
    ];

    private const STATUS_TO_EVENT = [
        'pending_approval' => self::EVENT_PENDING_APPROVAL,
        'pending' => self::EVENT_QUEUED,
        'processing' => self::EVENT_PROCESSING,
        'completed' => self::EVENT_COMPLETED,
        'failed' => self::EVENT_FAILED,
        'cancelled' => self::EVENT_CANCELLED,
    ];

    private const EVENTS = [
        'campaign_created' => ['label' => 'Kampanya oluşturuldu', 'desc' => 'Sipariş oluşturuldu', 'tpl' => 'campaign_created', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'campaign_approved' => ['label' => 'Kampanya onaylandı', 'desc' => 'Onay başarılı', 'tpl' => 'campaign_queued', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'campaign_queued' => ['label' => 'Kuyruğa alındı', 'desc' => 'Worker kuyruğu', 'tpl' => 'campaign_queued', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'campaign_started' => ['label' => 'Gönderim başladı', 'desc' => 'Worker başladı', 'tpl' => 'campaign_started', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'campaign_progress' => ['label' => 'Gönderim ilerleme', 'desc' => 'İlerleme bildirimi', 'tpl' => 'campaign_progress', 'enabled' => 0, 'throttle' => 10, 'only_error' => 0],
        'campaign_completed' => ['label' => 'Gönderim tamamlandı', 'desc' => 'Kampanya bitti', 'tpl' => 'campaign_completed', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'campaign_failed' => ['label' => 'Gönderim hata aldı', 'desc' => 'Failed status', 'tpl' => 'campaign_failed', 'enabled' => 1, 'throttle' => 0, 'only_error' => 1],
        'campaign_paused' => ['label' => 'Gönderim duraklatıldı', 'desc' => 'Pause/stop', 'tpl' => 'campaign_paused', 'enabled' => 1, 'throttle' => 1, 'only_error' => 0],
        'campaign_cancelled' => ['label' => 'Gönderim iptal edildi', 'desc' => 'Cancel', 'tpl' => 'campaign_cancelled', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'campaign_restarted' => ['label' => 'Gönderim tekrar başlatıldı', 'desc' => 'Restart', 'tpl' => 'campaign_started', 'enabled' => 1, 'throttle' => 0, 'only_error' => 0],
        'worker_started' => ['label' => 'Worker başladı', 'desc' => 'Claim alındı', 'tpl' => 'worker_started', 'enabled' => 0, 'throttle' => 1, 'only_error' => 0],
        'worker_stopped' => ['label' => 'Worker durdu', 'desc' => 'Worker stop', 'tpl' => 'worker_stopped', 'enabled' => 0, 'throttle' => 1, 'only_error' => 0],
        'worker_error' => ['label' => 'Worker hata verdi', 'desc' => 'Worker error', 'tpl' => 'worker_error', 'enabled' => 1, 'throttle' => 1, 'only_error' => 1],
        'balance_low' => ['label' => 'Bakiye azaldı', 'desc' => 'Düşük bakiye', 'tpl' => 'balance_low', 'enabled' => 1, 'throttle' => 10, 'only_error' => 0],
        'balance_empty' => ['label' => 'Bakiye bitti', 'desc' => 'Bakiye yok', 'tpl' => 'balance_empty', 'enabled' => 1, 'throttle' => 5, 'only_error' => 0],
        'daily_limit_warning' => ['label' => 'Günlük limit uyarısı', 'desc' => 'Limit yaklaşımı', 'tpl' => 'daily_limit_warning', 'enabled' => 0, 'throttle' => 10, 'only_error' => 1],
        'daily_limit_reached' => ['label' => 'Günlük limit doldu', 'desc' => 'Limit doldu', 'tpl' => 'daily_limit_reached', 'enabled' => 1, 'throttle' => 10, 'only_error' => 1],
        'smtp_error' => ['label' => 'SMTP hata verdi', 'desc' => 'SMTP hatası', 'tpl' => 'smtp_error', 'enabled' => 1, 'throttle' => 1, 'only_error' => 1],
        'smtp_daily_limit' => ['label' => 'SMTP günlük limit', 'desc' => 'SMTP limit doldu', 'tpl' => 'daily_limit_reached', 'enabled' => 0, 'throttle' => 10, 'only_error' => 1],
        'smtp_bounce_high' => ['label' => 'Bounce oranı yüksek', 'desc' => 'Hata oranı yükseldi', 'tpl' => 'campaign_failed', 'enabled' => 0, 'throttle' => 10, 'only_error' => 1],
        'system_error' => ['label' => 'Sistem hatası', 'desc' => 'Genel sistem', 'tpl' => 'system_error', 'enabled' => 1, 'throttle' => 2, 'only_error' => 1],
        'api_error' => ['label' => 'API hatası', 'desc' => 'Harici API', 'tpl' => 'api_error', 'enabled' => 1, 'throttle' => 2, 'only_error' => 1],
    ];

    private const DEFAULT_TEMPLATES = [
        'campaign_created' => ['title' => 'Kampanya Oluşturuldu', 'body' => "📌 Yeni Kampanya Oluşturuldu\n\n🆔 Sipariş ID: {order_id}\n📣 Konu: {campaign_subject}\n👤 Kullanıcı: {user_name}\n📧 Email: {user_email}\n📦 Liste: {data_pool_name}\n🕒 Tarih: {created_at}", 'parse_mode' => 'HTML'],
        'campaign_queued' => ['title' => 'Kuyruğa Alındı', 'body' => "✅ Mail gönderimi onaylandı ve worker kuyruğuna alındı.\n\n🆔 Sipariş: #{order_id}\n👤 Kullanıcı: {user_name}\n📝 Kampanya: {campaign_subject}\n📦 Gönderim adedi: {send_count}\n📚 Veri listesi: {data_pool_name}\n💰 Kalan bakiye: {remaining_balance}", 'parse_mode' => 'HTML'],
        'campaign_started' => ['title' => 'Gönderim Başladı', 'body' => "🚀 Mail Gönderimi Başladı\n\n🆔 Sipariş ID: {order_id}\n📣 Konu: {campaign_subject}\n📦 Liste: {data_pool_name}\n📨 Gönderilecek: {send_count}\n⚙️ Worker: {worker_name}\n🕒 Başlangıç: {started_at}", 'parse_mode' => 'HTML'],
        'campaign_progress' => ['title' => 'Gönderim İlerleme', 'body' => "📊 Gönderim Devam Ediyor\n\n🆔 Sipariş ID: {order_id}\n📣 Konu: {campaign_subject}\n\n📨 Gönderilen: {sent_count}/{send_count}\n✅ Başarılı: {success_count}\n❌ Hatalı: {failed_count}\n📈 İlerleme: %{progress_percent}\n\n⚙️ Worker: {worker_name}", 'parse_mode' => 'HTML'],
        'campaign_completed' => ['title' => 'Gönderim Tamamlandı', 'body' => "✅ Mail Gönderimi Tamamlandı\n\n🆔 Sipariş ID: {order_id}\n📣 Konu: {campaign_subject}\n\n📨 Toplam: {send_count}\n✅ Başarılı: {success_count}\n❌ Hatalı: {failed_count}\n↩️ Bounce: {bounce_count}\n🚫 Invalid: {invalid_count}\n\n💰 Kalan Bakiye: {remaining_balance}\n🕒 Tamamlanma: {completed_at}", 'parse_mode' => 'HTML'],
        'campaign_failed' => ['title' => 'Gönderim Hata Aldı', 'body' => "🚨 Mail Gönderimi Hata Aldı\n\n🆔 Sipariş ID: {order_id}\n📣 Konu: {campaign_subject}\n📌 Durum: {status}\n\n❌ Hata:\n{error_message}\n\n⚙️ Worker: {worker_name}\n🕒 Tarih: {completed_at}", 'parse_mode' => 'HTML'],
        'campaign_paused' => ['title' => 'Gönderim Duraklatıldı', 'body' => "⏸️ Gönderim duraklatıldı.\n\n🆔 Sipariş: {order_id}\n📣 Konu: {campaign_subject}\n⚙️ Worker: {worker_name}\nDurum: {status}", 'parse_mode' => 'HTML'],
        'campaign_cancelled' => ['title' => 'Gönderim İptal Edildi', 'body' => "🛑 Gönderim iptal edildi.\n\n🆔 Sipariş: {order_id}\n📣 Konu: {campaign_subject}\n👤 Kullanıcı: {user_name}\nDurum: {status}", 'parse_mode' => 'HTML'],
        'worker_started' => ['title' => 'Worker Başladı', 'body' => "▶️ Worker kampanya işlemini başlattı.\n\n⚙️ Worker: {worker_name}\n🆔 Sipariş: {order_id}\n🕒 Tarih: {started_at}", 'parse_mode' => 'HTML'],
        'worker_stopped' => ['title' => 'Worker Durdu', 'body' => "⏹️ Worker durdu.\n\n⚙️ Worker: {worker_name}\n🆔 Sipariş: {order_id}\n🕒 Tarih: {completed_at}", 'parse_mode' => 'HTML'],
        'worker_error' => ['title' => 'Worker Hatası', 'body' => "🚨 Worker Hatası\n\n⚙️ Worker: {worker_name}\n❌ Hata:\n{error_message}\n\n🕒 Tarih: {created_at}", 'parse_mode' => 'HTML'],
        'balance_low' => ['title' => 'Bakiye Azaldı', 'body' => "⚠️ Gönderim Bakiyesi Azaldı\n\n👤 Kullanıcı: {user_name}\n📧 Email: {user_email}\n💰 Kalan Bakiye: {remaining_balance}\n\nLütfen bakiye kontrolü yapın.", 'parse_mode' => 'HTML'],
        'balance_empty' => ['title' => 'Bakiye Bitti', 'body' => "⛔ Gönderim Bakiyesi Bitti\n\n👤 Kullanıcı: {user_name}\n📧 Email: {user_email}\n💰 Kalan Bakiye: {remaining_balance}\n\nGönderimler durabilir.", 'parse_mode' => 'HTML'],
        'smtp_error' => ['title' => 'SMTP Hatası', 'body' => "⚠️ SMTP Hatası\n\n📮 SMTP: {smtp_name}\n📧 SMTP Email: {smtp_email}\n❌ Hata:\n{error_message}\n\n🕒 Tarih: {created_at}", 'parse_mode' => 'HTML'],
        'daily_limit_warning' => ['title' => 'Günlük Limit Uyarısı', 'body' => "⚠️ Günlük limit dolmak üzere.\n\n📮 SMTP: {smtp_name}\n📊 Kullanım: {used_limit}/{daily_limit}", 'parse_mode' => 'HTML'],
        'daily_limit_reached' => ['title' => 'Günlük Limit Doldu', 'body' => "⛔ Günlük Limit Doldu\n\n📮 SMTP: {smtp_name}\n📊 Kullanım: {used_limit}/{daily_limit}\n\nBu SMTP bugün daha fazla gönderim yapamaz.", 'parse_mode' => 'HTML'],
        'system_error' => ['title' => 'Sistem Hatası', 'body' => "⚠️ Sistem hatası algılandı.\n\n❌ Hata:\n{error_message}\n\n🕒 Tarih: {created_at}", 'parse_mode' => 'HTML'],
        'api_error' => ['title' => 'API Hatası', 'body' => "⚠️ API hatası oluştu.\n\n❌ Hata:\n{error_message}\n\n🕒 Tarih: {created_at}", 'parse_mode' => 'HTML'],
    ];

    private string $key;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->key = (string) ($_ENV['APP_SECRET_KEY'] ?? getenv('APP_SECRET_KEY') ?: 'nexus-mail-panel-secret-key-2024');
    }

    public function getSettingsForAdmin(): array
    {
        $this->ensureRows();
        $row = $this->ensureSettingsRow();
        return [
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'chat_id' => (string) ($row['chat_id'] ?? ''),
            'parse_mode' => $this->normalizeParseMode((string) ($row['parse_mode'] ?? 'HTML')),
            'has_bot_token' => trim((string) ($row['bot_token'] ?? '')) !== '',
            'masked_bot_token' => $this->maskToken($this->decrypt((string) ($row['bot_token'] ?? ''))),
            'last_test_at' => $row['last_test_at'] ?? null,
            'last_error' => (string) ($row['last_error'] ?? ''),
            'last_test_status' => trim((string) ($row['last_error'] ?? '')) === '' ? 'success' : 'failed',
            'events' => $this->getEventsForAdmin(),
            'templates' => $this->getTemplatesForAdmin(),
            'event_labels' => $this->getEventLabels(),
            'sample_variables' => $this->sampleVars(),
        ];
    }

    public function saveSettings(array $p): array
    {
        $row = $this->ensureSettingsRow();
        $enabled = !empty($p['enabled']) ? 1 : 0;
        $chatId = trim((string) ($p['chat_id'] ?? ''));
        $tokenEncrypted = (string) ($row['bot_token'] ?? '');
        $newToken = trim((string) ($p['bot_token'] ?? ''));
        if ($newToken !== '') {
            $tokenEncrypted = $this->encrypt($newToken);
        }
        if ($enabled === 1) {
            if ($tokenEncrypted === '') {
                throw new \InvalidArgumentException('Bildirimler aktifken bot token zorunludur.');
            }
            if (!$this->isValidChatId($chatId)) {
                throw new \InvalidArgumentException('Telegram Chat ID geçersiz. Örn: -4808406081');
            }
        } elseif ($chatId !== '' && !$this->isValidChatId($chatId)) {
            throw new \InvalidArgumentException('Telegram Chat ID numerik olmalıdır.');
        }

        $this->em->getConnection()->update(self::SETTINGS_TABLE, [
            'enabled' => $enabled,
            'chat_id' => $chatId,
            'parse_mode' => $this->normalizeParseMode((string) ($p['parse_mode'] ?? ($row['parse_mode'] ?? 'HTML'))),
            'bot_token' => $tokenEncrypted,
            'updated_at' => $this->now(),
        ], ['id' => (int) ($row['id'] ?? 1)]);

        return $this->getSettingsForAdmin();
    }

    public function getEventsForAdmin(): array
    {
        $this->ensureRows();
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id,event_key,label,description,enabled,template_key,throttle_minutes,only_on_error,last_sent_at
             FROM ' . self::EVENTS_TABLE . ' ORDER BY id ASC'
        );
        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'event_key' => (string) ($row['event_key'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
                'template_key' => (string) ($row['template_key'] ?? ''),
                'throttle_minutes' => (int) ($row['throttle_minutes'] ?? 0),
                'only_on_error' => (int) ($row['only_on_error'] ?? 0) === 1,
                'last_sent_at' => $row['last_sent_at'] ?? null,
            ];
        }, $rows);
    }

    public function saveEvents(array $payload): array
    {
        $rows = is_array($payload['events'] ?? null) ? $payload['events'] : $payload;
        if (!is_array($rows)) {
            throw new \InvalidArgumentException('Event payload geçersiz.');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = strtolower(trim((string) ($row['event_key'] ?? '')));
            if ($key === '') continue;
            $this->em->getConnection()->update(self::EVENTS_TABLE, [
                'enabled' => !empty($row['enabled']) ? 1 : 0,
                'template_key' => trim((string) ($row['template_key'] ?? (self::EVENTS[$key]['tpl'] ?? $key))),
                'throttle_minutes' => max(0, (int) ($row['throttle_minutes'] ?? 0)),
                'only_on_error' => !empty($row['only_on_error']) ? 1 : 0,
                'updated_at' => $this->now(),
            ], ['event_key' => $key]);
        }
        return $this->getEventsForAdmin();
    }

    public function getTemplatesForAdmin(): array
    {
        $this->ensureRows();
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id,template_key,event_key,title,body,parse_mode,enabled,is_default
             FROM ' . self::TEMPLATES_TABLE . ' ORDER BY id ASC'
        );
        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'template_key' => (string) ($row['template_key'] ?? ''),
                'event_key' => (string) ($row['event_key'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'body' => (string) ($row['body'] ?? ''),
                'parse_mode' => strtoupper((string) ($row['parse_mode'] ?? 'HTML')),
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
                'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            ];
        }, $rows);
    }

    public function saveTemplates(array $payload): array
    {
        $rows = is_array($payload['templates'] ?? null) ? $payload['templates'] : $payload;
        if (!is_array($rows)) throw new \InvalidArgumentException('Template payload geçersiz.');
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $key = strtolower(trim((string) ($row['template_key'] ?? '')));
            $body = trim((string) ($row['body'] ?? ''));
            if ($key === '') throw new \InvalidArgumentException('Template key boş olamaz.');
            if ($body === '') throw new \InvalidArgumentException('Şablon metni boş olamaz: ' . $key);
            if (isset($seen[$key])) throw new \InvalidArgumentException('Template key tekrar ediyor: ' . $key);
            $seen[$key] = true;
            $this->em->getConnection()->update(self::TEMPLATES_TABLE, [
                'event_key' => strtolower(trim((string) ($row['event_key'] ?? $key))),
                'title' => trim((string) ($row['title'] ?? $key)),
                'body' => $body,
                'parse_mode' => $this->normalizeParseMode((string) ($row['parse_mode'] ?? 'HTML')),
                'enabled' => !empty($row['enabled']) ? 1 : 0,
                'updated_at' => $this->now(),
            ], ['template_key' => $key]);
        }
        return $this->getTemplatesForAdmin();
    }

    public function updateTemplateById(int $id, array $payload): array
    {
        if ($id < 1) throw new \InvalidArgumentException('Template ID geçersiz.');
        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM ' . self::TEMPLATES_TABLE . ' WHERE id = ? LIMIT 1', [$id]);
        if (!$row) throw new \InvalidArgumentException('Template bulunamadı.');
        $body = trim((string) ($payload['body'] ?? $row['body'] ?? ''));
        if ($body === '') throw new \InvalidArgumentException('Şablon metni boş olamaz.');
        $this->em->getConnection()->update(self::TEMPLATES_TABLE, [
            'title' => trim((string) ($payload['title'] ?? $row['title'] ?? '')),
            'body' => $body,
            'parse_mode' => $this->normalizeParseMode((string) ($payload['parse_mode'] ?? $row['parse_mode'] ?? 'HTML')),
            'enabled' => !empty($payload['enabled']) ? 1 : 0,
            'updated_at' => $this->now(),
        ], ['id' => $id]);
        return $this->em->getConnection()->fetchAssociative('SELECT id,template_key,event_key,title,body,parse_mode,enabled,is_default FROM ' . self::TEMPLATES_TABLE . ' WHERE id = ? LIMIT 1', [$id]) ?: [];
    }

    public function loadDefaults(): array
    {
        $this->ensureRows();
        foreach (self::DEFAULT_TEMPLATES as $key => $tpl) {
            $this->em->getConnection()->update(self::TEMPLATES_TABLE, [
                'title' => $tpl['title'],
                'body' => $tpl['body'],
                'parse_mode' => $tpl['parse_mode'],
                'enabled' => 1,
                'is_default' => 1,
                'updated_at' => $this->now(),
            ], ['template_key' => $key]);
        }
        foreach (self::EVENTS as $key => $e) {
            $this->em->getConnection()->update(self::EVENTS_TABLE, [
                'template_key' => $e['tpl'],
                'enabled' => $e['enabled'],
                'throttle_minutes' => $e['throttle'],
                'only_on_error' => $e['only_error'],
                'updated_at' => $this->now(),
            ], ['event_key' => $key]);
        }
        return $this->getSettingsForAdmin();
    }

    public function sendTestMessage(?string $tokenOverride = null, ?string $chatIdOverride = null, ?string $text = null): array
    {
        $s = $this->runtimeSettings();
        $token = trim((string) ($tokenOverride ?? $s['bot_token'] ?? ''));
        $chatId = trim((string) ($chatIdOverride ?? $s['chat_id'] ?? ''));
        $msg = trim((string) ($text ?? '✅ Nexus Mail Telegram bildirimi başarıyla çalışıyor.'));
        if ($token === '' || $chatId === '') return ['success' => false, 'message' => 'Bot token ve Chat ID zorunludur.'];
        if (!$this->isValidChatId($chatId)) return ['success' => false, 'message' => 'Telegram Chat ID geçersiz.'];
        $r = $this->sendTelegramRequest($token, $chatId, $msg, (string) ($s['parse_mode'] ?? 'HTML'));
        $settingsRow = $this->ensureSettingsRow();
        $this->em->getConnection()->update(self::SETTINGS_TABLE, ['last_test_at' => $this->now(), 'last_error' => $r['success'] ? null : (string) ($r['message'] ?? ''), 'updated_at' => $this->now()], ['id' => (int) ($settingsRow['id'] ?? 1)]);
        $this->insertLog('test_message', null, '', '', $chatId, [], $msg, $r);
        return $r;
    }

    public function sendTemplateTest(string $templateKey, array $sampleVars = []): array
    {
        $s = $this->runtimeSettings();
        if (trim((string) ($s['bot_token'] ?? '')) === '' || trim((string) ($s['chat_id'] ?? '')) === '') return ['success' => false, 'message' => 'Önce bot token ve chat ID kaydedin.'];
        $tpl = $this->em->getConnection()->fetchAssociative('SELECT * FROM ' . self::TEMPLATES_TABLE . ' WHERE template_key = ? LIMIT 1', [strtolower(trim($templateKey))]);
        if (!$tpl) return ['success' => false, 'message' => 'Şablon bulunamadı.'];
        $vars = array_merge($this->sampleVars(), $sampleVars);
        $body = $this->renderTemplate((string) ($tpl['body'] ?? ''), $vars, (string) ($tpl['parse_mode'] ?? 'HTML'));
        $r = $this->sendTelegramRequest((string) $s['bot_token'], (string) $s['chat_id'], $body, (string) ($tpl['parse_mode'] ?? 'HTML'));
        $this->insertLog('test_template', null, '', (string) ($tpl['template_key'] ?? ''), (string) $s['chat_id'], $vars, $body, $r);
        return $r;
    }

    public function getLogs(int $limit = 100): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT id,event_key,template_key,chat_id,status,error_message,telegram_message_id,created_at FROM ' . self::LOGS_TABLE . ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)));
    }

    public function notifyEmailOrderStatusChanged(EmailOrder $order, string $status, array $context = []): bool
    {
        $event = self::STATUS_TO_EVENT[strtolower(trim($status))] ?? null;
        return $event ? $this->notifyEvent($event, $order, $context) : false;
    }

    public function notifyEvent(string $eventKey, ?EmailOrder $order = null, array $context = []): bool
    {
        try {
            $this->ensureRows();
            $s = $this->runtimeSettings();
            if (!$s['enabled'] || trim((string) $s['bot_token']) === '' || trim((string) $s['chat_id']) === '') return false;
            $eventKey = strtolower(trim($eventKey));
            $e = $this->em->getConnection()->fetchAssociative('SELECT * FROM ' . self::EVENTS_TABLE . ' WHERE event_key = ? LIMIT 1', [$eventKey]);
            if (!$e || (int) ($e['enabled'] ?? 0) !== 1) return false;
            if ((int) ($e['only_on_error'] ?? 0) === 1 && trim((string) ($context['error_message'] ?? '')) === '') return false;
            $orderId = $order?->getId() ?: (isset($context['order_id']) ? (int) $context['order_id'] : null);
            $status = (string) ($context['status'] ?? $order?->getStatus()?->value ?? '');
            if ($orderId && $orderId > 0 && in_array($eventKey, self::ONCE_PER_ORDER_EVENTS, true) && $this->hasOrderEventBeenSent($eventKey, $orderId)) {
                return false;
            }
            if ($this->isThrottled((int) ($e['throttle_minutes'] ?? 0), $eventKey, $orderId, $status)) return false;
            $tplKey = (string) ($e['template_key'] ?? $eventKey);
            $tpl = $this->em->getConnection()->fetchAssociative('SELECT * FROM ' . self::TEMPLATES_TABLE . ' WHERE template_key = ? LIMIT 1', [$tplKey]);
            if (!$tpl || (int) ($tpl['enabled'] ?? 0) !== 1) return false;
            $vars = $this->vars($order, $context);
            $msg = $this->renderTemplate((string) ($tpl['body'] ?? ''), $vars, (string) ($tpl['parse_mode'] ?? 'HTML'));
            $r = $this->sendTelegramRequest((string) $s['bot_token'], (string) $s['chat_id'], $msg, (string) ($tpl['parse_mode'] ?? 'HTML'));
            $this->insertLog($eventKey, $orderId, $status, (string) ($tpl['template_key'] ?? ''), (string) $s['chat_id'], $context, $msg, $r);
            if (!$r['success']) {
                $settingsRow = $this->ensureSettingsRow();
                $this->em->getConnection()->update(self::SETTINGS_TABLE, ['last_error' => (string) ($r['message'] ?? ''), 'updated_at' => $this->now()], ['id' => (int) ($settingsRow['id'] ?? 1)]);
            }
            return (bool) ($r['success'] ?? false);
        } catch (\Throwable $e) {
            error_log('Telegram notify error: ' . $e->getMessage());
            return false;
        }
    }

    public function getEventLabels(): array
    {
        $out = [];
        foreach (self::EVENTS as $k => $v) $out[$k] = $v['label'];
        return $out;
    }

    private function runtimeSettings(): array
    {
        $row = $this->ensureSettingsRow();
        return ['enabled' => (int) ($row['enabled'] ?? 0) === 1, 'chat_id' => (string) ($row['chat_id'] ?? ''), 'bot_token' => $this->decrypt((string) ($row['bot_token'] ?? '')), 'parse_mode' => $this->normalizeParseMode((string) ($row['parse_mode'] ?? 'HTML'))];
    }

    private function ensureRows(): void
    {
        $this->ensureSettingsRow();
        $db = $this->em->getConnection();
        foreach (self::EVENTS as $key => $e) {
            $exists = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::EVENTS_TABLE . ' WHERE event_key = ?', [$key]) > 0;
            if (!$exists) $db->insert(self::EVENTS_TABLE, ['event_key' => $key, 'label' => $e['label'], 'description' => $e['desc'], 'enabled' => $e['enabled'], 'template_key' => $e['tpl'], 'throttle_minutes' => $e['throttle'], 'only_on_error' => $e['only_error'], 'created_at' => $this->now(), 'updated_at' => $this->now()]);
        }
        foreach (self::DEFAULT_TEMPLATES as $key => $tpl) {
            $exists = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::TEMPLATES_TABLE . ' WHERE template_key = ?', [$key]) > 0;
            if (!$exists) $db->insert(self::TEMPLATES_TABLE, ['template_key' => $key, 'event_key' => $key, 'title' => $tpl['title'], 'body' => $tpl['body'], 'parse_mode' => $tpl['parse_mode'], 'enabled' => 1, 'is_default' => 1, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
        }
    }

    private function ensureSettingsRow(): array
    {
        $db = $this->em->getConnection();
        $row = $db->fetchAssociative('SELECT * FROM ' . self::SETTINGS_TABLE . ' ORDER BY id ASC LIMIT 1');
        if ($row) return $row;
        $db->insert(self::SETTINGS_TABLE, ['enabled' => 0, 'bot_token' => null, 'chat_id' => null, 'parse_mode' => 'HTML', 'events' => null, 'templates' => null, 'last_test_at' => null, 'last_error' => null, 'created_at' => $this->now(), 'updated_at' => $this->now()]);
        return $db->fetchAssociative('SELECT * FROM ' . self::SETTINGS_TABLE . ' ORDER BY id ASC LIMIT 1') ?: [];
    }

    private function sendTelegramRequest(string $token, string $chatId, string $text, string $mode): array
    {
        if (!$this->isValidChatId($chatId)) return ['success' => false, 'message' => 'Telegram Chat ID geçersiz.'];
        $payload = ['chat_id' => $chatId, 'text' => $this->truncate($text), 'disable_web_page_preview' => true];
        $m = $this->normalizeParseMode($mode);
        if ($m === 'HTML') $payload['parse_mode'] = 'HTML';
        if ($m === 'MARKDOWN') $payload['parse_mode'] = 'MarkdownV2';
        $ch = curl_init('https://api.telegram.org/bot' . trim($token) . '/sendMessage');
        if ($ch === false) return ['success' => false, 'message' => 'Telegram isteği başlatılamadı.'];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'], CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0) return ['success' => false, 'message' => $this->mapErr('curl:' . $err, $http)];
        $json = json_decode((string) $raw, true);
        if (!is_array($json)) return ['success' => false, 'message' => 'Telegram geçersiz yanıt döndürdü.'];
        if (empty($json['ok'])) return ['success' => false, 'message' => $this->mapErr((string) ($json['description'] ?? 'Telegram API hatası'), $http)];
        return ['success' => true, 'message' => 'Telegram mesajı gönderildi.', 'telegram_message_id' => (string) ($json['result']['message_id'] ?? '')];
    }

    private function mapErr(string $raw, int $http): string
    {
        $m = strtolower($raw);
        if (str_contains($m, 'chat not found')) return 'Chat ID geçersiz veya bot grupta değil.';
        if (str_contains($m, 'forbidden') || str_contains($m, 'bot was blocked')) return 'Botun mesaj gönderme yetkisi yok.';
        if (str_contains($m, 'unauthorized') || str_contains($m, 'token')) return 'Bot token geçersiz.';
        if (str_contains($m, 'timeout') || str_contains($m, 'timed out') || $http === 504) return 'Telegram API timeout.';
        if ($http >= 500) return 'Telegram API sunucu hatası.';
        return $this->safe($raw);
    }

    private function vars(?EmailOrder $order, array $ctx): array
    {
        $base = $this->sampleVars();
        $total = max(0, (int) ($ctx['send_count'] ?? $ctx['total_count'] ?? $order?->getTotal() ?? 0));
        $sent = max(0, (int) ($ctx['sent_count'] ?? $ctx['success_count'] ?? $order?->getDelivered() ?? 0));
        $base['order_id'] = (string) ($order?->getId() ?? $ctx['order_id'] ?? '');
        $base['campaign_subject'] = (string) ($ctx['campaign_subject'] ?? $order?->getSubject() ?? '');
        $base['template_name'] = (string) ($ctx['template_name'] ?? $order?->getTemplate()?->getName() ?? '');
        $base['user_name'] = (string) ($ctx['user_name'] ?? $order?->getUser()?->getName() ?? '');
        $base['user_email'] = (string) ($ctx['user_email'] ?? $order?->getUser()?->getEmail() ?? '');
        $base['send_count'] = (string) $total;
        $base['success_count'] = (string) max(0, (int) ($ctx['success_count'] ?? $order?->getDelivered() ?? 0));
        $base['failed_count'] = (string) max(0, (int) ($ctx['failed_count'] ?? $order?->getFailed() ?? 0));
        $base['bounce_count'] = (string) max(0, (int) ($ctx['bounce_count'] ?? $order?->getBounced() ?? 0));
        $base['invalid_count'] = (string) max(0, (int) ($ctx['invalid_count'] ?? 0));
        $base['status'] = (string) ($ctx['status'] ?? $order?->getStatus()?->value ?? '');
        $base['data_pool_name'] = (string) ($ctx['data_pool_name'] ?? $order?->getPoolList()?->getName() ?? '');
        $base['created_at'] = $this->fmt($ctx['created_at'] ?? $order?->getCreatedAt() ?? null);
        $base['started_at'] = $this->fmt($ctx['started_at'] ?? null);
        $base['completed_at'] = $this->fmt($ctx['completed_at'] ?? $order?->getCompletedAt() ?? null);
        $base['error_message'] = (string) ($ctx['error_message'] ?? '');
        $base['worker_name'] = (string) ($ctx['worker_name'] ?? $ctx['worker_id'] ?? '');
        $base['remaining_balance'] = (string) ($ctx['remaining_balance'] ?? '');
        $base['progress_percent'] = $total > 0 ? (string) round(($sent / $total) * 100, 2) : '0';
        return $base;
    }

    private function renderTemplate(string $tpl, array $vars, string $mode): string
    {
        $m = $this->normalizeParseMode($mode);
        $rep = [];
        foreach ($vars as $k => $v) {
            $val = (string) $v;
            if ($m === 'HTML') $val = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($m === 'MARKDOWN') $val = preg_replace('/([_*\[\]()~`>#+\-=|{}.!])/', '\\\\$1', $val) ?? $val;
            $rep['{' . $k . '}'] = $val;
        }
        return strtr($tpl, $rep);
    }

    private function insertLog(string $eventKey, ?int $orderId, string $status, string $templateKey, string $chatId, array $payload, string $rendered, array $result): void
    {
        $this->em->getConnection()->insert(self::LOGS_TABLE, [
            'event_key' => $eventKey,
            'event_type' => $eventKey,
            'order_id' => $orderId,
            'status' => $status,
            'template_key' => $templateKey,
            'chat_id' => $chatId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'rendered_message' => $this->truncate($rendered),
            'status_code' => !empty($result['success']) ? 1 : 0,
            'sent' => !empty($result['success']) ? 1 : 0,
            'error_message' => !empty($result['success']) ? null : (string) ($result['message'] ?? ''),
            'telegram_message_id' => (string) ($result['telegram_message_id'] ?? ''),
            'created_at' => $this->now(),
        ]);
    }

    private function hasOrderEventBeenSent(string $eventKey, int $orderId): bool
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . self::LOGS_TABLE . ' WHERE event_key = ? AND order_id = ? AND status_code = 1',
            [$eventKey, $orderId]
        ) > 0;
    }

    private function isThrottled(int $minutes, string $eventKey, ?int $orderId, string $status): bool
    {
        if ($minutes <= 0) return false;
        $db = $this->em->getConnection();
        if ($orderId && $orderId > 0) {
            $cnt = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM ' . self::LOGS_TABLE . ' WHERE event_key = ? AND order_id = ? AND status = ? AND status_code = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)',
                [$eventKey, $orderId, $status, $minutes]
            );
            return $cnt > 0;
        }
        $cnt = (int) $db->fetchOne('SELECT COUNT(*) FROM ' . self::LOGS_TABLE . ' WHERE event_key = ? AND status_code = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)', [$eventKey, $minutes]);
        return $cnt > 0;
    }

    private function sampleVars(): array
    {
        return [
            'order_id' => '12345', 'campaign_subject' => 'Haziran Kampanyası', 'template_name' => 'Demo Şablon', 'user_name' => 'Demo Kullanıcı', 'user_email' => 'demo@nexus.com',
            'send_count' => '100000', 'success_count' => '98500', 'failed_count' => '1500', 'bounce_count' => '120', 'invalid_count' => '40',
            'status' => 'processing', 'data_pool_name' => 'Robinnet Liste 1', 'created_at' => $this->now(), 'started_at' => $this->now(), 'completed_at' => $this->now(),
            'error_message' => 'Örnek hata', 'worker_name' => 'email-worker-1', 'remaining_balance' => '12345', 'progress_percent' => '98.5',
            'total_count' => '100000', 'sent_count' => '98500', 'queue_count' => '1500', 'smtp_name' => 'SMTP #7', 'smtp_email' => 'noreply@example.com',
            'daily_limit' => '100000', 'used_limit' => '98500', 'available_balance' => '12345',
            'site_name' => (string) ($_ENV['SITE_TITLE'] ?? getenv('SITE_TITLE') ?: 'Nexus Mail'),
            'admin_url' => (string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''),
        ];
    }

    private function isValidChatId(string $chatId): bool
    {
        return preg_match('/^-?\d+$/', trim($chatId)) === 1;
    }

    private function normalizeParseMode(string $mode): string
    {
        $m = strtoupper(trim($mode));
        return in_array($m, ['HTML', 'MARKDOWN', 'PLAIN'], true) ? $m : 'HTML';
    }

    private function encrypt(string $plain): string
    {
        $iv = substr(hash('sha256', $this->key), 0, 16);
        $enc = openssl_encrypt($plain, 'AES-256-CBC', $this->key, 0, $iv);
        if ($enc === false) throw new \RuntimeException('Token şifrelenemedi.');
        return base64_encode($enc);
    }

    private function decrypt(string $enc): string
    {
        if (trim($enc) === '') return '';
        $iv = substr(hash('sha256', $this->key), 0, 16);
        $raw = base64_decode($enc, true);
        if ($raw === false) return '';
        $dec = openssl_decrypt($raw, 'AES-256-CBC', $this->key, 0, $iv);
        return $dec === false ? '' : $dec;
    }

    private function maskToken(string $token): string
    {
        $t = trim($token);
        $len = strlen($t);
        if ($len <= 8) return $len ? str_repeat('*', $len) : '';
        return substr($t, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($t, -4);
    }

    private function truncate(string $text): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') <= self::MAX_MESSAGE_LENGTH) return $text;
            return mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - 10, 'UTF-8') . "\n\n…";
        }
        if (strlen($text) <= self::MAX_MESSAGE_LENGTH) return $text;
        return substr($text, 0, self::MAX_MESSAGE_LENGTH - 10) . "\n\n...";
    }

    private function safe(string $msg): string
    {
        $s = preg_replace('/\b(\d{6,}:[A-Za-z0-9_\-]{20,})\b/', '[MASKED_TOKEN]', str_replace(["\n", "\r"], ' ', trim($msg)));
        return $s ?: 'Bilinmeyen hata';
    }

    private function fmt(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d H:i:s');
        return is_string($v) ? trim($v) : '';
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}

