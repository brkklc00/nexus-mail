# 📋 Dosya İzinleri Rehberi

## 🎯 Genel Kural

- **Klasörler**: `755` (rwxr-xr-x) - Owner okuyup yazabilir, diğerleri sadece okuyabilir
- **Dosyalar**: `644` (rw-r--r--) - Owner okuyup yazabilir, diğerleri sadece okuyabilir
- **Executable**: `755` (rwxr-xr-x) - Çalıştırılabilir scriptler
- **Yazılabilir**: `775` (rwxrwxr-x) - Web server'ın yazması gereken klasörler

## 📁 Storage vs Var Klasörleri

### 🗄️ Storage Klasörü (`/storage`)
**Amaç**: Kullanıcı dosyaları, upload'lar, medya dosyaları
**İzinler**: `775` (rwxrwxr-x)
**Owner**: `www-data:www-data`

```
storage/                    775  (Web server yazabilmeli)
├── logs/                   775  (Uygulama logları)
├── uploads/                775  (Kullanıcı upload'ları)
└── uploads/                775  (Kullanıcı upload dosyaları)
```

**Neden 775?**
- ✅ Web server (www-data) dosya yazabilmeli
- ✅ Deploy kullanıcısı dosyaları okuyabilmeli
- ✅ Backup scriptleri erişebilmeli

### 📦 Var Klasörü (`/var`)
**Amaç**: Geçici dosyalar, cache, session
**İzinler**: `775` (rwxrwxr-x)
**Owner**: `www-data:www-data`

```
var/                        775  (Sistem klasörü)
└── cache/                  775  (Twig template cache)
```

**Neden 775?**
- ✅ Twig cache dosyaları oluşturulabilmeli
- ✅ Cache silinebilmeli/güncellenebilmeli
- ✅ Deploy sırasında temizlenebilmeli

## ⚠️ ASLA 777 KULLANMA!

`777` (rwxrwxrwx) = Herkes her şeyi yapabilir = **GÜVENLİK RİSKİ**

❌ **Yanlış**:
```bash
chmod -R 777 storage/    # Tehlikeli!
chmod 777 var/cache/      # Güvensiz!
```

✅ **Doğru**:
```bash
chmod -R 775 storage/
chmod 775 var/cache/
chown -R www-data:www-data storage/ var/
```

## 🔐 Sunucu İzin Yapısı

### Standart Dizin Yapısı
```
/var/www/hub-nexus.com/
├── app/                    755  (Sadece okunur)
├── bin/                    755  (Executable)
│   ├── console            755
│   ├── deploy-domains     755
│   └── fix-permissions    755
├── config/                 755  (Sadece okunur)
├── public/                 755  (Web root)
│   ├── assets/            755
│   ├── index.php          644
│   └── storage/           775  (Symlink veya yazılabilir)
├── storage/               775  (Yazılabilir)
│   ├── logs/              775
│   ├── uploads/           775
│   └── uploads/           775
├── templates/             755  (Sadece okunur)
├── var/                   775  (Yazılabilir)
│   └── cache/             775
├── vendor/                755  (Sadece okunur)
└── composer.json          644
```

## 🚀 Deployment Sonrası Kontrol

```bash
# 1. Owner kontrolü
ls -la /var/www/hub-nexus.com/storage
ls -la /var/www/hub-nexus.com/var

# Beklenen: www-data www-data

# 2. İzin kontrolü
stat -c "%a %n" /var/www/hub-nexus.com/storage
stat -c "%a %n" /var/www/hub-nexus.com/var/cache

# Beklenen: 775

# 3. Yazma testi
sudo -u www-data touch /var/www/hub-nexus.com/storage/test.txt
sudo -u www-data touch /var/www/hub-nexus.com/var/cache/test.txt
```

## 🛠️ Sorun Giderme

### Sorun 1: "Permission denied" hatası
```bash
# Çözüm: Owner ve izinleri düzelt
sudo chown -R www-data:www-data storage/ var/
sudo chmod -R 775 storage/ var/
```

### Sorun 2: "Cache yazılamıyor" veya "proxy directory must be writable"
```bash
# Çözüm: Dizinleri oluştur, izinleri düzelt
cd /var/www/hub-nexus.com
sudo mkdir -p var/cache/doctrine/proxies var/cache/twig
sudo chmod -R 775 var/cache
sudo chown -R www-data:www-data var/cache
```

### Sorun 3: "Upload başarısız"
```bash
# Çözüm: Storage klasörünü kontrol et
sudo chmod 775 storage/uploads
sudo chown www-data:www-data storage/uploads
```

## 🔄 Otomatik İzin Düzeltme

Proje kökünde `bin/fix-permissions` scripti var:

```bash
cd /var/www/hub-nexus.com
sudo ./bin/fix-permissions
```

Bu script tüm izinleri otomatik düzeltir.

## 📊 İzin Karşılaştırma Tablosu

| Klasör          | Local (Dev)  | Sunucu (Prod) | Owner           | Açıklama                    |
|----------------|--------------|---------------|-----------------|----------------------------|
| `storage/`     | 755 → 775    | **775**       | www-data:www-data | Upload ve medya dosyaları |
| `storage/logs/`| 755 → 775    | **775**       | www-data:www-data | Uygulama logları          |
| `var/cache/`   | 777 → 775    | **775**       | www-data:www-data | Twig template cache       |
| `public/`      | 755          | **755**       | www-data:www-data | Web root (sadece okuma)   |
| `app/`         | 755          | **755**       | www-data:www-data | Uygulama kodu             |
| `bin/*`        | 755          | **755**       | www-data:www-data | Executable scriptler      |
| `vendor/`      | 755          | **755**       | www-data:www-data | Composer bağımlılıkları   |

## ✅ Güvenlik En İyi Uygulamaları

1. ✅ **Minimum izin prensibi**: Sadece gerekli yerlere yazma izni ver
2. ✅ **Doğru owner**: Web server kullanıcısı (www-data) owner olmalı
3. ✅ **Hassas dosyalar**: `.env`, config dosyaları 640 veya 644 olmalı
4. ✅ **Public erişim**: `public/` dışındaki dosyalara web'den erişim olmamalı
5. ❌ **777 yasak**: Hiçbir zaman 777 kullanma
6. ❌ **Root owner**: Dosyaların owner'ı root olmamalı

## 🎯 Hızlı Komutlar

```bash
# Tüm izinleri düzelt
sudo ./bin/fix-permissions

# Sadece storage'ı düzelt
sudo chown -R www-data:www-data storage/
sudo chmod -R 775 storage/

# Sadece cache'i düzelt
sudo rm -rf var/cache/*
sudo chown -R www-data:www-data var/
sudo chmod -R 775 var/

# İzinleri kontrol et
find storage/ var/ -type d -exec ls -ld {} \;
```

---

**Son güncelleme**: 23 Aralık 2025
**Versiyon**: 1.0

