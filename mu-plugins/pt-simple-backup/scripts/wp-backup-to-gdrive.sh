#!/usr/bin/env bash
set -euo pipefail

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

cleanup() {
  if [[ -n "${WORKDIR:-}" && -d "$WORKDIR" ]]; then
    rm -rf "$WORKDIR"
  fi
}

on_error() {
  local exit_code=$?
  local line_no=$1
  log "ERROR at line ${line_no}: backup aborted with status ${exit_code}."
  exit ${exit_code}
}

trap 'on_error ${LINENO}' ERR
trap cleanup EXIT

umask 0077

REMOTE=${REMOTE:-}
WP_PATH=${WP_PATH:-}
PREFIX=${PREFIX:-backup-}
KEEP_DAYS=${KEEP_DAYS:-0}
KEEP_FOREVER=${KEEP_FOREVER:-0}
PARTS=${PARTS:-db,plugins,themes,uploads,langs,config,scripts}
JOB_ID=${JOB_ID:-}
RUN_ORIGIN=${RUN_ORIGIN:-}
ORIGINAL_PARTS=${ORIGINAL_PARTS:-}
PARTS_CHUNK_INDEX=${PARTS_CHUNK_INDEX:-}
PARTS_CHUNK_TOTAL=${PARTS_CHUNK_TOTAL:-}
PARTS_CHUNK_LABEL=${PARTS_CHUNK_LABEL:-}

DB_NAME=${DB_NAME:-}
DB_USER=${DB_USER:-}
DB_PASSWORD=${DB_PASSWORD:-}
DB_HOST=${DB_HOST:-localhost}
DB_CHARSET=${DB_CHARSET:-utf8mb4}
DB_COLLATE=${DB_COLLATE:-}

if [[ -z "$REMOTE" || -z "$WP_PATH" ]]; then
  log "REMOTE and WP_PATH environment variables are required."
  exit 1
fi

if [[ ! -d "$WP_PATH" ]]; then
  log "WP_PATH '$WP_PATH' not found."
  exit 1
fi

if ! command -v rclone >/dev/null 2>&1; then
  log "rclone binary not found."
  exit 1
fi

WORKDIR=$(mktemp -d -t ptsb-backup-XXXXXX)
cd "$WP_PATH"

parts_array=()
IFS=',' read -r -a parts_array <<<"${PARTS}"

