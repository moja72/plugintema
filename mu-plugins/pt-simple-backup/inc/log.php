<?php
if (!defined('ABSPATH')) { exit; }

function ptsb_log($msg) {
    $cfg  = ptsb_cfg();
    ptsb_log_rotate_if_needed();
    $line = '[' . ptsb_now_brt()->format('d-m-Y-H:i') . '] ' . strip_tags($msg) . "\n";
    @file_put_contents($cfg['log'], $line, FILE_APPEND);
}

/**
 * Rotaciona o log quando ultrapassa o limite configurado.
 * - Se existir lock: faz copytruncate (copia para .1 e zera o arquivo atual).
 * - Se não existir lock: renomeia log -> log.1 e cria log novo.
 */

function ptsb_log_rotate_if_needed(): void {
    $cfg   = ptsb_cfg();
    $log   = (string)($cfg['log'] ?? '');
    $keep  = max(1, (int)($cfg['log_keep']    ?? 5));
    $maxMb = (float)  ($cfg['log_max_mb']     ?? 3);
    $limit = max(1, (int)round($maxMb * 1048576)); // MB -> bytes

    if ($log === '' || !@file_exists($log)) return;

    @clearstatcache(true, $log);
    $size = @filesize($log);
    if ($size === false || $size < $limit) return;

    // 1) abre espaço para o novo .1 (shift .1->.2, .2->.3, ..., até .keep)
    for ($i = $keep; $i >= 1; $i--) {
        $from = $log . '.' . $i;
        $to   = $log . '.' . ($i + 1);
        if (@file_exists($to))  @unlink($to);
        if (@file_exists($from)) @rename($from, $to);
    }

    // 2) estratégia conforme lock
    $running = ptsb_lock_is_active();
    if ($running) {
        // copytruncate
        @copy($log, $log . '.1');
        if ($fp = @fopen($log, 'c')) { @ftruncate($fp, 0); @fclose($fp); }
    } else {
        // rename + arquivo novo
        @rename($log, $log . '.1');
        if (!@file_exists($log)) { @file_put_contents($log, ""); }
    }

    // 3) se sobrou .(keep+1), remove
    $overflow = $log . '.' . ($keep + 1);
    if (@file_exists($overflow)) @unlink($overflow);

    ptsb_tail_cache_flush($log);
}

/**
 * Limpa o log: remove rotações .1..N e zera (ou recria) o arquivo atual.
 * - Se estiver rodando (lock): apenas trunca o atual e apaga as rotações.
 */

function ptsb_log_clear_all(): void {
    $cfg  = ptsb_cfg();
    $log  = (string)($cfg['log'] ?? '');
    $keep = max(1, (int)($cfg['log_keep'] ?? 5));
    if ($log === '') return;

    $running = ptsb_lock_is_active();

    // apaga rotações conhecidas (varremos um pouco além por segurança)
    for ($i = 1; $i <= ($keep + 5); $i++) {
        $p = $log . '.' . $i;
        if (@file_exists($p)) @unlink($p);
    }

    if ($running) {
        // não remove o atual: só zera
        if ($fp = @fopen($log, 'c')) { @ftruncate($fp, 0); @fclose($fp); }
    } else {
        // remove e recria vazio (para o tail AJAX continuar bem)
        @unlink($log);
        @file_put_contents($log, "");
    }

    ptsb_tail_cache_flush($log);
}

function ptsb_tail_log_raw($path, $n = 50) {
    $path = (string)$path;
    $n    = max(1, (int)$n);

    if ($path === '' || !@file_exists($path)) {
        return "Log nao encontrado em: $path";
    }

    $size = @filesize($path);
    $mtime = @filemtime($path);
    if ($size === false || $mtime === false) {
        return "Sem acesso de leitura ao log: $path";
    }

    $cacheKey = 'ptsb_tail_v1_' . md5($path) . '_' . $n;
    $cached   = get_transient($cacheKey);
    if (is_array($cached) && isset($cached['size'], $cached['mtime'], $cached['text'])) {
        if ((int)$cached['size'] === (int)$size && (int)$cached['mtime'] === (int)$mtime) {
            return (string)$cached['text'];
        }

        if ((int)$cached['size'] < (int)$size && (int)$cached['mtime'] <= (int)$mtime) {
            $delta = ptsb_tail_log_read_append($path, (int)$cached['size'], (int)$size);
            if ($delta !== null) {
                $combined = ptsb_tail_log_keep_lines((string)$cached['text'] . $delta, $n);
                $combined = ptsb_to_utf8($combined);
                set_transient($cacheKey, ['size' => $size, 'mtime' => $mtime, 'text' => $combined], 60);
                return $combined;
            }
        }
    }

    $text = ptsb_tail_log_read_full($path, $n);
    $text = ptsb_to_utf8($text);
    set_transient($cacheKey, ['size' => $size, 'mtime' => $mtime, 'text' => $text], 60);
    return $text;
}

