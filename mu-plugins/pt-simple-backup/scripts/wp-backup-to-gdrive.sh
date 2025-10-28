#!/usr/bin/env bash
set -euo pipefail

log() {
  printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

fail() {
  log "ERROR: $*"
  exit 1
}

metrics_now_ms() {
  date +%s%3N
}

declare -A PTSB_METRICS_STEP_START=()
declare -A PTSB_METRICS_STEP_DURATION=()

PTSB_METRICS_START_MS=$(metrics_now_ms)
PTSB_METRICS_START_ISO=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
PTSB_METRICS_BYTES=0
PTSB_METRICS_SUCCESS=0
PTSB_METRICS_CLK_TCK=$(getconf CLK_TCK 2>/dev/null || echo 100)
PTSB_CPU_U_START=0
PTSB_CPU_S_START=0
PTSB_CPU_CU_START=0
PTSB_CPU_CS_START=0

if [[ -r "/proc/$$/stat" ]]; then
  read -r PTSB_CPU_U_START PTSB_CPU_S_START PTSB_CPU_CU_START PTSB_CPU_CS_START < <(awk '{print $14" "$15" "$16" "$17}' "/proc/$$/stat") || true
fi

metrics_step_begin() {
  local key="$1"
  PTSB_METRICS_STEP_START["$key"]=$(metrics_now_ms)
}

metrics_step_end() {
  local key="$1"
  local start="${PTSB_METRICS_STEP_START[$key]:-}"
  [[ -z "$start" ]] && return
  local end
  end=$(metrics_now_ms)
  if [[ "$end" =~ ^[0-9]+$ && "$start" =~ ^[0-9]+$ ]]; then
    local diff=$((end - start))
    (( diff < 0 )) && diff=0
    PTSB_METRICS_STEP_DURATION["$key"]=$diff
  fi
}

metrics_json_escape() {
  local raw="$1"
  raw=${raw//\\/\\\\}
  raw=${raw//\"/\\\"}
  raw=${raw//$'\n'/\\n}
  raw=${raw//$'\r'/\\r}
  raw=${raw//$'\t'/\\t}
  printf '%s' "$raw"
}

metrics_emit_summary() {
  local end_ms
  end_ms=$(metrics_now_ms)
  local duration_ms=$((end_ms - PTSB_METRICS_START_MS))
  (( duration_ms < 0 )) && duration_ms=0

  local ended_iso
  ended_iso=$(date -u '+%Y-%m-%dT%H:%M:%SZ')

  local cpu_u=0 cpu_s=0 cpu_cu=0 cpu_cs=0
  if [[ -r "/proc/$$/stat" ]]; then
    read -r cpu_u cpu_s cpu_cu cpu_cs < <(awk '{print $14" "$15" "$16" "$17}' "/proc/$$/stat") || true
  fi

  local cpu_ticks=$(( (cpu_u - PTSB_CPU_U_START) + (cpu_s - PTSB_CPU_S_START) + (cpu_cu - PTSB_CPU_CU_START) + (cpu_cs - PTSB_CPU_CS_START) ))
  (( cpu_ticks < 0 )) && cpu_ticks=0

  local cpu_seconds
  cpu_seconds=$(awk -v ticks="$cpu_ticks" -v hz="$PTSB_METRICS_CLK_TCK" 'BEGIN { if (hz <= 0) hz = 100; printf "%.3f", ticks / hz }')

  local io_wait_seconds
  io_wait_seconds=$(awk -v ms="$duration_ms" -v cpu="$cpu_seconds" 'BEGIN { wall = ms / 1000.0; c = cpu + 0.0; wait = wall - c; if (wait < 0) wait = 0; printf "%.3f", wait }')

  local peak_kb=0
  if [[ -r "/proc/$$/status" ]]; then
    peak_kb=$(awk '/VmHWM:/ {print $2}' "/proc/$$/status" 2>/dev/null | head -n1)
    peak_kb=${peak_kb:-0}
  fi
  local peak_bytes=$((peak_kb * 1024))

  local steps_json="{}"
  local -a order=("dump_db" "compress_db" "archive_bundle" "compress_bundle" "upload" "retention")
  local -a step_entries=()
  for key in "${order[@]}"; do
    local value="${PTSB_METRICS_STEP_DURATION[$key]:-}"
    [[ -z "$value" ]] && continue
    step_entries+=("\"$key\":$value")
  done
  if (( ${#step_entries[@]} )); then
    local steps_joined
    local IFS=','
    steps_joined="${step_entries[*]}"
    steps_json="{${steps_joined}}"
  fi

  local started_escaped
  started_escaped=$(metrics_json_escape "$PTSB_METRICS_START_ISO")
  local ended_escaped
  ended_escaped=$(metrics_json_escape "$ended_iso")

  local bytes_transferred="${PTSB_METRICS_BYTES:-0}"
  [[ -z "$bytes_transferred" ]] && bytes_transferred=0

  log "METRICS {\"started_at\":\"${started_escaped}\",\"finished_at\":\"${ended_escaped}\",\"duration_ms\":${duration_ms},\"bytes_transferred\":${bytes_transferred},\"cpu_seconds\":${cpu_seconds},\"io_wait_seconds\":${io_wait_seconds},\"peak_memory_bytes\":${peak_bytes},\"steps\":${steps_json}}"
}

cleanup() {
  if [[ ${PTSB_METRICS_SUCCESS:-0} -eq 1 ]]; then
    metrics_emit_summary
  fi
  if [[ -n "${WORK_DIR:-}" && -d "${WORK_DIR:-}" ]]; then
    rm -rf "$WORK_DIR"
  fi
}

trap cleanup EXIT
umask 077

log "=== Start WP backup $(date -u '+%Y-%m-%dT%H:%M:%SZ')"

REMOTE="${REMOTE:-}"
WP_PATH="${WP_PATH:-}"
PARTS_RAW="${PARTS:-}"
PREFIX="${PREFIX:-wpb-}"
KEEP_DAYS="${KEEP_DAYS:-${KEEP:-0}}"
KEEP_FOREVER="${KEEP_FOREVER:-0}"

[[ -z "$REMOTE" ]] && fail "REMOTE não informado"
[[ -z "$WP_PATH" ]] && fail "WP_PATH não informado"

if [[ ! -d "$WP_PATH" ]]; then
  fail "WP_PATH inválido: $WP_PATH"
fi

command -v mysqldump >/dev/null 2>&1 || fail "mysqldump não encontrado"
command -v rclone >/dev/null 2>&1 || fail "rclone não encontrado"
command -v tar >/dev/null 2>&1 || fail "tar não encontrado"

WORK_DIR="$(mktemp -d /tmp/ptsb.XXXXXX)"
SQL_FILE=""
SQL_LABEL="database.sql"

# Normaliza partes
IFS=',' read -r -a PARTS_ARR <<<"$PARTS_RAW"
declare -A WANT
for raw in "${PARTS_ARR[@]}"; do
  key="$(echo "$raw" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
  [[ -n "$key" ]] && WANT[$key]=1
done

contains_part() {
  [[ -n "${WANT[$1]:-}" ]]
}

# Dump do banco (se solicitado)
if contains_part "db"; then
  log "Dumping DB"
  CREDS=$(WP_PATH="$WP_PATH" php <<'PHP'
<?php
$path = rtrim(getenv('WP_PATH') ?: '', "/\");
if ($path === '') { fwrite(STDERR, "WP_PATH indefinido\n"); exit(1); }
define('SHORTINIT', true);
require $path . '/wp-config.php';
$host = DB_HOST;
$charset = defined('DB_CHARSET') ? DB_CHARSET : '';
echo DB_NAME, "\n", DB_USER, "\n", DB_PASSWORD, "\n", $host, "\n", $charset, "\n";
PHP
)
  if [[ $? -ne 0 || -z "$CREDS" ]]; then
    fail "Não foi possível obter credenciais do banco"
  fi
  IFS=$'\n' read -r DB_NAME DB_USER DB_PASS DB_HOST DB_CHARSET <<<"$CREDS"
  DB_CHARSET=${DB_CHARSET:-utf8mb4}
  DB_HOST=${DB_HOST:-localhost}

  DB_PORT=""
  DB_SOCKET=""
  if [[ "$DB_HOST" == *:* ]]; then
    HOST_LEFT="${DB_HOST%%:*}"
    HOST_RIGHT="${DB_HOST#*:}"
    if [[ "$HOST_RIGHT" == /* ]]; then
      DB_SOCKET="$HOST_RIGHT"
      DB_HOST="$HOST_LEFT"
    else
      DB_PORT="$HOST_RIGHT"
      DB_HOST="$HOST_LEFT"
    fi
  fi

  SQL_FILE="$WORK_DIR/database.sql"
  declare -a DUMP_CMD
  DUMP_CMD=("mysqldump" "--single-transaction" "--quick" "--routines" "--events" "--triggers" "--hex-blob" "--default-character-set=${DB_CHARSET}" "-h" "$DB_HOST" "-u" "$DB_USER")
  [[ -n "$DB_PORT" ]] && DUMP_CMD+=("-P" "$DB_PORT")
  [[ -n "$DB_SOCKET" ]] && DUMP_CMD+=("--socket" "$DB_SOCKET")
  DUMP_CMD+=("$DB_NAME")
  metrics_step_begin "dump_db"
  MYSQL_PWD="$DB_PASS" "${DUMP_CMD[@]}" > "$SQL_FILE"
  metrics_step_end "dump_db"

  metrics_step_begin "compress_db"
  if command -v pigz >/dev/null 2>&1; then
    log "Compressing DB dump with pigz"
    pigz -9 "$SQL_FILE"
    SQL_FILE+=".gz"
    SQL_LABEL="database.sql.gz"
  else
    log "Compressing DB dump with gzip"
    gzip -9 "$SQL_FILE"
    SQL_FILE+=".gz"
    SQL_LABEL="database.sql.gz"
  fi
  metrics_step_end "compress_db"
fi

# Monta lista de itens para o TAR
log "Archiving selected parts"

# Helper para adicionar item (-C path item)
add_tar_item() {
  local base="$1"
  local entry="$2"
  if [[ -e "$base/$entry" ]]; then
    TAR_ITEMS+=("-C" "$base" "$entry")
  fi
}

TAR_ITEMS=()

if [[ -n "$SQL_FILE" && -f "$SQL_FILE" ]]; then
  TAR_ITEMS+=("-C" "$WORK_DIR" "$SQL_LABEL")
fi

if contains_part "plugins"; then add_tar_item "$WP_PATH" "wp-content/plugins"; fi
if contains_part "themes"; then add_tar_item "$WP_PATH" "wp-content/themes"; fi
if contains_part "uploads"; then add_tar_item "$WP_PATH" "wp-content/uploads"; fi
if contains_part "core"; then
  add_tar_item "$WP_PATH" "wp-admin"
  add_tar_item "$WP_PATH" "wp-includes"
fi
if contains_part "langs" || contains_part "others"; then
  add_tar_item "$WP_PATH" "wp-content/languages"
fi
if contains_part "config" || contains_part "others"; then
  add_tar_item "$WP_PATH" "wp-config.php"
  add_tar_item "$WP_PATH" ".htaccess"
fi
if contains_part "scripts"; then
  if [[ -d "$WP_PATH/wp-content/mu-plugins" ]]; then
    add_tar_item "$WP_PATH" "wp-content/mu-plugins"
  fi
  parent_dir="$(dirname "$WP_PATH")"
  if [[ -d "$parent_dir/Scripts" ]]; then
    add_tar_item "$parent_dir" "Scripts"
  fi
fi

if [[ ${#TAR_ITEMS[@]} -eq 0 ]]; then
  fail "Nenhuma parte encontrada para arquivar"
fi

TIMESTAMP="$(date '+%Y%m%d-%H%M%S')"
BASE_NAME="${PREFIX}${TIMESTAMP}"

if [[ -n "${PARTS_CHUNK_TOTAL:-}" && "${PARTS_CHUNK_TOTAL:-1}" -gt 1 ]]; then
  IDX=$(printf '%02d' "${PARTS_CHUNK_INDEX:-1}")
  TOT=$(printf '%02d' "${PARTS_CHUNK_TOTAL:-1}")
  LABEL="chunk${IDX}of${TOT}"
  if [[ -n "${PARTS_CHUNK_LABEL:-}" ]]; then
    SAFE_LABEL="$(echo "$PARTS_CHUNK_LABEL" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
    [[ -n "$SAFE_LABEL" ]] && LABEL+="-${SAFE_LABEL}"
  fi
  BASE_NAME+="-${LABEL}"
fi

BUNDLE_TAR="$WORK_DIR/${BASE_NAME}.tar"
BUNDLE_GZ="$BUNDLE_TAR.gz"

log "Creating final bundle"
metrics_step_begin "archive_bundle"
tar -cf "$BUNDLE_TAR" "${TAR_ITEMS[@]}"
metrics_step_end "archive_bundle"

metrics_step_begin "compress_bundle"
if command -v pigz >/dev/null 2>&1; then
  log "Compressing bundle with pigz"
  pigz -9 "$BUNDLE_TAR"
else
  log "Compressing bundle with gzip"
  gzip -9 "$BUNDLE_TAR"
fi
metrics_step_end "compress_bundle"

if [[ -f "$BUNDLE_GZ" ]]; then
  PTSB_METRICS_BYTES=$(stat -c%s "$BUNDLE_GZ" 2>/dev/null || echo 0)
  PTSB_METRICS_BYTES=${PTSB_METRICS_BYTES:-0}
fi

REMOTE_TRIM="${REMOTE%/}"
if [[ "$REMOTE_TRIM" == *: ]]; then
  REMOTE_TARGET="${REMOTE_TRIM}${BASE_NAME}.tar.gz"
else
  REMOTE_TARGET="${REMOTE_TRIM}/${BASE_NAME}.tar.gz"
fi

log "Uploading to $REMOTE_TARGET"
metrics_step_begin "upload"
rclone copyto "$BUNDLE_GZ" "$REMOTE_TARGET"
metrics_step_end "upload"

log "Uploaded and removing local bundle"
rm -f "$BUNDLE_GZ"

apply_retention() {
  local keep="$1"
  local forever="$2"
  if [[ "$forever" == "1" ]]; then
    log "Retention skipped (sempre manter)"
    return
  fi
  if [[ "$keep" -le 0 ]]; then
    log "Retention skipped (keep_days=0)"
    return
  fi

  local cutoff
  cutoff=$(date -u -d "-$keep days" '+%s') || cutoff=""
  [[ -z "$cutoff" ]] && return

  log "Applying retention (${keep}d)"
  mapfile -t entries < <(rclone lsf "$REMOTE_TRIM" --files-only --format "pt" --separator ";" --include "*.tar.gz" 2>/dev/null || true)
  for entry in "${entries[@]}"; do
    [[ -z "$entry" ]] && continue
    local ts="${entry%%;*}"
    local file="${entry##*;}"
    local epoch
    epoch=$(date -u -d "$ts" '+%s' 2>/dev/null || true)
    [[ -z "$epoch" ]] && continue
    if (( epoch < cutoff )); then
      local keep_sidecar
      keep_sidecar="${file}.keep"
      local keep_exists
      keep_exists=$(rclone lsf "$REMOTE_TRIM" --files-only --include "$keep_sidecar" 2>/dev/null || true)
      if [[ -n "$keep_exists" ]]; then
        log "Retention: pulando $file (keep ativo)"
        continue
      fi
      local target
      if [[ "$REMOTE_TRIM" == *: ]]; then
        target="${REMOTE_TRIM}${file}"
        json_target="${REMOTE_TRIM}${file%.tar.gz}.json"
      else
        target="${REMOTE_TRIM}/${file}"
        json_target="${REMOTE_TRIM}/${file%.tar.gz}.json"
      fi
      log "Retention: removendo $file"
      rclone deletefile "$target" 2>/dev/null || true
      rclone deletefile "$json_target" 2>/dev/null || true
    fi
  done
}

metrics_step_begin "retention"
apply_retention "${KEEP_DAYS:-0}" "$KEEP_FOREVER"
metrics_step_end "retention"

log "Backup finished successfully."
PTSB_METRICS_SUCCESS=1
