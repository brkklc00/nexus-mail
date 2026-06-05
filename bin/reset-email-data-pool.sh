#!/usr/bin/env bash
#
# Mail havuzu tablolarını sıfırlar; önce bloklayıcı MySQL oturumlarını sonlandırır.
#
# Kullanım:
#   sudo bash bin/reset-email-data-pool.sh --force
#   sudo bash bin/reset-email-data-pool.sh --force --keep-lists
#   sudo bash bin/reset-email-data-pool.sh --force --clear-alibaba
#
# --force              Zorunlu (yanlışlıkla çalışmayı önler)
# --keep-lists           Listeleri silmez; sadece mail kayıtları + cache/job tablolarını temizler
# --clear-alibaba        alibaba_invalid_* tablolarını da temizler
# --skip-service-stop    pm2 / php-fpm durdurma ve yeniden başlatmayı atlar
# --default-list NAME    Yeni varsayılan liste adı (varsayılan: Liste 1)
#
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FORCE=0
KEEP_LISTS=0
CLEAR_ALIBABA=0
SKIP_SERVICE_STOP=0
DEFAULT_LIST_NAME="Liste 1"
PHP_FPM_WAS=0
MYSQL_CNF=""

usage() {
  cat <<'EOF'
Mail havuzu sıfırlama (PROCESSLIST temizliği + TRUNCATE)

  sudo bash bin/reset-email-data-pool.sh --force
  sudo bash bin/reset-email-data-pool.sh --force --keep-lists
  sudo bash bin/reset-email-data-pool.sh --force --clear-alibaba
  sudo bash bin/reset-email-data-pool.sh --force --skip-service-stop

EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --force) FORCE=1 ;;
    --keep-lists) KEEP_LISTS=1 ;;
    --clear-alibaba) CLEAR_ALIBABA=1 ;;
    --skip-service-stop) SKIP_SERVICE_STOP=1 ;;
    --default-list)
      shift
      DEFAULT_LIST_NAME="${1:-}"
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Bilinmeyen argüman: $1" >&2
      usage >&2
      exit 1
      ;;
  esac
  shift
done

if [[ "$FORCE" -ne 1 ]]; then
  echo "UYARI: Bu işlem mail havuzu verilerini kalıcı olarak siler." >&2
  echo "Devam etmek için: sudo bash bin/reset-email-data-pool.sh --force" >&2
  echo "Sadece mailleri sil (listeler kalsın): --force --keep-lists" >&2
  exit 1
fi

if [[ -z "$DEFAULT_LIST_NAME" ]]; then
  echo "Hata: --default-list boş olamaz." >&2
  exit 1
fi

load_env() {
  if [[ ! -f .env ]]; then
    echo "Hata: .env bulunamadı ($ROOT/.env)" >&2
    exit 1
  fi

  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%%#*}"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    [[ -z "$line" ]] && continue
    if [[ "$line" =~ ^(DB_[A-Z_]+)=(.*)$ ]]; then
      key="${BASH_REMATCH[1]}"
      val="${BASH_REMATCH[2]}"
      val="${val#\"}"; val="${val%\"}"
      val="${val#\'}"; val="${val%\'}"
      printf -v "$key" '%s' "$val"
    fi
  done < .env

  DB_HOST="${DB_HOST:-127.0.0.1}"
  DB_NAME="${DB_NAME:-}"
  DB_USER="${DB_USER:-root}"
  DB_PASSWORD="${DB_PASSWORD:-}"

  if [[ "$DB_HOST" == "localhost" ]]; then
    DB_HOST="127.0.0.1"
  fi

  if [[ -z "$DB_NAME" ]]; then
    echo "Hata: .env içinde DB_NAME tanımlı değil." >&2
    exit 1
  fi
}

setup_mysql() {
  MYSQL_CNF="$(mktemp)"
  chmod 600 "$MYSQL_CNF"
  cat >"$MYSQL_CNF" <<EOF
[client]
host=${DB_HOST}
user=${DB_USER}
password=${DB_PASSWORD}
database=${DB_NAME}
EOF
}

cleanup() {
  if [[ -n "$MYSQL_CNF" && -f "$MYSQL_CNF" ]]; then
    rm -f "$MYSQL_CNF"
  fi
}

trap cleanup EXIT

mysql_cmd() {
  mysql --defaults-extra-file="$MYSQL_CNF" "$@"
}

mysql_query() {
  mysql_cmd -Nse "$1"
}

mysql_exec() {
  mysql_cmd -e "$1"
}

table_exists() {
  local table="$1"
  mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '${table}'"
}

count_table() {
  local table="$1"
  if [[ "$(table_exists "$table")" != "1" ]]; then
    echo 0
    return
  fi
  mysql_query "SELECT COUNT(*) FROM \`${table//\`/\`\`}\`"
}

truncate_table() {
  local table="$1"
  if [[ "$(table_exists "$table")" != "1" ]]; then
    echo "  atlandı (yok): ${table}"
    return
  fi
  mysql_exec "TRUNCATE TABLE \`${table//\`/\`\`}\`"
  echo "  temizlendi: ${table}"
}

stop_services() {
  if [[ "$SKIP_SERVICE_STOP" -eq 1 ]]; then
    echo "Servis durdurma atlandı (--skip-service-stop)"
    return
  fi

  echo "Servisler durduruluyor..."
  if command -v pm2 >/dev/null 2>&1; then
    pm2 stop all 2>/dev/null || true
  fi
  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
    sudo systemctl stop php8.3-fpm
    PHP_FPM_WAS=1
  fi
  sleep 1
}

