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
# --skip-service-stop      pm2 / php-fpm durdurma ve yeniden başlatmayı atlar
# --restart-mysql          KILL başarısız olursa MySQL servisini yeniden başlatır (sudo)
# --default-list NAME      Yeni varsayılan liste adı (varsayılan: Liste 1)
#
set -eu

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FORCE=0
KEEP_LISTS=0
CLEAR_ALIBABA=0
SKIP_SERVICE_STOP=0
RESTART_MYSQL=0
DEFAULT_LIST_NAME="Liste 1"
PHP_FPM_WAS=0
MYSQL_CNF=""
MYSQL_ROOT_CNF=""
LOCK_WAIT_SEC=10
KILL_ATTEMPTS=15

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
    --restart-mysql) RESTART_MYSQL=1 ;;
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

setup_mysql_root() {
  MYSQL_ROOT_CNF=""
  local root_host=""

  if [[ "${EUID:-$(id -u)}" -eq 0 ]] && mysql -e "SELECT 1" >/dev/null 2>&1; then
    root_host=""
  elif mysql -uroot -h127.0.0.1 -e "SELECT 1" >/dev/null 2>&1; then
    root_host="127.0.0.1"
  elif mysql -uroot -e "SELECT 1" >/dev/null 2>&1; then
    root_host=""
  else
    return 1
  fi

  MYSQL_ROOT_CNF="$(mktemp)"
  chmod 600 "$MYSQL_ROOT_CNF"
  if [[ -n "$root_host" ]]; then
    cat >"$MYSQL_ROOT_CNF" <<'EOF'
[client]
user=root
host=127.0.0.1
EOF
  else
    cat >"$MYSQL_ROOT_CNF" <<'EOF'
[client]
user=root
EOF
  fi
}

