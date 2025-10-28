<?php
if (!defined('ABSPATH')) { exit; }

function ptsb_cfg(bool $refresh = false) {
    static $cache = null;

    if ($refresh) {
        $cache = null;
    }

    if ($cache !== null) {
        return $cache;
    }

    $cfg = [
        'remote'         => 'gdrive_backup:',
        'prefix'         => 'wpb-',
        'log'            => '/home/plugintema.com/Scripts/backup-wp.log',
        'lock'           => '/tmp/wpbackup.lock',
        'script_backup'  => dirname(__DIR__) . '/scripts/wp-backup-to-gdrive.sh',
        'mysqldump_bin'  => '/usr/bin/mysqldump',
        'script_restore' => '/home/plugintema.com/Scripts/wp-restore-from-archive.sh',
        'download_dir'   => '/home/plugintema.com/Backups/downloads',
        'drive_url'      => 'https://drive.google.com/drive/u/0/folders/18wIaInN0d0ftKhsi1BndrKmkVuOQkFoO',
        'keep_days_def'  => 12,

        'rclone'         => [
            'transfers'           => 2,
            'checkers'            => 4,
            'retries'             => 5,
            'retries_sleep'       => '10s',
            'retries_sleep_max'   => '2m',
            'low_level_retries'   => 10,
            'fast_list'           => false,
            'delta_enabled'       => true,
            'delta_max_age'       => '168h',
            'delta_use_update'    => true,
            'delta_path_template' => '{Y}/{m}',
        ],

        // agendamento
        'tz_string'      => 'America/Sao_Paulo',
        'cron_hook'      => 'ptsb_cron_tick',
        'cron_sched'     => 'ptsb_minutely',   // 60s (visitor-based)
        'max_per_day'    => 6,
        'min_gap_min'    => 10,
        'miss_window'    => 15,
        'queue_timeout'  => 5400,              // 90min
        'log_max_mb'     => 3,                 // tamanho máx. do log
        'log_keep'       => 5,                 // quantos arquivos rotacionados manter
    ];

    /**
     * Filtros úteis:
     * - ptsb_config           : altera o array completo
     * - ptsb_remote           : altera remote rclone (ex.: 'meudrive:')
     * - ptsb_prefix           : prefixo dos arquivos (ex.: 'site-')
     * - ptsb_default_parts    : CSV padrão para PARTS (ver ptsb_start_backup)
     * - ptsb_default_ui_codes : letras padrão marcadas na UI (P,T,W,S,M,O)
     */
    $cfg = apply_filters('ptsb_config', $cfg);
    $cfg['remote'] = apply_filters('ptsb_remote', $cfg['remote']);
    $cfg['prefix'] = apply_filters('ptsb_prefix', $cfg['prefix']);

    $cache = $cfg;

    return $cache;
}

function ptsb_cfg_flush(): void {
    ptsb_cfg(true);
}

function ptsb_get_nonce(): string {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = wp_create_nonce('ptsb_nonce');
    }
    return $nonce;
}

function ptsb_shell_env_prefix(): string {
    return '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8';
}

function ptsb_rclone_options(): array {
    $cfg  = ptsb_cfg();
    $opts = $cfg['rclone'] ?? [];
    if (!is_array($opts)) {
        $opts = [];
    }

    $defaults = [
        'transfers'           => 2,
        'checkers'            => 4,
        'retries'             => 5,
        'retries_sleep'       => '10s',
        'retries_sleep_max'   => '2m',
        'low_level_retries'   => 10,
        'fast_list'           => false,
        'delta_enabled'       => true,
        'delta_max_age'       => '168h',
        'delta_use_update'    => true,
        'delta_path_template' => '{Y}/{m}',
    ];

    $opts = array_merge($defaults, $opts);

    return apply_filters('ptsb_rclone_options', $opts);
}