start_services() {
  if [[ "$SKIP_SERVICE_STOP" -eq 1 ]]; then
    return
  fi

  echo "Servisler başlatılıyor..."
  if [[ "$PHP_FPM_WAS" -eq 1 ]]; then
    sudo systemctl start php8.3-fpm
  fi
  if command -v pm2 >/dev/null 2>&1 && [[ -f ecosystem.config.js ]]; then
    pm2 start ecosystem.config.js 2>/dev/null || pm2 start all 2>/dev/null || true
    pm2 save 2>/dev/null || true
  fi
}

kill_db_sessions() {
  echo "PROCESSLIST kontrol ediliyor (${DB_NAME})..."

  local rows
  rows="$(mysql_query "
    SELECT CONCAT(id, '\t', user, '\t', time, 's\t', IFNULL(state,''), '\t', LEFT(IFNULL(info,''), 100))
    FROM information_schema.processlist
    WHERE db = '${DB_NAME}'
      AND id != CONNECTION_ID()
    ORDER BY time DESC
  " 2>/dev/null || true)"

  if [[ -z "$rows" ]]; then
    echo "  bloklayıcı oturum yok"
    return
  fi

  echo "$rows" | while IFS=$'\t' read -r pid puser ptime pstate pquery; do
    [[ -z "${pid:-}" ]] && continue
    echo "  Id=${pid} user=${puser} time=${ptime} state=${pstate}"
    if [[ -n "${pquery:-}" ]]; then
      echo "    query: ${pquery}"
    fi
  done

  local attempt ids
  for attempt in 1 2 3; do
    ids="$(mysql_query "
      SELECT id
      FROM information_schema.processlist
      WHERE db = '${DB_NAME}'
        AND id != CONNECTION_ID()
    " 2>/dev/null || true)"

    if [[ -z "$ids" ]]; then
      echo "  tüm oturumlar sonlandırıldı"
      return
    fi

    while read -r id; do
      [[ -z "$id" ]] && continue
      echo "  KILL CONNECTION ${id} (deneme ${attempt})"
      mysql_exec "KILL CONNECTION ${id};" 2>/dev/null \
        || mysql_exec "KILL ${id};" 2>/dev/null \
        || echo "    uyarı: Id ${id} sonlandırılamadı" >&2
    done <<< "$ids"

    sleep 2
  done

  ids="$(mysql_query "
    SELECT id
    FROM information_schema.processlist
    WHERE db = '${DB_NAME}'
      AND id != CONNECTION_ID()
  " 2>/dev/null || true)"

  if [[ -n "$ids" ]]; then
    echo "Uyarı: bazı oturumlar hâlâ açık (root ile tekrar deneyin veya --skip-service-stop kullanmayın):" >&2
    echo "$ids" | while read -r id; do
      [[ -n "$id" ]] && echo "  Id=${id}" >&2
    done
  fi
}

reset_tables() {
  mysql_exec "SET FOREIGN_KEY_CHECKS = 0"

  truncate_table "email_data_pool"
  truncate_table "email_data_pool_analysis_cache"
  truncate_table "email_data_pool_analysis_jobs"
  truncate_table "email_pool_stats"
  truncate_table "data_pool_jobs"

  if [[ "$CLEAR_ALIBABA" -eq 1 ]]; then
    truncate_table "alibaba_invalid_addresses"
    truncate_table "alibaba_invalid_fetch_logs"
  fi

  if [[ "$KEEP_LISTS" -eq 1 ]]; then
    if [[ "$(table_exists email_data_pool_lists)" == "1" ]]; then
      mysql_exec "UPDATE email_data_pool_lists SET total_count = 0, active_count = 0, passive_count = 0, updated_count_at = NOW()"
      echo "  sıfırlandı: email_data_pool_lists (sayılar)"
    fi
  else
    truncate_table "email_data_pool_lists"
    local esc_name="${DEFAULT_LIST_NAME//\'/\'\'}"
    mysql_exec "INSERT INTO email_data_pool_lists
      (id, name, sort_order, created_at, total_count, active_count, passive_count, updated_count_at)
      VALUES (1, '${esc_name}', 0, NOW(), 0, 0, 0, NOW())"
    mysql_exec "ALTER TABLE email_data_pool_lists AUTO_INCREMENT = 2"
    echo "  oluşturuldu: varsayılan liste \"${DEFAULT_LIST_NAME}\""
  fi

  mysql_exec "SET FOREIGN_KEY_CHECKS = 1"
}

clear_cache() {
  if [[ ! -d var/cache ]]; then
    return
  fi
  find var/cache -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
  echo "  temizlendi: var/cache/"
}

load_env
setup_mysql

if ! mysql_cmd -e "SELECT 1" >/dev/null 2>&1; then
  echo "Veritabanı bağlantı hatası (.env DB_* değerlerini kontrol edin)." >&2
  exit 1
fi

echo ""
echo "Mail Havuzu Sıfırlama"
echo "────────────────────────────────────────"
if [[ "$KEEP_LISTS" -eq 1 ]]; then
  echo "Mod: sadece kayıtlar (--keep-lists)"
else
  echo "Mod: listeler + kayıtlar"
fi
echo "Önce:"
echo "  email_data_pool: $(count_table email_data_pool)"
echo "  email_data_pool_lists: $(count_table email_data_pool_lists)"
echo ""

stop_services
kill_db_sessions

echo ""
echo "Tablolar temizleniyor..."
reset_tables
clear_cache

start_services

echo ""
echo "Sonra:"
echo "  email_data_pool: $(count_table email_data_pool)"
echo "  email_data_pool_lists: $(count_table email_data_pool_lists)"
echo ""
echo "OK — mail havuzu sıfırlandı."
