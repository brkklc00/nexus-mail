#!/usr/bin/env bash
# nexus-mail repo için git hook'larını kur
# WORKER_SLOT kurulumu için slot numarasını argüman olarak gir:
#   bash bin/install-hooks.sh 0    # mail sunucusu
#   bash bin/install-hooks.sh 1    # mail-1 sunucusu
#   bash bin/install-hooks.sh 2    # mail-2 sunucusu

set -euo pipefail
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
HOOKS_DIR="$REPO_DIR/.git/hooks"
SCRIPTS_DIR="$REPO_DIR/bin/git-hooks"
SLOT="${1:-}"

if [ ! -d "$HOOKS_DIR" ]; then
    echo "❌ .git/hooks dizini bulunamadı."
    exit 1
fi

# Slot belirleme
if [ -z "$SLOT" ]; then
    # .env'den veya .worker-slot'tan oku
    if [ -f "$REPO_DIR/.worker-slot" ]; then
        SLOT=$(cat "$REPO_DIR/.worker-slot" | tr -d '[:space:]')
    elif [ -f "$REPO_DIR/.env" ]; then
        SLOT=$(grep -E '^WORKER_SLOT=' "$REPO_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2 | tr -d "'\"\r\n " || true)
    fi
fi

if [ -z "$SLOT" ]; then
    echo "⚠️  Slot numarası belirtilmedi!"
    echo "   Kullanım: bash bin/install-hooks.sh <0|1|2>"
    echo "   Örnek:    bash bin/install-hooks.sh 0   (bu sunucu: mail, mail-1, mail-2)"
    echo ""
    echo "   Slot seç: (0=mail  1=mail-1  2=mail-2)"
    read -r -p "   Slot numarası: " SLOT
fi

if ! echo "$SLOT" | grep -qE '^[0-2]$'; then
    echo "❌ Geçersiz slot: $SLOT (0, 1 veya 2 olmalı)"
    exit 1
fi

# .worker-slot dosyasına kaydet (kalıcı)
echo "$SLOT" > "$REPO_DIR/.worker-slot"
echo "✅ .worker-slot = $SLOT kaydedildi"

# .env güncelle
ENV_FILE="$REPO_DIR/.env"
if [ -f "$ENV_FILE" ]; then
    if grep -qE '^WORKER_SLOT=' "$ENV_FILE"; then
        # var, güncelle
        sed -i.bak "s/^WORKER_SLOT=.*/WORKER_SLOT=$SLOT/" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
        echo "✅ .env: WORKER_SLOT güncellendi → $SLOT"
    else
        # yok, ekle
        echo "" >> "$ENV_FILE"
        echo "# Priority campaign worker slot (0=mail, 1=mail-1, 2=mail-2)" >> "$ENV_FILE"
        echo "WORKER_SLOT=$SLOT" >> "$ENV_FILE"
        echo "✅ .env: WORKER_SLOT=$SLOT eklendi"
    fi
else
    echo "WORKER_SLOT=$SLOT" > "$ENV_FILE"
    echo "✅ .env oluşturuldu: WORKER_SLOT=$SLOT"
fi

mkdir -p "$SCRIPTS_DIR"

# post-merge hook
cat > "$SCRIPTS_DIR/post-merge" <<'HOOK'
#!/usr/bin/env bash
set -euo pipefail
REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ENV_FILE="$REPO_DIR/.env"
SLOT_FILE="$REPO_DIR/.worker-slot"

echo ""
echo "╔══════════════════════════════════════════════╗"
echo "║  Nexus Mail — post-merge env updater         ║"
echo "╚══════════════════════════════════════════════╝"
echo ""

# WORKER_SLOT senkronize et
if [ -f "$SLOT_FILE" ]; then
    SLOT=$(cat "$SLOT_FILE" | tr -d '[:space:]')
    if [ -f "$ENV_FILE" ]; then
        if grep -qE '^WORKER_SLOT=' "$ENV_FILE"; then
            sed -i.bak "s/^WORKER_SLOT=.*/WORKER_SLOT=$SLOT/" "$ENV_FILE" && rm -f "${ENV_FILE}.bak"
        else
            echo "" >> "$ENV_FILE"
            echo "WORKER_SLOT=$SLOT" >> "$ENV_FILE"
        fi
        echo "✅ WORKER_SLOT=$SLOT (.env güncellendi)"
    fi
fi

# npm install (yeni paket geldiyse)
WORKER_DIR="$REPO_DIR"
[ -f "$REPO_DIR/email-worker/package.json" ] && WORKER_DIR="$REPO_DIR/email-worker"
if command -v npm &>/dev/null && [ -f "$WORKER_DIR/package.json" ]; then
    echo "▶  npm install..."
    cd "$WORKER_DIR" && npm install --silent 2>&1 | tail -3 && echo "✅ npm ok" || echo "⚠️  npm install başarısız"
    cd "$REPO_DIR"
fi

# PM2 restart
if command -v pm2 &>/dev/null; then
    echo "▶  PM2 email-worker restart..."
    pm2 restart email-worker 2>/dev/null && echo "✅ PM2 restart ok" \
        || echo "⚠️  PM2 restart başarısız (ilk kurulumsa normal)"
fi

echo ""
echo "✅ post-merge tamamlandı"
echo ""
HOOK

chmod +x "$SCRIPTS_DIR/post-merge"

cat > "$HOOKS_DIR/post-merge" <<HOOK
#!/usr/bin/env bash
bash "\$(cd "\$(dirname "\$0")/../.." && pwd)/bin/git-hooks/post-merge"
HOOK
chmod +x "$HOOKS_DIR/post-merge"

echo ""
echo "✅ nexus-mail git hooks kuruldu (slot=$SLOT)"
echo "   Artık her 'git pull' sonrası WORKER_SLOT otomatik güncellenir ve pm2 restart olur."
