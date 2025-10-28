#!/usr/bin/env bash
# Executa eventos pendentes do WordPress via WP-CLI.
# Pode ser chamado pelo cron do sistema para manter o agendamento do plugin em dia.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WP_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
WP_PATH="${WP_PATH:-$WP_ROOT}"

if [[ ! -d "$WP_PATH" ]]; then
  echo "Diretório do WordPress inválido: $WP_PATH" >&2
  exit 1
fi

if command -v wp >/dev/null 2>&1; then
  WP_CLI_BIN="${WP_CLI_BIN:-$(command -v wp)}"
else
  WP_CLI_BIN="${WP_CLI_BIN:-/usr/local/bin/wp}"
fi

if [[ ! -x "$WP_CLI_BIN" ]]; then
  echo "wp-cli não encontrado ou sem permissão de execução: $WP_CLI_BIN" >&2
  exit 1
fi

cd "$WP_PATH"

# Executa eventos agendados do WordPress vencidos.
"$WP_CLI_BIN" cron event run --due-now --quiet