function ptsb_tail_log_read_full(string $path, int $n): string {
    if (ptsb_can_shell()) {
        $txt = shell_exec('tail -n ' . $n . ' ' . escapeshellarg($path));
        if (is_string($txt) && $txt !== '') {
            return (string)$txt;
        }
    }

    $f = @fopen($path, 'rb');
    if (!$f) return "Sem acesso de leitura ao log: $path";

    $lines = [];
    $buffer = '';
    fseek($f, 0, SEEK_END);
    $filesize = ftell($f);
    $chunk = 4096;
    while ($filesize > 0 && count($lines) <= $n) {
        $seek = max($filesize - $chunk, 0);
        $read = $filesize - $seek;
        fseek($f, $seek);
        $buffer = fread($f, $read) . $buffer;
        $filesize = $seek;
        $lines = explode("\n", $buffer);
    }
    fclose($f);

    return ptsb_tail_log_keep_lines($buffer, $n);
}

function ptsb_tail_log_read_append(string $path, int $from, int $to): ?string {
    if ($to <= $from) {
        return null;
    }

    $f = @fopen($path, 'rb');
    if (!$f) {
        return null;
    }

    if (@fseek($f, $from) !== 0) {
        fclose($f);
        return null;
    }

    $length = $to - $from;
    $data = '';
    while ($length > 0 && !feof($f)) {
        $chunk = fread($f, min(8192, $length));
        if ($chunk === false) {
            break;
        }
        $data .= $chunk;
        $length -= strlen($chunk);
    }
    fclose($f);

    return $data;
}

function ptsb_tail_log_keep_lines(string $text, int $n): string {
    $lines = preg_split('/\r?\n/', $text);
    if ($lines === false) {
        $lines = explode("\n", $text);
    }
    if (!empty($lines)) {
        $last = end($lines);
        if ($last === '') {
            array_pop($lines);
        }
    }
    $lines = array_slice($lines, -$n);
    return implode("\n", $lines);
}

/* -------------------------------------------------------
 * Notificação: só dispara o evento; quem envia é o plugin de e-mails
 * -----------------------------------------------------*/

function ptsb_log_has_success_marker() {
    $cfg  = ptsb_cfg();
    $tail = (string) ptsb_tail_log_raw($cfg['log'], 800);

    if ($tail === '') {
        // evita flood: não loga toda hora
        if (!get_transient('ptsb_notify_rl_tail_empty')) {
            set_transient('ptsb_notify_rl_tail_empty', 1, 60);
            ptsb_log('[notify] tail vazio — permitindo notificação.');
        }
        return true;
    }

    $patterns = [
        '/Backup finished successfully\.?/i',
        '/Backup finalizado com sucesso\.?/i',
        '/Uploaded and removing local bundle/i',
        '/Upload(?:ed)?\s+completed/i',
        '/All done/i',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $tail)) return true;
    }

    // sem marcador: loga no máx 1x/min
    if (!get_transient('ptsb_notify_rl_no_marker')) {
        set_transient('ptsb_notify_rl_no_marker', 1, 60);
        ptsb_log('[notify] sem marcador de sucesso nas últimas linhas — aguardando.');
    }
    return false;
}