bundle_date=$(date '+%Y%m%d-%H%M%S')
chunk_suffix=""
if [[ -n "$PARTS_CHUNK_LABEL" ]]; then
  safe_label=$(echo "$PARTS_CHUNK_LABEL" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-_')
  if [[ -n "$safe_label" ]]; then
    chunk_suffix="-${safe_label}"
  fi
elif [[ -n "$PARTS_CHUNK_INDEX" && -n "$PARTS_CHUNK_TOTAL" ]]; then
  chunk_suffix="-part${PARTS_CHUNK_INDEX}of${PARTS_CHUNK_TOTAL}"
fi

bundle_name="${PREFIX}${bundle_date}${chunk_suffix}"
manifest_path="$WORKDIR/manifest.json"

have_db_dump=0
if printf '%s\n' "${parts_array[@]}" | grep -q '^db$'; then
  if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
    log "DB credentials missing; skipping database dump."
  else
    log "Starting MySQL dump for database '$DB_NAME'."
    dump_file="$WORKDIR/database.sql"
    MYSQLDUMP_BIN=${MYSQLDUMP_BIN:-mysqldump}
    if ! command -v "$MYSQLDUMP_BIN" >/dev/null 2>&1; then
      log "mysqldump binary '$MYSQLDUMP_BIN' not found; skipping database dump."
    else
      host_arg=("--host=${DB_HOST}")
      port_arg=()
      socket_arg=()
      if [[ "$DB_HOST" == *":"* ]]; then
        host_part="${DB_HOST%%:*}"
        rest="${DB_HOST#*:}"
        if [[ "$rest" =~ ^[0-9]+$ ]]; then
          host_arg=("--host=${host_part}")
          port_arg=("--port=${rest}")
        elif [[ "$rest" == /* ]]; then
          host_arg=("--host=${host_part}")
          socket_arg=("--socket=${rest}")
        fi
      fi
      set +e
      "$MYSQLDUMP_BIN" \
        --single-transaction \
        --quick \
        --hex-blob \
        --routines \
        --triggers \
        --events \
        --default-character-set="${DB_CHARSET}" \
        "${host_arg[@]}" \
        "${port_arg[@]}" \
        "${socket_arg[@]}" \
        --user="${DB_USER}" \
        --password="${DB_PASSWORD}" \
        ${DB_COLLATE:+--set-charset} \
        "${DB_NAME}" >"$dump_file"
      dump_status=$?
      set -e
      if [[ $dump_status -ne 0 || ! -s "$dump_file" ]]; then
        log "mysqldump failed (exit ${dump_status})."
      else
        log "Database dump completed." 
        have_db_dump=1
      fi
    fi
  fi
fi

add_path() {
  local rel_path="$1"
  if [[ -e "$WP_PATH/$rel_path" ]]; then
    tar_items+=("$rel_path")
  fi
}

tar_items=()
tar_externals=()
for part in "${parts_array[@]}"; do
  case "$part" in
    plugins)
      add_path "wp-content/plugins"
      ;;
    themes)
      add_path "wp-content/themes"
      ;;
    uploads)
      add_path "wp-content/uploads"
      ;;
    core)
      add_path "wp-admin"
      add_path "wp-includes"
      add_path "index.php"
      for file in wp-*.php; do
        [[ -e "$file" ]] && tar_items+=("$file")
      done
      ;;
    config)
      add_path "wp-config.php"
      add_path ".env"
      add_path ".env.local"
      add_path ".user.ini"
      add_path ".htaccess"
      add_path "wp-content/advanced-cache.php"
      add_path "wp-content/object-cache.php"
      ;;
    langs)
      add_path "wp-content/languages"
      ;;
    scripts)
      add_path "wp-content/mu-plugins"
      if [[ -d "$WP_PATH/../Scripts" ]]; then
        tar_externals+=("$WP_PATH/../Scripts")
      fi
      ;;
    others)
      add_path "wp-content/cache"
      add_path "wp-content/upgrade"
      add_path "wp-content/backups"
      add_path "wp-content/wflogs"
      ;;
    db)
      # handled separately
      ;;
    *)
      add_path "$part"
      ;;
  esac
done

if [[ ${#tar_items[@]} -eq 0 && $have_db_dump -eq 0 ]]; then
  log "No valid parts to include; aborting."
  exit 1
fi

bundle_tar="$WORKDIR/${bundle_name}.tar"
log "Creating tar bundle ${bundle_name}.tar."
if [[ ${#tar_items[@]} -gt 0 ]]; then
  tar -cf "$bundle_tar" -C "$WP_PATH" "${tar_items[@]}"
else
  tar -cf "$bundle_tar" --files-from /dev/null
fi

for ext_path in "${tar_externals[@]}"; do
  base_dir=$(dirname "$ext_path")
  entry_name=$(basename "$ext_path")
  if [[ -d "$ext_path" ]]; then
    tar -rf "$bundle_tar" -C "$base_dir" "$entry_name"
  fi
done

if [[ $have_db_dump -eq 1 ]]; then
  tar -rf "$bundle_tar" -C "$WORKDIR" database.sql
fi

export PTSB_MANIFEST_PATH="$manifest_path"
export PTSB_MANIFEST_PARTS="$PARTS"
export PTSB_MANIFEST_KEEP_DAYS="$KEEP_DAYS"
export PTSB_MANIFEST_KEEP_FOREVER="$KEEP_FOREVER"
export PTSB_MANIFEST_ORIGIN="$RUN_ORIGIN"
export PTSB_MANIFEST_JOB_ID="$JOB_ID"
export PTSB_MANIFEST_CHUNK_INDEX="$PARTS_CHUNK_INDEX"
export PTSB_MANIFEST_CHUNK_TOTAL="$PARTS_CHUNK_TOTAL"
export PTSB_MANIFEST_CHUNK_LABEL="$PARTS_CHUNK_LABEL"
export PTSB_MANIFEST_ORIGINAL_PARTS="$ORIGINAL_PARTS"
php <<'PHP'
<?php
$path = getenv('PTSB_MANIFEST_PATH');
$manifest = [
    'created_at'       => gmdate('c'),
    'parts'            => getenv('PTSB_MANIFEST_PARTS') ?: '',
    'keep_days'        => (int) (getenv('PTSB_MANIFEST_KEEP_DAYS') ?: 0),
    'keep_forever'     => (int) (getenv('PTSB_MANIFEST_KEEP_FOREVER') ?: 0),
    'origin'           => getenv('PTSB_MANIFEST_ORIGIN') ?: '',
    'job_id'           => getenv('PTSB_MANIFEST_JOB_ID') ?: '',
    'original_parts'   => getenv('PTSB_MANIFEST_ORIGINAL_PARTS') ?: '',
];
$chunkIndex = getenv('PTSB_MANIFEST_CHUNK_INDEX');
$chunkTotal = getenv('PTSB_MANIFEST_CHUNK_TOTAL');
$chunkLabel = getenv('PTSB_MANIFEST_CHUNK_LABEL');
if ($chunkIndex !== false && $chunkIndex !== '' && $chunkTotal !== false && $chunkTotal !== '') {
    $manifest['chunk'] = [
        'index' => (int) $chunkIndex,
        'total' => (int) $chunkTotal,
    ];
    if ($chunkLabel !== false && $chunkLabel !== '') {
        $manifest['chunk']['label'] = $chunkLabel;
    }
}
file_put_contents($path, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
PHP

tar -rf "$bundle_tar" -C "$WORKDIR" manifest.json

compressor="gzip"
if command -v pigz >/dev/null 2>&1; then
  compressor="pigz"
fi
log "Compressing bundle with ${compressor}."
"$compressor" -f "$bundle_tar"

bundle_path="${bundle_tar}.gz"
remote_path="${REMOTE}${bundle_name}.tar.gz"
log "Uploading bundle to ${remote_path}."
rclone copyto "$bundle_path" "$remote_path"

manifest_remote="${REMOTE}${bundle_name}.json"
log "Uploading manifest sidecar."
rclone rcat "$manifest_remote" < "$manifest_path"

if [[ "$KEEP_FOREVER" == "1" ]]; then
  keep_remote="${remote_path}.keep"
  log "Marking backup as keep forever (${keep_remote})."
  printf '' | rclone rcat "$keep_remote"
fi

log "Uploaded and removing local bundle."
rm -f "$bundle_path"
rm -f "$manifest_path"

log "Backup finished successfully."
