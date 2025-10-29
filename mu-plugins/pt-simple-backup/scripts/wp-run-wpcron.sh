#!/usr/bin/env bash
set -euo pipefail

PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
PHP_INI="/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini"
WP_PATH="/home/plugintema.com/public_html"
WP="/usr/bin/wp"

cd "$WP_PATH"

# sanity check: precisa listar 'mysqli'
if ! "$PHP_BIN" -c "$PHP_INI" -m 2>/dev/null | grep -iq mysqli; then
  echo "[WP-CRON] ERRO: PHP sem mysqli (${PHP_BIN})." >&2
  exit 1
fi

# Chama o WP-CLI usando explicitamente o PHP/ini corretos
exec "$PHP_BIN" -c "$PHP_INI" "$WP" cron event run --due-now --path="$WP_PATH" --quiet