function ptsb_log_error_is_permanent(string $message): bool {
    $patterns = [
        '/mysqldump n[oã]o encontrado/i',
        '/rclone n[oã]o encontrado/i',
        '/tar n[oã]o encontrado/i',
        '/REMOTE n[oã]o informado/i',
        '/WP_PATH inv[áa]lido/i',
        '/Nenhuma parte encontrada para arquivar/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }

    return false;
}

function ptsb_log_last_error_entry(int $since = 0): ?array {
    $cfg  = ptsb_cfg();
    $tail = (string) ptsb_tail_log_raw($cfg['log'], 800);
    if ($tail === '') {
        return null;
    }

    $lines = array_reverse(array_filter(array_map('trim', preg_split('/\r?\n/', $tail))));
    foreach ($lines as $line) {
        if ($line === '') {
            continue;
        }
        if (stripos($line, 'ERROR:') === false) {
            continue;
        }

        $ts = 0;
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
            $ts = strtotime($m[1]) ?: 0;
        }
        if ($since > 0 && $ts > 0 && $ts < ($since - 5)) {
            continue;
        }

        $pos = stripos($line, 'ERROR:');
        $message = $pos !== false ? trim(substr($line, $pos + 6)) : $line;

        return [
            'line'      => $line,
            'message'   => $message,
            'timestamp' => $ts,
            'hash'      => md5($line),
            'permanent' => ptsb_log_error_is_permanent($message),
        ];
    }

    return null;
}

function ptsb_notifications_storage_dir(): ?string {
    $uploads = wp_upload_dir();
    if (!empty($uploads['error'])) {
        return null;
    }

    $dir = trailingslashit($uploads['basedir']) . 'pt-simple-backup/';
    if (!wp_mkdir_p($dir)) {
        return null;
    }

    return $dir;
}

function ptsb_last_notified_payload_file(): ?string {
    $dir = ptsb_notifications_storage_dir();
    if ($dir === null) {
        return null;
    }

    return $dir . 'last-notified-payload.json';
}

function ptsb_store_last_notified_payload(array $payload): void {
    $file   = ptsb_last_notified_payload_file();
    $stored = false;

    if ($file !== null) {
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = json_encode($payload);
        }

        if (is_string($json)) {
            $tmpFile = $file . '.tmp';
            $written = @file_put_contents($tmpFile, $json, LOCK_EX);
            if ($written !== false && @rename($tmpFile, $file)) {
                $stored = true;
            } else {
                @unlink($tmpFile);
                $stored = (@file_put_contents($file, $json, LOCK_EX) !== false);
            }
        }
    }

    if ($stored) {
        update_option('ptsb_last_notified_payload_meta', [
            'storage'    => 'file',
            'file'       => $file ? basename($file) : '',
            'updated_at' => time(),
        ], false);
        delete_option('ptsb_last_notified_payload');
        return;
    }

    update_option('ptsb_last_notified_payload', $payload, false);
    update_option('ptsb_last_notified_payload_meta', [
        'storage'    => 'option',
        'updated_at' => time(),
    ], false);
}

