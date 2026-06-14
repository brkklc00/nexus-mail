#!/bin/bash
# Disk izleme + otomatik log temizleme
# Cron: */30 * * * * /var/www/nexus-mail/bin/disk-watch.sh >> /var/log/disk-watch.log 2>&1

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
HOSTNAME=$(hostname)
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# Eşikler
WARN_PCT=75     # uyarı logu
CLEAN_PCT=85    # otomatik log temizle
ALERT_PCT=90    # kritik uyarı

# / bölümünün kullanım yüzdesi
DISK_PCT=$(df / | awk 'NR==2 {sub(/%/,"",$5); print $5}')
DISK_AVAIL=$(df -h / | awk 'NR==2 {print $4}')

if [ "$DISK_PCT" -lt "$WARN_PCT" ]; then
    # Normal — sessiz çık
    exit 0
fi

echo "[$TIMESTAMP] $HOSTNAME disk: %${DISK_PCT} kullanılıyor (${DISK_AVAIL} serbest)"

if [ "$DISK_PCT" -ge "$ALERT_PCT" ]; then
    echo "[$TIMESTAMP] KRİTİK: Disk %${DISK_PCT} dolu — acil temizleme!"
fi

if [ "$DISK_PCT" -ge "$CLEAN_PCT" ]; then
    echo "[$TIMESTAMP] Otomatik log temizleme başlıyor (%${DISK_PCT} >= %${CLEAN_PCT})"
    bash "$SCRIPT_DIR/clear-worker-logs.sh" 2>&1 | sed "s/^/  /"

    # Temizlik sonrası yeni disk durumu
    NEW_PCT=$(df / | awk 'NR==2 {sub(/%/,"",$5); print $5}')
    NEW_AVAIL=$(df -h / | awk 'NR==2 {print $4}')
    echo "[$TIMESTAMP] Temizleme sonrası: %${NEW_PCT} kullanılıyor (${NEW_AVAIL} serbest)"
else
    echo "[$TIMESTAMP] UYARI: Disk %${DISK_PCT} (eşik %${CLEAN_PCT}) — izleniyor"
fi
