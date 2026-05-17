#!/bin/bash
# Doctrine migrations - PHP path fallback (MAMP, server compatible)
set -e

cd "$(dirname "$0")/.."

# Find PHP binary
PHP_BIN=""
if command -v php &>/dev/null; then
  PHP_BIN=$(command -v php)
elif [ -x /usr/bin/php ]; then
  PHP_BIN=/usr/bin/php
elif [ -x /usr/local/bin/php ]; then
  PHP_BIN=/usr/local/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.5.0/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.5.0/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.4.15/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.4.15/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.3.28/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.3.28/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.4/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.4/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.3/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.3/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.2/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.2/bin/php
elif [ -x /Applications/MAMP/bin/php/php8.1/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php8.1/bin/php
elif [ -x /Applications/MAMP/bin/php/php7.4/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php7.4/bin/php
elif [ -x /Applications/MAMP/bin/php/php7.3/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php7.3/bin/php
elif [ -x /Applications/MAMP/bin/php/php/bin/php ]; then
  PHP_BIN=/Applications/MAMP/bin/php/php/bin/php
else
  PHP_BIN="php"
fi

echo "PHP: $PHP_BIN"
echo "Running Doctrine migrations..."

DB_CONFIG="migrations-db.php"
if [ ! -f "$DB_CONFIG" ] && [ -f "config/migrations-db.php" ]; then
  DB_CONFIG="config/migrations-db.php"
fi

# İki ayrı migrations dizini (database/migrations ve database/migrations_doctrine) için
# sırayla migrate — tek config ile diğer klasör hiç çalışmıyordu.
for MIGRATIONS_CONFIG in "config/migrations.php" "config/migrations-doctrine.php"; do
  if [ -f "$MIGRATIONS_CONFIG" ]; then
    echo "==> doctrine-migrations ($MIGRATIONS_CONFIG)"
    $PHP_BIN vendor/bin/doctrine-migrations migrate --no-interaction \
      --db-configuration="$DB_CONFIG" \
      --configuration="$MIGRATIONS_CONFIG" \
      "$@"
  fi
done