function ptsb_maybe_notify_backup_done() {
    $cfg = ptsb_cfg();

    // === THROTTLE: roda no máx 1x a cada 15s (evita flood via admin_init/AJAX) ===
    $th_key = 'ptsb_notify_throttle_15s';
    $now_ts = time();
    $last   = (int) get_transient($th_key);
    if ($last && ($now_ts - $last) < 15) {
        return;
    }
    set_transient($th_key, $now_ts, 15);

    // se ainda está rodando, não notifica (loga no máx 1x/min)
    if (ptsb_lock_is_active()) {
        if (!get_transient('ptsb_notify_lock_log_rl')) {
            set_transient('ptsb_notify_lock_log_rl', 1, 60);
            ptsb_log('[notify] pulando: lock presente (backup rodando).');
        }
        return;
    }

    // pega o último arquivo do Drive
    $rows = ptsb_list_remote_files();
    if (!$rows) return;
    $latest    = $rows[0];
    $last_sent = (string) get_option('ptsb_last_notified_backup_file', '');

    // evita duplicar notificação
    if ($latest['file'] === $last_sent) return;

    // espera até 10min pelo marcador explícito de sucesso no log
    $ok = ptsb_log_has_success_marker();
    if (!$ok) {
        try { $finished = new DateTimeImmutable($latest['time']); } catch (Throwable $e) { $finished = null; }
        $margem = $finished ? (ptsb_now_brt()->getTimestamp() - $finished->getTimestamp()) : 0;
        if ($finished && $margem < 600) {
            if (!get_transient('ptsb_notify_wait_marker_rl')) {
                set_transient('ptsb_notify_wait_marker_rl', 1, 60);
                ptsb_log('[notify] aguardando marcador (até 10min) para '.$latest['file']);
            }
            return;
        }
        if (!get_transient('ptsb_notify_no_marker_rl2')) {
            set_transient('ptsb_notify_no_marker_rl2', 1, 60);
            ptsb_log('[notify] seguindo sem marcador explícito para '.$latest['file']);
        }
    }

    $chunkState = ptsb_chunk_plan_complete((string)$latest['file']);
    if (!empty($chunkState['noop'])) {
        return;
    }
    if (!$chunkState['final']) {
        $completed = (int)($chunkState['completed'] ?? 0);
        $remaining = (int)($chunkState['remaining'] ?? 0);
        ptsb_log(sprintf('[chunk] Parte concluída (%d/%d). Aguardando próximo chunk.', $completed, $completed + $remaining));
        return;
    }
    $chunkFailures = [];
    if (isset($chunkState['failed']) && is_array($chunkState['failed'])) {
        $chunkFailures = $chunkState['failed'];
    }
    if ($chunkFailures) {
        foreach ($chunkFailures as $failure) {
            $partsCsv = (string)($failure['parts_csv'] ?? implode(',', (array)($failure['parts'] ?? [])));
            $partsLbl = $partsCsv !== '' ? implode(', ', ptsb_parts_to_labels($partsCsv)) : (string)($failure['key'] ?? 'partes');
            if ($partsLbl === '') {
                $partsLbl = 'partes';
            }
            $attempts = max(1, (int)($failure['attempts'] ?? 1));
            $msg = (string)($failure['last_error'] ?? 'Falha permanente na etapa.');
            ptsb_log(sprintf('[chunk] Exceção permanente (%s, tentativa %d): %s', $partsLbl, $attempts, $msg));
        }
    }
    $chunkOriginalParts = (string)($chunkState['original_parts'] ?? '');

    // === LOCK anti-duplicidade (apenas 1 request envia) ===
    $lock_opt = 'ptsb_notify_lock';
    $got_lock = add_option($lock_opt, (string)$latest['file'], '', 'no'); // true se criou
    if (!$got_lock) {
        // se alguém já está processando este MESMO arquivo, sai silencioso
        $cur = (string) get_option($lock_opt, '');
        if ($cur === (string)$latest['file']) {
            return;
        } else {
            // outro arquivo ainda em processamento – não competir
            return;
        }
    }

    try {
        // intenção do último disparo (manual/rotina + retenção)
        $intent         = get_option('ptsb_last_run_intent', []);
        $intent_kdays   = isset($intent['keep_days']) ? (int)$intent['keep_days'] : (int)ptsb_settings()['keep_days'];
        $intent_forever = !empty($intent['keep_forever']) || $intent_kdays === 0;
        $intent_origin  = (string)($intent['origin'] ?? '');

        // manifest existente (se houver)
        $man = ptsb_manifest_read($latest['file']);

        // PARTES (CSV) -> letras + rótulos humanos
        $partsCsv = (string)($man['parts'] ?? '');
        if ($partsCsv === '' && $chunkOriginalParts !== '') {
            $partsCsv = $chunkOriginalParts;
        }
        if ($partsCsv === '') {
            $partsCsv = (string) get_option('ptsb_last_run_parts', '');
        }
        if ($partsCsv === '') {
            $partsCsv = apply_filters('ptsb_default_parts', 'db,plugins,themes,uploads,langs,config,scripts');
        }
        $letters = ptsb_parts_to_letters($partsCsv);
        $parts_h = ptsb_parts_to_labels($partsCsv);

        // RETENÇÃO (dias) — 0 = “sempre”
        $keepDaysMan = ptsb_manifest_keep_days(is_array($man) ? $man : [], null);
        $keepDays    = ($keepDaysMan === null) ? ($intent_forever ? 0 : max(1, (int)$intent_kdays)) : (int)$keepDaysMan;

        // se for "sempre manter", garante o sidecar .keep
        $keepers = ptsb_keep_map();
        if ($keepDays === 0 && empty($keepers[$latest['file']])) {
            ptsb_apply_keep_sidecar($latest['file']);
        }

        // rótulos de retenção
        $ret_label = ($keepDays === 0) ? 'sempre' : sprintf('%d dia%s', $keepDays, $keepDays > 1 ? 's' : '');
        $ret_prog  = null;
        if ($keepDays > 0) {
            $ri       = ptsb_retention_calc((string)$latest['time'], $keepDays);
            $ret_prog = $ri['x'].'/'.$ri['y'];
        }

        // tenta inferir modo da rotina pelo nome do arquivo
        $routine_mode = (string)(ptsb_guess_cycle_mode_from_filename($latest['file']) ?? '');

        // sincroniza manifest com dados úteis
        $manAdd = [
            'keep_days'    => $keepDays,
            'origin'       => ($intent_origin ?: 'manual'),
            'parts'        => $partsCsv,
            'letters'      => $letters,
            'routine_mode' => $routine_mode,
        ];
        ptsb_manifest_write($latest['file'], $manAdd, true);

        ptsb_store_run_metrics_summary($latest, [
            'parts'        => $partsCsv,
            'keep_days'    => $keepDays,
            'origin'       => $intent_origin,
            'routine_mode' => $routine_mode,
            'keep_forever' => ($keepDays === 0 ? 1 : 0),
        ]);

        // payload da notificação
        $payload = [
            'file'               => (string)$latest['file'],
            'size'               => (int)$latest['size'],
            'size_h'             => ptsb_hsize((int)$latest['size']),
            'finished_at_iso'    => (string)$latest['time'],
            'finished_at_local'  => ptsb_fmt_local_dt((string)$latest['time']),
            'drive_url'          => (string)$cfg['drive_url'],
            'parts_csv'          => $partsCsv,
            'parts_h'            => $parts_h,
            'letters'            => $letters,
            'keep_days'          => $keepDays,
            'retention_label'    => $ret_label,
            'retention_prog'     => $ret_prog,
            'origin'             => ($intent_origin ?: 'manual'),
            'routine_mode'       => $routine_mode,
            'keep_forever'       => ($keepDays === 0 ? 1 : 0),
            'job_id'             => (string)($intent['job_id'] ?? ''),
        ];

        // dispara o evento; outro plugin/integração cuida de enviar e-mails
        do_action('ptsb_backup_done', $payload);

        ptsb_manual_job_mark_completed($payload);

      // === FALLBACK de e-mail (só se NÃO houver OU pt_done OU pt_finished) ===
if (!has_action('ptsb_backup_done') && !has_action('ptsb_backup_finished') && function_exists('wp_mail')) {
    ptsb_notify_send_email_fallback($payload);
}


        // marca como notificado
        update_option('ptsb_last_notified_backup_file', (string)$latest['file'], false);
        ptsb_store_last_notified_payload($payload);

        ptsb_log('[notify] evento disparado para '.$latest['file']);
    } finally {
        // libera lock mesmo com erro
        delete_option($lock_opt);
    }
}

