#!/bin/bash

echo "🧹 Worker Log Temizleme Başlatılıyor..."
echo ""

# Çalışma dizinini kontrol et
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

# Log dosyalarını bul ve temizle (mail-only)
WORKERS=("email-worker")
TOTAL_CLEARED=0

for worker in "${WORKERS[@]}"; do
    if [ -d "$worker/logs" ]; then
        echo "📂 $worker/logs"
        LOG_COUNT=$(find "$worker/logs" -type f -name "*.log" 2>/dev/null | wc -l)
        
        if [ $LOG_COUNT -gt 0 ]; then
            find "$worker/logs" -type f -name "*.log" -exec truncate -s 0 {} \; 2>/dev/null
            echo "   ✅ $LOG_COUNT log dosyası temizlendi"
            TOTAL_CLEARED=$((TOTAL_CLEARED + LOG_COUNT))
        else
            echo "   ℹ️  Log dosyası bulunamadı"
        fi
    else
        echo "📂 $worker/logs - dizin yok"
    fi
done

echo ""
echo "✨ Toplam $TOTAL_CLEARED log dosyası temizlendi!"
echo ""

# Apache log'larını temizle (opsiyonel - root gerekir)
if [ "$EUID" -eq 0 ]; then
    echo "🌐 Apache Log'ları Temizleniyor..."
    
    APACHE_LOGS=("/var/log/apache2/error.log" "/var/log/httpd/error_log")
    
    for log in "${APACHE_LOGS[@]}"; do
        if [ -f "$log" ]; then
            truncate -s 0 "$log"
            echo "   ✅ $(basename "$log") temizlendi"
        fi
    done
    
    echo "   ✅ Apache log'ları temizlendi"
    
    # Apache'yi reload et
    if command -v systemctl &> /dev/null; then
        systemctl reload apache2 2>/dev/null || systemctl reload httpd 2>/dev/null
        echo "   ✅ Apache reload edildi"
    fi
else
    echo "ℹ️  Apache log'ları için root yetki gerekli (sudo ile çalıştırın)"
fi

echo ""
echo "✅ Tamamlandı!"