function ptsb_rclone_base_flags(): string {
    $opts  = ptsb_rclone_options();
    $flags = [];

    $transfers = max(0, (int)($opts['transfers'] ?? 0));
    if ($transfers > 0) {
        $flags[] = '--transfers=' . $transfers;
    }

    $checkers = max(0, (int)($opts['checkers'] ?? 0));
    if ($checkers > 0) {
        $flags[] = '--checkers=' . $checkers;
    }

    $retries = max(0, (int)($opts['retries'] ?? 0));
    if ($retries > 0) {
        $flags[] = '--retries=' . $retries;
    }

    $lowLevel = max(0, (int)($opts['low_level_retries'] ?? 0));
    if ($lowLevel > 0) {
        $flags[] = '--low-level-retries=' . $lowLevel;
    }

    $sleep = trim((string)($opts['retries_sleep'] ?? ''));
    if ($sleep !== '') {
        $flags[] = '--retries-sleep=' . $sleep;
    }

    $sleepMax = trim((string)($opts['retries_sleep_max'] ?? ''));
    if ($sleepMax !== '') {
        $flags[] = '--retries-sleep-max=' . $sleepMax;
    }

    $flags = apply_filters('ptsb_rclone_base_flags', $flags, $opts);

    return trim(implode(' ', array_filter(array_map('trim', $flags))));
}

function ptsb_rclone_fast_list_enabled(): bool {
    $opts    = ptsb_rclone_options();
    $enabled = !empty($opts['fast_list']);
    return (bool) apply_filters('ptsb_rclone_use_fast_list', $enabled, $opts);
}

function ptsb_rclone_fast_list_flag(): string {
    return ptsb_rclone_fast_list_enabled() ? ' --fast-list' : '';
}

function ptsb_rclone_delta_config(): array {
    $opts  = ptsb_rclone_options();
    $delta = [
        'enabled'       => !empty($opts['delta_enabled']),
        'max_age'       => (string)($opts['delta_max_age'] ?? ''),
        'use_update'    => isset($opts['delta_use_update']) ? (bool)$opts['delta_use_update'] : true,
        'path_template' => (string)($opts['delta_path_template'] ?? ''),
    ];

    return apply_filters('ptsb_rclone_delta_config', $delta, $opts);
}

function ptsb_backup_env_defaults(): array {
    $opts  = ptsb_rclone_options();
    $delta = ptsb_rclone_delta_config();

    $env = [];

    $flags = ptsb_rclone_base_flags();
    if ($flags !== '') {
        $env['RCLONE_FLAGS'] = $flags;
    }

    if (isset($opts['transfers'])) {
        $env['RCLONE_TRANSFERS'] = max(0, (int)$opts['transfers']);
    }
    if (isset($opts['checkers'])) {
        $env['RCLONE_CHECKERS'] = max(0, (int)$opts['checkers']);
    }
    if (isset($opts['retries'])) {
        $env['RCLONE_RETRIES'] = max(0, (int)$opts['retries']);
    }
    if (isset($opts['retries_sleep'])) {
        $env['RCLONE_RETRIES_SLEEP'] = (string)$opts['retries_sleep'];
    }
    if (isset($opts['retries_sleep_max'])) {
        $env['RCLONE_RETRIES_SLEEP_MAX'] = (string)$opts['retries_sleep_max'];
    }
    if (isset($opts['low_level_retries'])) {
        $env['RCLONE_LOW_LEVEL_RETRIES'] = max(0, (int)$opts['low_level_retries']);
    }

    $env['RCLONE_FAST_LIST'] = ptsb_rclone_fast_list_enabled() ? 1 : 0;

    $env['RCLONE_DELTA_ENABLED'] = !empty($delta['enabled']) ? 1 : 0;
    if (!empty($delta['max_age'])) {
        $env['RCLONE_DELTA_MAX_AGE'] = (string)$delta['max_age'];
    }
    $env['RCLONE_DELTA_USE_UPDATE'] = !empty($delta['use_update']) ? 1 : 0;
    if (!empty($delta['path_template'])) {
        $env['RCLONE_DELTA_PATH_TEMPLATE'] = (string)$delta['path_template'];
    }

    return apply_filters('ptsb_backup_env_defaults', $env, $opts, $delta);
}

function ptsb_rclone_command(string $command): string {
    $command = ltrim($command);
    $flags   = ptsb_rclone_base_flags();
    if ($flags !== '') {
        $command = $flags . ' ' . $command;
    }
    return ptsb_shell_env_prefix() . ' rclone ' . $command;
}

function ptsb_rclone_exec(string $command)
{
    return shell_exec(ptsb_rclone_command($command));
}

function ptsb_rclone_exec_input(string $command, string $input)
{
    $cmd = ptsb_rclone_command($command);
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proc = proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) {
        return false;
    }

    fwrite($pipes[0], $input);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    // discard stderr
    if (is_resource($pipes[2])) {
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
    }

    proc_close($proc);

    return $stdout;
}