/**
 * Envio de e-mail simples caso não exista listener para o hook `ptsb_backup_done`.
 * Personalizável via filtro `ptsb_notify_email_to`.
 */

function ptsb_notify_send_email_fallback(array $payload) {
    $to = apply_filters('ptsb_notify_email_to', get_option('admin_email'));
    if (!is_email($to)) return;

    $site  = wp_parse_url(home_url(), PHP_URL_HOST);
    $assunto = sprintf('[%s] Backup concluído: %s (%s)',
        $site ?: 'site', (string)$payload['file'], (string)$payload['size_h']
    );

    $linhas = [];
    $linhas[] = 'Backup concluído e enviado ao Drive.';
    $linhas[] = '';
    $linhas[] = 'Arquivo: ' . (string)$payload['file'];
    $linhas[] = 'Tamanho: ' . (string)$payload['size_h'];
    $linhas[] = 'Concluído: ' . (string)$payload['finished_at_local'];
    $linhas[] = 'Backup: ' . implode(', ', (array)$payload['parts_h']);
    $linhas[] = 'Retenção: ' . (string)$payload['retention_label'] . ($payload['retention_prog'] ? ' ('.$payload['retention_prog'].')' : '');
    if (!empty($payload['drive_url'])) {
        $linhas[] = 'Drive: ' . (string)$payload['drive_url'];
    }
    $linhas[] = '';
    $linhas[] = 'Origem: ' . (string)$payload['origin'] . ($payload['routine_mode'] ? ' / modo: '.$payload['routine_mode'] : '');

    $body = implode("\n", $linhas);

    // texto simples
    @wp_mail($to, $assunto, $body);
}

add_filter('pre_option_ptsb_last_notified_payload', 'ptsb_pre_option_last_notified_payload');

