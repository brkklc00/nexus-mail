#!/bin/bash

# Apache ve PHP-FPM yeniden başlatma script'i

echo "=== SERVİSLERİ YENİDEN BAŞLATMA ==="
echo ""

# PHP versiyonunu kontrol et
PHP_VERSION=$(php -v | head -n 1 | grep -oP '\d+\.\d+' | head -n 1)
echo "PHP Versiyonu: $PHP_VERSION"

# PHP-FPM servis adını belirle
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"

echo ""
echo "PHP-FPM Servisi: $PHP_FPM_SERVICE"
echo ""

# PHP-FPM'i yeniden başlat
if systemctl list-units --type=service | grep -q "$PHP_FPM_SERVICE"; then
    echo "🔄 PHP-FPM yeniden başlatılıyor..."
    sudo systemctl restart "$PHP_FPM_SERVICE"
    if [ $? -eq 0 ]; then
        echo "✅ PHP-FPM başarıyla yeniden başlatıldı"
    else
        echo "❌ PHP-FPM yeniden başlatılamadı"
    fi
else
    echo "⚠️  PHP-FPM servisi bulunamadı: $PHP_FPM_SERVICE"
    echo "   Alternatif servis adlarını kontrol ediliyor..."
    
    # Alternatif servis adlarını dene
    for alt_service in php-fpm php8.3-fpm php8.2-fpm php8.1-fpm php8.0-fpm; do
        if systemctl list-units --type=service | grep -q "$alt_service"; then
            echo "🔄 PHP-FPM yeniden başlatılıyor: $alt_service"
            sudo systemctl restart "$alt_service"
            if [ $? -eq 0 ]; then
                echo "✅ PHP-FPM başarıyla yeniden başlatıldı: $alt_service"
                break
            fi
        fi
    done
fi

echo ""

# Apache'yi yeniden başlat
if systemctl list-units --type=service | grep -q "apache2"; then
    echo "🔄 Apache yeniden başlatılıyor..."
    sudo systemctl restart apache2
    if [ $? -eq 0 ]; then
        echo "✅ Apache başarıyla yeniden başlatıldı"
    else
        echo "❌ Apache yeniden başlatılamadı"
    fi
elif systemctl list-units --type=service | grep -q "httpd"; then
    echo "🔄 HTTPD yeniden başlatılıyor..."
    sudo systemctl restart httpd
    if [ $? -eq 0 ]; then
        echo "✅ HTTPD başarıyla yeniden başlatıldı"
    else
        echo "❌ HTTPD yeniden başlatılamadı"
    fi
else
    echo "⚠️  Apache/HTTPD servisi bulunamadı"
fi

echo ""
echo "✅ Servis yeniden başlatma tamamlandı!"
echo ""
echo "Şimdi sayfayı yenileyin ve domain config'in çalıştığını kontrol edin:"
echo "  - https://numbpanel.com/login"
echo "  - https://prime-medya.com/login"
echo "  - https://x-solutions.app/login"
