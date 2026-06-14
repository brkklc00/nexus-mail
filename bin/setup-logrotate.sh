#!/bin/bash
# PM2 logrotate kurulum + disk-watch cron ayarı
# Bir kez çalıştır: bash /var/www/nexus-mail/bin/setup-logrotate.sh

HOSTNAME=$(hostname)
MAIL_ROOT="/var/www/nexus-mail"
DISK_WATCH_LOG="/var/log/disk-watch.log"

echo "=== PM2 Logrotate + Disk İzleme Kurulumu — $HOSTNAME ==="
echo ""

# 1. pm2-logrotate kur (zaten varsa günceller)
echo "1. pm2-logrotate kuruluyor..."
pm2 install pm2-logrotate

# 2. Konfigürasyon
echo ""
echo "2. Logrotate ayarları yapılandırılıyor..."
pm2 set pm2-logrotate:max_size 30M          # 30MB'dan büyük dosyayı rotate et
pm2 set pm2-logrotate:retain 5              # son 5 arşivi sakla
pm2 set pm2-logrotate:compress true         # gzip ile sıkıştır
pm2 set pm2-logrotate:dateFormat YYYY-MM-DD_HH-mm  # arşiv tarih formatı
pm2 set pm2-logrotate:workerInterval 3600   # her saat kontrol et
pm2 set pm2-logrotate:rotateInterval '0 3 * * *'   # her gece 03:00'da rotate
pm2 set pm2-logrotate:rotateModule true     # pm2-logrotate'in kendi loglarını da rotate et
echo "   Tamam"

# 3. disk-watch.log dosyası oluştur
touch "$DISK_WATCH_LOG" 2>/dev/null || true

# 4. Cron: her 30 dakikada disk izle
echo ""
echo "3. Cron job ekleniyor (disk-watch her 30dk)..."
CRON_LINE="*/30 * * * * $MAIL_ROOT/bin/disk-watch.sh >> $DISK_WATCH_LOG 2>&1"
# Aynı satır zaten varsa ekleme
if crontab -l 2>/dev/null | grep -qF "disk-watch.sh"; then
    echo "   disk-watch cron zaten mevcut, atlandı"
else
    ( crontab -l 2>/dev/null; echo "$CRON_LINE" ) | crontab -
    echo "   Eklendi: $CRON_LINE"
fi

# 5. PM2 kaydet
echo ""
echo "4. PM2 listesi kaydediliyor..."
pm2 save

# 6. Mevcut durumu göster
echo ""
echo "=== Mevcut PM2 logrotate ayarları ==="
pm2 get pm2-logrotate 2>/dev/null || true

echo ""
echo "=== Disk durumu ==="
df -h /

echo ""
echo "=== Kurulum tamamlandi — $HOSTNAME ==="