cleanup() {
  if [[ -n "$MYSQL_CNF" && -f "$MYSQL_CNF" ]]; then
    rm -f "$MYSQL_CNF"
  fi
  if [[ -n "$MYSQL_ROOT_CNF" && -f "$MYSQL_ROOT_CNF" ]]; then
    rm -f "$MYSQL_ROOT_CNF"
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

mysql_root_cmd() {
  [[ -n "$MYSQL_ROOT_CNF" ]] || return 1
  mysql --defaults-extra-file="$MYSQL_ROOT_CNF" "$@"
}

mysql_root_query() {
  mysql_root_cmd -Nse "$1"
}

mysql_root_exec() {
  mysql_root_cmd -e "$1"
}

session_ids_for_db() {
  local sql="
    SELECT id
    FROM information_schema.processlist
    WHERE id != CONNECTION_ID()
      AND (
        db = '${DB_NAME}'
        OR IFNULL(info, '') LIKE '%email_data_pool%'
        OR IFNULL(info, '') LIKE '%email_data_pool_%'
      )
    ORDER BY time DESC
  "
  if [[ -n "$MYSQL_ROOT_CNF" ]]; then
    mysql_root_query "$sql" 2>/dev/null || true
  else
    mysql_query "$sql" 2>/dev/null || true
  fi
}

kill_one_session() {
  local id="$1"
  local via="${2:-app}"

  echo "  KILL QUERY ${id} (${via})"
  if [[ "$via" == "root" && -n "$MYSQL_ROOT_CNF" ]]; then
    mysql_root_exec "KILL QUERY ${id};" 2>/dev/null || true
    mysql_root_exec "KILL CONNECTION ${id};" 2>/dev/null || mysql_root_exec "KILL ${id};" 2>/dev/null || true
  else
    mysql_exec "KILL QUERY ${id};" 2>/dev/null || true
    mysql_exec "KILL CONNECTION ${id};" 2>/dev/null || mysql_exec "KILL ${id};" 2>/dev/null || true
  fi
}

restart_mysql_service() {
  echo "MySQL servisi yeniden başlatılıyor (takılı oturumlar için)..."
  if command -v systemctl >/dev/null 2>&1; then
    systemctl restart mysql 2>/dev/null \
      || systemctl restart mariadb 2>/dev/null \
      || systemctl restart mysqld 2>/dev/null \
      || return 1
  else
    service mysql restart 2>/dev/null || service mariadb restart 2>/dev/null || return 1
  fi
  sleep 3
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

append_truncate() {
  local table="$1"
  local -n buf="$2"
  if [[ "$(table_exists "$table")" != "1" ]]; then
    echo "  atlandı (yok): ${table}"
    return
  fi
  echo "  truncate: ${table}..."
  buf+="TRUNCATE TABLE \`${table//\`/\`\`}\`;"
}

reset_tables() {
  local esc_name="${DEFAULT_LIST_NAME//\'/\'\'}"
  local sql=""

  sql+="SET SESSION innodb_lock_wait_timeout = ${LOCK_WAIT_SEC};"
  sql+="SET SESSION lock_wait_timeout = ${LOCK_WAIT_SEC};"
  sql+="SET FOREIGN_KEY_CHECKS = 0;"

  append_truncate "email_data_pool" sql
  append_truncate "email_data_pool_analysis_cache" sql
  append_truncate "email_data_pool_analysis_jobs" sql
  append_truncate "email_pool_stats" sql
  append_truncate "data_pool_jobs" sql

  if [[ "$CLEAR_ALIBABA" -eq 1 ]]; then
    append_truncate "alibaba_invalid_addresses" sql
    append_truncate "alibaba_invalid_fetch_logs" sql
  fi

  if [[ "$KEEP_LISTS" -eq 1 ]]; then
    if [[ "$(table_exists email_data_pool_lists)" == "1" ]]; then
      sql+="UPDATE email_data_pool_lists SET total_count = 0, active_count = 0, passive_count = 0, updated_count_at = NOW();"
      echo "  sıfırlanacak: email_data_pool_lists (sayılar)"
    fi
  else
    append_truncate "email_data_pool_lists" sql
    sql+="INSERT INTO email_data_pool_lists
      (id, name, sort_order, created_at, total_count, active_count, passive_count, updated_count_at)
      VALUES (1, '${esc_name}', 0, NOW(), 0, 0, 0, NOW());"
    sql+="ALTER TABLE email_data_pool_lists AUTO_INCREMENT = 2;"
  fi

  sql+="SET FOREIGN_KEY_CHECKS = 1;"

  if ! mysql_cmd -e "$sql"; then
    echo "" >&2
    echo "Hata: tablo temizleme başarısız (FK veya kilit)." >&2
    echo "  sudo bash bin/reset-email-data-pool.sh --force --restart-mysql" >&2
    exit 1
  fi

  echo "  temizlendi: email_data_pool"
  echo "  temizlendi: email_data_pool_analysis_cache"
  echo "  temizlendi: email_data_pool_analysis_jobs"
  echo "  temizlendi: email_pool_stats"
  echo "  temizlendi: data_pool_jobs"
  if [[ "$CLEAR_ALIBABA" -eq 1 ]]; then
    echo "  temizlendi: alibaba_invalid_addresses"
    echo "  temizlendi: alibaba_invalid_fetch_logs"
  fi
  if [[ "$KEEP_LISTS" -eq 1 ]]; then
    echo "  sıfırlandı: email_data_pool_lists (sayılar)"
  else
    echo "  temizlendi: email_data_pool_lists"
    echo "  oluşturuldu: varsayılan liste \"${DEFAULT_LIST_NAME}\""
  fi
}

stop_services() {
  if [[ "$SKIP_SERVICE_STOP" -eq 1 ]]; then
    echo "Servis durdurma atlandı (--skip-service-stop)"
    return
  fi

  echo "Servisler durduruluyor..."
  if command -v pm2 >/dev/null 2>&1; then
    pm2 stop all 2>/dev/null || true
    pm2 stop data-pool-worker 2>/dev/null || true
    pm2 stop email-worker 2>/dev/null || true
  fi
  if command -v systemctl >/dev/null 2>&1; then
    if systemctl is-active --quiet php8.3-fpm 2>/dev/null; then
      systemctl stop php8.3-fpm
      PHP_FPM_WAS=1
    fi
    systemctl stop php8.2-fpm 2>/dev/null || true
    systemctl stop php-fpm 2>/dev/null || true
  fi
  sleep 2
}

start_services() {
  if [[ "$SKIP_SERVICE_STOP" -eq 1 ]]; then
    return
  fi

  echo "Servisler başlatılıyor..."
  if [[ "$PHP_FPM_WAS" -eq 1 ]]; then
    systemctl start php8.3-fpm
  fi
  if command -v pm2 >/dev/null 2>&1 && [[ -f ecosystem.config.js ]]; then
    pm2 start ecosystem.config.js 2>/dev/null || pm2 start all 2>/dev/null || true
    pm2 save 2>/dev/null || true
  fi
}

print_blocking_sessions() {
  local q="
    SELECT id, user, time, IFNULL(state,''), LEFT(IFNULL(info,''), 120)
    FROM information_schema.processlist
    WHERE id != CONNECTION_ID()
      AND (
        db = '${DB_NAME}'
        OR IFNULL(info, '') LIKE '%email_data_pool%'
      )
    ORDER BY time DESC
  "
  if [[ -n "$MYSQL_ROOT_CNF" ]]; then
    mysql_root_cmd -e "$q" 2>/dev/null || mysql_cmd -e "$q" 2>/dev/null || true
  else
    mysql_cmd -e "$q" 2>/dev/null || true
  fi
}

kill_db_sessions() {
  echo "PROCESSLIST kontrol ediliyor (${DB_NAME})..."

  if setup_mysql_root; then
    echo "  MySQL root erişimi: var (zorla KILL için)"
  else
    echo "  MySQL root erişimi: yok (sudo ile çalıştırın veya --restart-mysql kullanın)"
  fi

  local ids attempt via remaining
  ids="$(session_ids_for_db)"
  if [[ -z "$ids" ]]; then
    echo "  bloklayıcı oturum yok"
    return 0
  fi

  echo "  takılı oturumlar:"
  print_blocking_sessions

  for attempt in $(seq 1 "$KILL_ATTEMPTS"); do
    ids="$(session_ids_for_db)"
    if [[ -z "$ids" ]]; then
      echo "  tüm oturumlar sonlandırıldı"
      return 0
    fi

    via="app"
    if [[ -n "$MYSQL_ROOT_CNF" ]]; then
      via="root"
    fi

    while read -r id; do
      [[ -z "$id" ]] && continue
      kill_one_session "$id" "$via"
    done <<< "$ids"

    sleep 2
  done

  remaining="$(session_ids_for_db)"
  if [[ -z "$remaining" ]]; then
    echo "  tüm oturumlar sonlandırıldı"
    return 0
  fi

  if [[ "$RESTART_MYSQL" -eq 1 ]] && [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
    restart_mysql_service || true
    setup_mysql
    setup_mysql_root || true
    remaining="$(session_ids_for_db)"
    if [[ -z "$remaining" ]]; then
      echo "  MySQL restart sonrası oturum kalmadı"
      return 0
    fi
  fi

  echo "" >&2
  echo "Hata: sonlandırılamayan MySQL oturumları var; TRUNCATE metadata lock'ta takılır." >&2
  echo "$remaining" | while read -r id; do
    [[ -n "$id" ]] && echo "  Id=${id}" >&2
  done
  echo "" >&2
  echo "Manuel (her Id için):" >&2
  echo "  sudo mysql -e \"KILL QUERY <id>; KILL CONNECTION <id>;\"" >&2
  echo "  sudo bash bin/reset-email-data-pool.sh --force --restart-mysql" >&2
  exit 1
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
