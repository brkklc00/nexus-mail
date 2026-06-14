#!/bin/bash
# Worker & sistem log temizleme — nexus-mail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
cd "$PROJECT_ROOT"

TOTAL_BEFORE=0
TOTAL_AFTER=0

log_size_mb() { du -sm "$1" 2>/dev/null | awk '{print $1}'; }

echo "=== Worker Log Temizleme — $(date '+%Y-%m-%d %H:%M:%S') ==="
echo ""
echo "Disk durumu (önce):"
df -h / | tail -1
echo ""

# --- PM2 flush (in-memory log buffer) ---
if command -v pm2 &>/dev/null; then
    pm2 flush --silent 2>/dev/null && echo "[pm2] flush tamam" || true
fi

# --- Worker log dosyaları ---
LOG_DIRS=(
    "email-worker/logs"
    "storage/logs"
)

for dir in "${LOG_DIRS[@]}"; do
    if [ ! -d "$dir" ]; then
        continue
    fi
    before=$(log_size_mb "$dir")
    TOTAL_BEFORE=$((TOTAL_BEFORE + ${before:-0}))
    find "$dir" -type f \( -name "*.log" -o -name "*.log.*" \) | while read -r f; do
        truncate -s 0 "$f"
    done
    after=$(log_size_mb "$dir")
    TOTAL_AFTER=$((TOTAL_AFTER + ${after:-0}))
    echo "[$dir] $before MB -> $after MB"
done

# --- PM2 rotasyonlu log dosyaları (~/.pm2/logs) ---
PM2_LOG_DIR="${HOME}/.pm2/logs"
if [ -d "$PM2_LOG_DIR" ]; then
    before=$(log_size_mb "$PM2_LOG_DIR")
    # Sıkıştırılmış arşivleri sil, aktif olanları truncate et
    find "$PM2_LOG_DIR" -name "*.gz" -delete 2>/dev/null
    find "$PM2_LOG_DIR" -name "*.log*" -type f | while read -r f; do
        truncate -s 0 "$f"
    done
    after=$(log_size_mb "$PM2_LOG_DIR")
    echo "[pm2 logs] $before MB -> $after MB"
fi

# --- Sistem logları (root gerekir) ---
if [ "$EUID" -eq 0 ]; then
    echo ""
    echo "Sistem logları temizleniyor..."
    # Eski journal kayıtlarını (>3 gün) sil
    journalctl --vacuum-time=3d 2>/dev/null | tail -1
    # Apache/Nginx
    for f in /var/log/apache2/error.log /var/log/nginx/error.log; do
        [ -f "$f" ] && truncate -s 0 "$f" && echo "  $f temizlendi"
    done
fi

echo ""
echo "Disk durumu (sonra):"
df -h / | tail -1
echo ""
echo "=== Tamamlandi ==="