function ptsb_remote_cache_flush(): void {
    delete_transient('ptsb_totals_v1');
    delete_transient('ptsb_remote_files_v1');
    delete_transient('ptsb_keep_map_v1');
}

function ptsb_tail_cache_flush(string $path): void {
    $hash = md5($path);
    foreach ([50, 800] as $n) {
        delete_transient('ptsb_tail_v1_' . $hash . '_' . $n);
    }
}

/* -------------------------------------------------------
 * Utils
 * -----------------------------------------------------*/

function ptsb_can_shell() {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
}

function ptsb_lock_key(): string { return 'ptsb_lock_active'; }

function ptsb_lock_owner_key(): string { return 'ptsb_lock_owner'; }

function ptsb_lock_ttl(): int {
    return (int) apply_filters('ptsb_lock_ttl', 2 * HOUR_IN_SECONDS);
}

function ptsb_lock_token(): string {
    try { return bin2hex(random_bytes(8)); } catch (Throwable $e) { return md5(uniqid('ptsb', true)); }
}

function ptsb_lock_store(array $payload, ?int $ttl = null): void {
    $ttl = $ttl ?? ptsb_lock_ttl();
    $key = ptsb_lock_key();
    set_transient($key, $payload, $ttl);
    update_option($key, $payload, false);
}

function ptsb_lock_info(): array {
    $key = ptsb_lock_key();
    $ttl = ptsb_lock_ttl();
    $info = get_transient($key);
    if (!is_array($info)) {
        $opt = get_option($key, []);
        $info = is_array($opt) ? $opt : [];
    }

    $cfg      = ptsb_cfg();
    $lockPath = (string)($cfg['lock'] ?? '');
    $hasFile  = $lockPath !== '' && @file_exists($lockPath);
    $timestamp = isset($info['timestamp']) ? (int) $info['timestamp'] : 0;

    if ($timestamp) {
        $age = time() - $timestamp;
        if ($ttl <= 0 || $age <= $ttl) {
            if ($hasFile || $age <= 120) {
                return $info;
            }
        }
    }

    $mtime = $hasFile ? (int) @filemtime($lockPath) : 0;
    if ($mtime && ($ttl <= 0 || (time() - $mtime) <= $ttl)) {
        return [
            'pid'       => (int)($info['pid'] ?? 0),
            'timestamp' => $mtime,
            'token'     => (string)($info['token'] ?? ''),
            'source'    => 'file',
        ];
    }

    if ($timestamp) {
        ptsb_lock_release();
    }

    return [];
}

function ptsb_lock_is_active(): bool {
    return !empty(ptsb_lock_info());
}

function ptsb_lock_release(?string $token = null): void {
    $ownerKey = ptsb_lock_owner_key();
    if ($token !== null) {
        $owner = (string) get_option($ownerKey, '');
        if ($owner !== '' && $owner !== $token) {
            return;
        }
    }
    delete_option($ownerKey);
    delete_transient(ptsb_lock_key());
    delete_option(ptsb_lock_key());
}

function ptsb_lock_touch(string $token, array $extra = []): void {
    $info = ptsb_lock_info();
    if (!$info || ($info['token'] ?? '') !== $token) {
        return;
    }

    $payload = array_merge($info, $extra);
    $payload['token']     = $token;
    $payload['timestamp'] = time();
    ptsb_lock_store($payload);
}

function ptsb_lock_try_acquire(int $attempts = 4, ?int $ttl = null): ?array {
    $ttl      = $ttl ?? ptsb_lock_ttl();
    $ownerKey = ptsb_lock_owner_key();
    $token    = ptsb_lock_token();

    for ($i = 0; $i < max(1, $attempts); $i++) {
        if (add_option($ownerKey, $token, '', 'no')) {
            $payload = [
                'pid'       => getmypid() ?: 0,
                'timestamp' => time(),
                'token'     => $token,
            ];
            ptsb_lock_store($payload, $ttl);
            return $payload;
        }

        $info = ptsb_lock_info();
        if (!$info) {
            delete_option($ownerKey);
        } else {
            $age = time() - (int)($info['timestamp'] ?? 0);
            if ($age > $ttl) {
                $owner = (string) get_option($ownerKey, '');
                if ($owner === '' || $owner === (string)($info['token'] ?? '')) {
                    ptsb_lock_release();
                    continue;
                }
            }
        }

        usleep((int)((40 + mt_rand(10, 60)) * 1000 * ($i + 1)));
    }

    return null;
}

