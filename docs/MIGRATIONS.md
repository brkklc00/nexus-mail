# Veritabanı Migrations

## Otomatik Çalıştırma

Push yapıldığında GitHub Actions deploy workflow'u migration'ları otomatik çalıştırır.

## Manuel Çalıştırma

### Doctrine Migrations (PHP 8.4)

```bash
/Applications/MAMP/bin/php/php8.4.15/bin/php vendor/bin/doctrine-migrations migrate --no-interaction --db-configuration=migrations-db.php
```

### Proje Migrations (run-migrations)

```bash
/Applications/MAMP/bin/php/php8.4.15/bin/php bin/run-migrations
```

## Şema kodda var, veritabanında yok (ör. pool_list_id)

Kod deploy edildi ama migration sunucuda çalışmadıysa `Unknown column 'pool_list_id'` benzeri hatalar oluşur.

Doctrine “Already at the latest version” deyip `Version20260330` hiç listelenmiyorsa, sunucuda `database/migrations/Version20260330_*.php` eksiktir veya eski deploy’dur. **Doğrudan şema yaması:**

```bash
cd /var/www/main && php bin/apply-email-pool-schema.php
```

Bu script `.env` ile bağlanır, eksik kolonları ekler ve `doctrine_migration_versions` içine `Version20260330` kaydını yoksa yazar.

1. SSH ile: `cd /var/www/main` (veya proje kökü) ve `bash bin/run-doctrine-migrations.sh`
2. Hâlâ sorun varsa veya migration kaydı “çalıştı” görünüp kolon yoksa: [`database/sql/manual_patch_20260330_email_pool_lists.sql`](../database/sql/manual_patch_20260330_email_pool_lists.sql) dosyasını MySQL’de çalıştırın. `Version20260330` migration dosyası artık **idempotent** (eksik tablo/kolon varsa tamamlar).
3. `doctrine_migration_versions` içinde `Version20260330_EmailSendingCapsAndPoolLists` kaydı var ama kolonlar yoksa: ilgili satırı silip `bash bin/run-doctrine-migrations.sh` tekrar çalıştırın (idempotent migration eksikleri tamamlar).

## Config Dosyaları

- `migrations.php` - Doctrine Migrations ana config
- `migrations-db.php` - Veritabanı bağlantı ayarları
- `config/migrations-doctrine.php` - Doctrine format migration'lar için config
- `database/migrations_doctrine/` - Doctrine AbstractMigration formatındaki migration'lar