function ptsb_pre_option_last_notified_payload($pre) {
    $meta = get_option('ptsb_last_notified_payload_meta', []);
    if (!is_array($meta) || ($meta['storage'] ?? '') !== 'file') {
        return $pre;
    }

    $file = (string)($meta['file'] ?? '');
    if ($file === '') {
        return $pre;
    }

    $dir = ptsb_notifications_storage_dir();
    if ($dir === null) {
        return $pre;
    }

    $path = $dir . basename($file);
    if (!@file_exists($path)) {
        return $pre;
    }

    $json = @file_get_contents($path);
    if (!is_string($json) || $json === '') {
        return $pre;
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : $pre;
}

function ptsb_store_run_metrics_summary(array $latest, array $context): void {
    $metrics = ptsb_log_extract_latest_metrics();
    if (!is_array($metrics)) {
        return;
    }

    $file = (string)($latest['file'] ?? '');
    if ($file === '') {
        return;
    }

    $finishedAt = (string)($latest['time'] ?? '');

    $entry = [
        'file'                => $file,
        'finished_at'         => $finishedAt,
        'duration_ms'         => isset($metrics['duration_ms']) ? max(0, (int)$metrics['duration_ms']) : 0,
        'bytes_transferred'   => isset($metrics['bytes_transferred']) ? max(0, (int)$metrics['bytes_transferred']) : 0,
        'io_wait_seconds'     => isset($metrics['io_wait_seconds']) ? (float) round((float) $metrics['io_wait_seconds'], 3) : 0.0,
        'cpu_seconds'         => isset($metrics['cpu_seconds']) ? (float) round((float) $metrics['cpu_seconds'], 3) : 0.0,
        'peak_memory_bytes'   => isset($metrics['peak_memory_bytes']) ? max(0, (int)$metrics['peak_memory_bytes']) : 0,
    ];

    if (!empty($metrics['started_at'])) {
        $entry['started_at'] = (string) $metrics['started_at'];
    }

    if (!empty($metrics['steps']) && is_array($metrics['steps'])) {
        $steps = [];
        foreach ($metrics['steps'] as $name => $value) {
            $key = preg_replace('/[^a-z0-9_\-]/i', '', (string) $name);
            if ($key === '') {
                continue;
            }
            $steps[$key] = max(0, (int) $value);
        }
        if ($steps) {
            $entry['steps'] = $steps;
        }
    }

    if (isset($context['parts'])) {
        $entry['parts'] = (string) $context['parts'];
    }
    if (isset($context['keep_days'])) {
        $entry['keep_days'] = (int) $context['keep_days'];
    }
    if (!empty($context['keep_forever']) || (isset($context['keep_days']) && (int) $context['keep_days'] === 0)) {
        $entry['keep_forever'] = 1;
    }

    $origin = (string) ($context['origin'] ?? '');
    $entry['origin'] = $origin !== '' ? $origin : 'manual';

    if (!empty($context['routine_mode'])) {
        $entry['routine_mode'] = (string) $context['routine_mode'];
    }

    ptsb_run_metrics_history_add($entry);
}

function ptsb_log_extract_latest_metrics(): ?array {
    $cfg  = ptsb_cfg();
    $path = (string) ($cfg['log'] ?? '');
    if ($path === '' || !@file_exists($path)) {
        return null;
    }

    $raw = ptsb_tail_log_raw($path, 400);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $lines = preg_split('/\r?\n/', $raw);
    if (!is_array($lines)) {
        return null;
    }

    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string) $lines[$i]);
        if ($line === '') {
            continue;
        }
        if (!preg_match('/METRICS\s+({.+})$/', $line, $m)) {
            continue;
        }
        $json = trim((string) $m[1]);
        $decoded = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function ptsb_run_metrics_history_add(array $entry): void {
    $option  = 'ptsb_run_metrics_history';
    $history = get_option($option, []);
    if (!is_array($history)) {
        $history = [];
    }

    $filtered = [];
    foreach ($history as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['file'] ?? '') === (string) ($entry['file'] ?? '')) {
            continue;
        }
        $filtered[] = $row;
    }

    array_unshift($filtered, $entry);

    $max = (int) apply_filters('ptsb_metrics_history_max', 20);
    if ($max <= 0) {
        $max = 20;
    }
    if (count($filtered) > $max) {
        $filtered = array_slice($filtered, 0, $max);
    }

    update_option($option, $filtered, false);
}

function ptsb_get_run_metrics_history(): array {
    $history = get_option('ptsb_run_metrics_history', []);
    return is_array($history) ? $history : [];
}

