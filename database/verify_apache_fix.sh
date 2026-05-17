#!/bin/bash

# Apache VirtualHost dosyalarının düzgün güncellenip güncellenmediğini kontrol et

echo "=== APACHE VIRTUALHOST DOĞRULAMA ==="
echo ""

DOMAINS=("hub-nexus.com" "numbpanel.com" "prime-medya.com" "x-solutions.app")

for domain in "${DOMAINS[@]}"; do
    echo "Kontrol ediliyor: $domain"
    
    SSL_FILE="/etc/apache2/sites-available/${domain}-le-ssl.conf"
    
    if [ -f "$SSL_FILE" ]; then
        echo "  📄 Dosya: $SSL_FILE"
        
        # UseCanonicalName Off var mı?
        if grep -q "UseCanonicalName Off" "$SSL_FILE"; then
            echo "  ✅ UseCanonicalName Off: VAR"
        else
            echo "  ❌ UseCanonicalName Off: YOK"
        fi
        
        # RequestHeader var mı?
        if grep -q "RequestHeader set Host" "$SSL_FILE"; then
            echo "  ✅ RequestHeader set Host: VAR"
        else
            echo "  ❌ RequestHeader set Host: YOK"
        fi
        
        # VirtualHost içeriğini göster
        echo "  📋 VirtualHost içeriği:"
        grep -E "VirtualHost|ServerName|ServerAlias|UseCanonicalName|RequestHeader|DocumentRoot" "$SSL_FILE" | sed 's/^/    /'
        echo ""
    else
        echo "  ❌ Dosya bulunamadı: $SSL_FILE"
        echo ""
    fi
done

echo "=== APACHE CONFIG TEST ==="
apache2ctl configtest
