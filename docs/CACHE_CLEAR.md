# Cache Temizleme Kılavuzu

Sunucuda cache temizleme için kullanabileceğiniz yöntemler:

## 1. SSH Üzerinden Komut (Önerilen)

```bash
# Proje dizinine gidin
cd /path/to/nexus

# Cache temizleme scriptini çalıştırın
php bin/clear-cache.php
```

Bu komut şunları temizler:
- ✅ Twig cache (`var/cache/twig/`)
- ✅ Doctrine cache (`var/cache/doctrine/`)
- ✅ Container cache (`var/cache/container.php`)

## 2. Web Üzerinden (Admin Girişi Gerekli)

Admin olarak giriş yaptıktan sonra:

```
https://yourdomain.com/clear-cache.php
```

veya tarayıcıdan direkt açın. JSON formatında sonuç döner.

**Not:** Bu endpoint sadece admin kullanıcıları için çalışır.

## 3. Manuel Dosya Silme

SSH üzerinden manuel olarak:

```bash
# Twig cache
rm -rf /path/to/nexus/var/cache/twig/*

# Doctrine cache
rm -rf /path/to/nexus/var/cache/doctrine/*

# Container cache
rm -f /path/to/nexus/var/cache/container.php
```

## 4. OPcache Temizleme (PHP OPcache)

Eğer PHP OPcache aktifse, bunu da temizlemek gerekebilir:

### Yöntem A: PHP-FPM restart
```bash
sudo systemctl restart php-fpm
# veya
sudo service php-fpm restart
```

### Yöntem B: OPcache reset endpoint (eğer varsa)
```bash
curl http://yourdomain.com/opcache-reset.php
```

### Yöntem C: PHP dosyası ile
```php
<?php
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "OPcache temizlendi";
} else {
    echo "OPcache aktif değil";
}
```

## 5. Tarayıcı Cache ve Çerezler

### Chrome/Edge:
1. `Ctrl+Shift+Delete` (Windows) veya `Cmd+Shift+Delete` (Mac)
2. "Çerezler ve diğer site verileri" seçin
3. "Tüm zamanlar" seçin
4. "Verileri temizle" tıklayın

### Firefox:
1. `Ctrl+Shift+Delete` (Windows) veya `Cmd+Shift+Delete` (Mac)
2. "Çerezler" seçin
3. "Tüm zamanlar" seçin
4. "Şimdi temizle" tıklayın

### Safari:
1. `Cmd+Option+E` (Mac)
2. Veya Safari > Ayarlar > Gizlilik > "Tüm web sitesi verilerini kaldır"

## 6. Domain Config Değişikliklerinden Sonra

Domain ayarları değiştirdikten sonra:

1. **Otomatik:** Domain ayarları kaydedildiğinde Twig cache otomatik temizlenir
2. **Manuel:** Yukarıdaki yöntemlerden birini kullanın
3. **Sayfayı yenileyin:** `Ctrl+F5` (hard refresh) ile tarayıcı cache'ini de temizleyin

## 7. Hızlı Komut (Tek Satır)

```bash
cd /path/to/nexus && php bin/clear-cache.php && echo "Cache temizlendi!"
```

## Sorun Giderme

### Cache temizlenmiyor?
- Dosya izinlerini kontrol edin: `chmod -R 775 var/cache/`
- Web sunucusu kullanıcısının yazma izni olduğundan emin olun
- `var/cache/` dizininin var olduğundan emin olun

### Logo/marka öğeleri güncellenmiyor?
1. Twig cache'i temizleyin
2. Tarayıcı cache'ini temizleyin (`Ctrl+F5`)
3. Domain ayarlarını kontrol edin (`/admin/domain-settings`)
4. Domain config'in doğru yüklendiğini loglardan kontrol edin

### Production'da cache sorunları?
- `APP_ENV=production` ise cache aktif olur
- Her değişiklikten sonra cache'i temizleyin
- `auto_reload` aktif olsa bile production'da cache temizleme önerilir
