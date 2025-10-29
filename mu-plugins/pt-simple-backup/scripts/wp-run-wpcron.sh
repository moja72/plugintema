#!/usr/bin/env bash
set -euo pipefail

DEFAULT_PHP_BIN="/usr/local/lsws/lsphp83/bin/php"
DEFAULT_PHP_INI="/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini"
DEFAULT_WP_PATH="/home/plugintema.com/public_html"
DEFAULT_WP_CLI="/usr/bin/wp"

PHP_BIN="${PHP_BIN:-$DEFAULT_PHP_BIN}"
if [[ ! -x "$PHP_BIN" ]]; then
  PHP_BIN="$(command -v php || true)"
fi
if [[ -z "${PHP_BIN}" ]]; then
  echo "[WP-CRON] ERRO: PHP_BIN não encontrado." >&2
  exit 1
fi

PHP_INI="${PHP_INI:-$DEFAULT_PHP_INI}"
if [[ -n "$PHP_INI" && ! -f "$PHP_INI" ]]; then
  echo "[WP-CRON] ERRO: php.ini não encontrado (${PHP_INI})." >&2
  exit 1
fi

WP_PATH="${WP_PATH:-$DEFAULT_WP_PATH}"
if [[ ! -d "$WP_PATH" ]]; then
  echo "[WP-CRON] ERRO: WP_PATH inválido (${WP_PATH})." >&2
  exit 1
fi

WP_BIN="${WP_CLI_BIN:-${WP_BIN:-$DEFAULT_WP_CLI}}"
if [[ ! -x "$WP_BIN" ]]; then
  WP_BIN="$(command -v wp || true)"
fi
if [[ -z "$WP_BIN" ]]; then
  echo "[WP-CRON] ERRO: WP-CLI não encontrado." >&2
  exit 1
fi

cd "$WP_PATH"

PHP_ARGS=()
if [[ -n "$PHP_INI" ]]; then
  PHP_ARGS+=(-c "$PHP_INI")
fi

if ! "$PHP_BIN" "${PHP_ARGS[@]}" -m 2>/dev/null | grep -iq mysqli; then
  echo "[WP-CRON] ERRO: PHP sem mysqli (${PHP_BIN})." >&2
  exit 1
fi

exec "$PHP_BIN" "${PHP_ARGS[@]}" "$WP_BIN" cron event run --due-now --path="$WP_PATH" --quiet
