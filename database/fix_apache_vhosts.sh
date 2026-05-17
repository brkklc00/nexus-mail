#!/bin/bash

# Apache VirtualHost dosyalarını düzelt
# HTTP_HOST header'ını korumak için RequestHeader ekle
# Apache yeniden kurulduğunda bu script'i çalıştırın!

echo "=== APACHE VIRTUALHOST DÜZELTME ==="
echo "⚠️  Apache yeniden kurulduğunda bu script'i çalıştırın!"
echo ""

DOMAINS=("hub-nexus.com" "numbpanel.com" "prime-medya.com" "x-solutions.app")

for domain in "${DOMAINS[@]}"; do
    echo "Düzeltiliyor: $domain"
    
    # SSL VirtualHost dosyası
    SSL_FILE="/etc/apache2/sites-available/${domain}-le-ssl.conf"
    
    if [ -f "$SSL_FILE" ]; then
        # UseCanonicalName Off ekle (HTTP_HOST'u korumak için)
        # Eğer zaten varsa, önce kaldır sonra ekle (tekrar eklenmesin)
        if grep -q "UseCanonicalName" "$SSL_FILE"; then
            # Mevcut UseCanonicalName satırını kaldır
            sed -i '/UseCanonicalName/d' "$SSL_FILE"
        fi
        # VirtualHost açılış tag'inden sonra ekle
        sed -i "/<VirtualHost \*:443>/a\\    UseCanonicalName Off" "$SSL_FILE"
        echo "  ✅ UseCanonicalName Off eklendi: $SSL_FILE"
        
        # RequestHeader direktifi var mı kontrol et
        if grep -q "RequestHeader set Host" "$SSL_FILE"; then
            # Mevcut RequestHeader satırını kaldır
            sed -i '/RequestHeader set Host/d' "$SSL_FILE"
        fi
        # ServerName'den sonra ekle
        sed -i "/ServerName/a\\    RequestHeader set Host %{HTTP_HOST}e env=HTTP_HOST" "$SSL_FILE"
        echo "  ✅ RequestHeader eklendi: $SSL_FILE"
    else
        echo "  ❌ $SSL_FILE bulunamadı"
    fi
    
    # HTTP VirtualHost dosyası (opsiyonel)
    HTTP_FILE="/etc/apache2/sites-available/${domain}.conf"
    
    if [ -f "$HTTP_FILE" ]; then
        if ! grep -q "RequestHeader set Host" "$HTTP_FILE"; then
            sed -i "/ServerName/a\\    RequestHeader set Host %{HTTP_HOST}e env=HTTP_HOST" "$HTTP_FILE"
            echo "  ✅ $HTTP_FILE güncellendi"
        fi
    fi
    
    echo ""
done

echo "=== APACHE YENİDEN YÜKLEME ==="
apache2ctl configtest
if [ $? -eq 0 ]; then
    echo "✅ Apache config test başarılı!"
    echo "Apache'yi yeniden yüklemek için: systemctl reload apache2"
else
    echo "❌ Apache config test başarısız! Lütfen kontrol edin."
fi
