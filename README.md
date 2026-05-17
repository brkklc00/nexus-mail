# Nexus Mail

Nexus Mail, Nexus panelinin sadeleştirilmiş ve yalnızca e-posta operasyonlarına odaklanan sürümüdür.

## Özellikler

- Mail gönderimi, sipariş takibi ve gönderim geçmişi
- Email rehberleri, şablonlar ve kara liste yönetimi
- SMTP havuzu, sağlık durumu, test ve aktif/pasif yönetimi
- Mail gönderim ayarları (kota, hız, throttle, worker pacing)
- Link kısaltıcı ve destek talepleri
- Son 7 gün mail dashboard ve temel performans göstergeleri

## Kurulum

1. Depoyu klonlayın.
2. PHP bağımlılıklarını kurun:
   - `composer install`
3. Worker bağımlılıklarını kurun:
   - `cd email-worker && npm install`
4. Ortam dosyasını oluşturun:
   - `cp .env.example .env`
5. Veritabanı migrasyonlarını çalıştırın.
6. Uygulamayı web sunucusu üzerinden başlatın.

## ENV

Temel değişkenler için `.env.example` dosyasını kullanın.

- Uygulama: `APP_ENV`, `APP_URL`
- Güvenlik: `AUTH_SECRET`, `TRACKING_SECRET`
- Veritabanı: `DATABASE_URL` (veya `EMAIL_DB_*`)
- Redis: `REDIS_URL`
- Worker: `MAIL_WORKER_*`, `EMAIL_*`, `WORKER_*`
- SMTP: `SMTP_DEFAULT_*`
- Alibaba DirectMail: `ALIBABA_DIRECTMAIL_*`
- Link kısaltıcı: `LINK_SHORTENER_*`

## Worker Çalıştırma

Mail worker:

- `cd email-worker`
- `npm run start`

Worker yalnızca mail kampanya/sipariş kuyruğunu işler.

## SMTP Ayarları

- Panelden `SMTP Hesapları` ekranında hesap ekleyin.
- `Test Connection` ile doğrulama yapın.
- Aktif/pasif durumunu panelden yönetin.
- Günlük/saatlik/dakikalık limitler `Email Gönderim Ayarları` ile uyumludur.

## Mail Gönderim Akışı

1. Rehber/manuel/havuz ile mail siparişi oluşturulur.
2. Sipariş worker kuyruğuna düşer.
3. Worker uygun SMTP hesabını seçer.
4. Gönderim sonuçları (başarılı/hatalı/bounce) işlenir.
5. Dashboard ve sipariş detayları güncellenir.

## Tracking

Sistem mail kampanyalarında open/click/unsubscribe akışları için izleme altyapısını destekler.

## Troubleshooting

- `Slim Application Error` görüyorsanız uygulama loglarını kontrol edin.
- SMTP testi başarısızsa host/port/şifreleme ve kimlik bilgilerini doğrulayın.
- Worker çalışmıyorsa `email-worker` loglarını ve API erişimini kontrol edin.
- Gönderim yavaşsa `Email Gönderim Ayarları` üzerinde throttle ve lane değerlerini optimize edin.