function ptsb_is_readable($p){ return @is_file($p) && @is_readable($p); }

function ptsb_tz() {
    $cfg = ptsb_cfg();
    try { return new DateTimeZone($cfg['tz_string']); } catch(Throwable $e){ return new DateTimeZone('America/Sao_Paulo'); }
}

function ptsb_now_brt() { return new DateTimeImmutable('now', ptsb_tz()); }

function ptsb_fmt_local_dt($iso) {
    try {
        $tz  = ptsb_tz();
        $dt  = new DateTimeImmutable($iso);
        $dt2 = $dt->setTimezone($tz);
        return $dt2->format('d/m/Y - H:i:s');
    } catch (Throwable $e) { return $iso; }
}

function ptsb_hsize($bytes) {
    $b = (float)$bytes;
    if ($b >= 1073741824) return number_format_i18n($b/1073741824, 2) . ' GB';
    return number_format_i18n(max($b/1048576, 0.01), 2) . ' MB';
}

/** Totais de backups no Drive (count e bytes), com cache de 10 min. */

function ptsb_backups_totals_cached(): array {
    $key = 'ptsb_totals_v1';
    $cached = get_transient($key);
    if (is_array($cached) && isset($cached['count'], $cached['bytes'])) {
        return $cached;
    }
    $rows = ptsb_list_remote_files(); // 1 chamada rclone lsf
    $count = count($rows);
    $bytes = 0;
    foreach ($rows as $r) { $bytes += (int)($r['size'] ?? 0); }
    $out = ['count'=>$count, 'bytes'=>$bytes];
    set_transient($key, $out, 10 * MINUTE_IN_SECONDS); // 10 min
    return $out;
}

/** Converte nome de bundle .tar.gz para o sidecar .json */

function ptsb_tar_to_json(string $tar): string {
    return preg_replace('/\.tar\.gz$/i', '.json', $tar);
}

/** Gera prefixo “slug-” a partir de um nome livre (para nome do arquivo) */

function ptsb_slug_prefix(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    if (function_exists('sanitize_title')) {
        $slug = sanitize_title($name);
    } else {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name)));
        $slug = trim($slug, '-');
    }
    return $slug ? ($slug . '-') : '';
}

function ptsb_to_utf8($s) {
    if ($s === null) return '';
    if (function_exists('seems_utf8') && seems_utf8($s)) return $s;
    if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
        $enc = mb_detect_encoding($s, ['UTF-8','ISO-8859-1','Windows-1252','ASCII'], true);
        if ($enc && $enc !== 'UTF-8') return mb_convert_encoding($s, 'UTF-8', $enc);
    }
    $out = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    return $out !== false ? $out : $s;
}

/* ===== Tamanhos e máscara de e-mail (usados no resumo do Drive) ===== */

function ptsb_mask_email($email, $keep = 7) {
    $email = trim((string)$email);
    if ($email === '' || strpos($email, '@') === false) return $email;
    [$left, $domain] = explode('@', $email, 2);
    $keep = max(1, min((int)$keep, strlen($left)));
    return substr($left, 0, $keep) . '...@' . $domain;
}

function ptsb_hsize_compact($bytes) {
    $b = (float)$bytes;
    $tb = 1099511627776; $gb = 1073741824; $mb = 1048576;
    if ($b >= $tb) return number_format_i18n($b/$tb, 1).' TB';
    if ($b >= $gb) return number_format_i18n($b/$gb, 1).' GB';
    return number_format_i18n(max($b/$mb,0.01), 1).' MB';
}

function ptsb_size_to_bytes($numStr, $unit) {
    $num  = (float)str_replace(',', '.', $numStr);
    $unit = strtoupper(trim($unit));
    $map = ['B'=>1,'KB'=>1024,'MB'=>1024**2,'GB'=>1024**3,'TB'=>1024**4,'KIB'=>1024,'MIB'=>1024**2,'GIB'=>1024**3,'TIB'=>1024**4];
    return (int)round($num * ($map[$unit] ?? 1));
}

/* -------------------------------------------------------
 * Retenção
 * -----------------------------------------------------*/

function ptsb_settings() {
    $cfg = ptsb_cfg();
     $d = max(1, (int) get_option('ptsb_keep_days', $cfg['keep_days_def']));
    $d = min($d, 3650);
    return ['keep_days'=>$d];
}

