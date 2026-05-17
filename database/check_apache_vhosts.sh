#!/bin/bash

# Apache VirtualHost kontrol script'i

echo "=== APACHE VIRTUALHOST KONTROLÜ ==="
echo ""

echo "1. Aktif VirtualHost dosyaları:"
echo "----------------------------------------"
ls -la /etc/apache2/sites-enabled/ | grep -E "\.conf$|\.ssl$"
echo ""

echo "2. hub-nexus.com VirtualHost içeriği:"
echo "----------------------------------------"
if [ -f /etc/apache2/sites-enabled/hub-nexus.com-le-ssl.conf ]; then
    cat /etc/apache2/sites-enabled/hub-nexus.com-le-ssl.conf | grep -E "ServerName|ServerAlias|DocumentRoot|VirtualHost"
elif [ -f /etc/apache2/sites-enabled/000-default-le-ssl.conf ]; then
    cat /etc/apache2/sites-enabled/000-default-le-ssl.conf | grep -E "ServerName|ServerAlias|DocumentRoot|VirtualHost"
else
    echo "VirtualHost dosyası bulunamadı!"
fi
echo ""

echo "3. Tüm VirtualHost'lar:"
echo "----------------------------------------"
for file in /etc/apache2/sites-enabled/*.conf; do
    if [ -f "$file" ]; then
        echo "=== $file ==="
        grep -E "ServerName|ServerAlias|DocumentRoot|VirtualHost" "$file" | head -10
        echo ""
    fi
done

echo "4. Apache modül durumu:"
echo "----------------------------------------"
apache2ctl -M | grep -E "rewrite|headers|ssl"
