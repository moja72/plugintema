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
        'script_backup'  => '/home/plugintema.com/Scripts/wp-backup-to-gdrive.sh',
        'script_restore' => '/home/plugintema.com/Scripts/wp-restore-from-archive.sh',
        'download_dir'   => '/home/plugintema.com/Backups/downloads',
        'drive_url'      => 'https://drive.google.com/drive/u/0/folders/18wIaInN0d0ftKhsi1BndrKmkVuOQkFoO',
        'keep_days_def'  => 12,

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

function ptsb_rclone_command(string $command): string {
    $command = ltrim($command);
    return ptsb_shell_env_prefix() . ' rclone ' . $command;
}

function ptsb_rclone_exec(string $command, array $context = [])
{
    $command = ptsb_rclone_apply_flags($command, $context);
    return shell_exec(ptsb_rclone_command($command));
}

function ptsb_rclone_exec_input(string $command, string $input, array $context = [])
{
    $command = ptsb_rclone_apply_flags($command, $context);
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

function ptsb_rclone_backend_features(): array {
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];

    if (!ptsb_can_shell()) {
        return $cache;
    }

    $cfg = ptsb_cfg();
    $remote = isset($cfg['remote']) ? (string) $cfg['remote'] : '';
    if ($remote === '') {
        return $cache;
    }

    $json = shell_exec(ptsb_shell_env_prefix() . ' rclone backend features ' . escapeshellarg($remote) . ' --json 2>/dev/null');
    $data = json_decode((string) $json, true);
    if (is_array($data)) {
        $cache = $data;
    }

    return $cache;
}

function ptsb_rclone_supports_fast_list(): bool {
    static $supports = null;

    if ($supports !== null) {
        return $supports;
    }

    $features = ptsb_rclone_backend_features();
    $fast = false;

    if (isset($features['Features']) && is_array($features['Features'])) {
        $fast = !empty($features['Features']['ListR']);
    } elseif (isset($features['features']) && is_array($features['features'])) {
        $fast = !empty($features['features']['ListR']);
    } elseif (isset($features['ListR'])) {
        $fast = !empty($features['ListR']);
    }

    $supports = (bool) apply_filters('ptsb_rclone_supports_fast_list', $fast, $features);

    return $supports;
}

function ptsb_rclone_delta_flags(array $context = []): array {
    $keepDays = null;
    if (function_exists('ptsb_settings')) {
        $settings = ptsb_settings();
        if (is_array($settings) && isset($settings['keep_days'])) {
            $keepDays = (int) $settings['keep_days'];
        }
    }
    if ($keepDays === null || $keepDays <= 0) {
        $cfg = ptsb_cfg();
        if (isset($cfg['keep_days_def'])) {
            $keepDays = (int) $cfg['keep_days_def'];
        }
    }
    $maxAge = null;
    if ($keepDays !== null && $keepDays > 0) {
        $maxAge = max(1, min($keepDays, 3650)) . 'd';
    }

    $defaults = [
        'update'      => true,
        'max_age'     => $maxAge,
        'min_age'     => null,
        'include'     => [],
        'exclude'     => [],
        'filter_from' => [],
    ];

    $config = apply_filters('ptsb_rclone_delta_config', $defaults, $context);
    if (!is_array($config)) {
        $config = $defaults;
    } else {
        $config = array_merge($defaults, $config);
    }

    $flags = [];

    if (!empty($config['update'])) {
        $flags[] = '--update';
    }

    foreach (['max_age' => '--max-age', 'min_age' => '--min-age'] as $key => $flag) {
        $value = isset($config[$key]) ? trim((string) $config[$key]) : '';
        if ($value !== '') {
            $flags[] = $flag . '=' . $value;
        }
    }

    foreach ([
        'include'     => '--include',
        'exclude'     => '--exclude',
        'filter_from' => '--filter-from',
    ] as $key => $flag) {
        $values = isset($config[$key]) ? (array) $config[$key] : [];
        foreach ($values as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern === '') {
                continue;
            }
            $flags[] = $flag . ' ' . escapeshellarg($pattern);
        }
    }

    return $flags;
}

function ptsb_rclone_default_flags(array $context = []): array {
    $category = strtolower((string) ($context['category'] ?? 'general'));

    $flags = [
        '--retries=5',
        '--retries-sleep=10s',
        '--low-level-retries=10',
        '--low-level-retries-sleep=5s',
    ];

    if (in_array($category, ['transfer', 'mutate'], true)) {
        $flags[] = '--transfers=2';
        $flags[] = '--checkers=4';
    } elseif ($category === 'list') {
        $flags[] = '--checkers=4';
    }

    if (!empty($context['delta'])) {
        $flags = array_merge($flags, ptsb_rclone_delta_flags($context));
    }

    if (!empty($context['fast_list']) && ptsb_rclone_supports_fast_list()) {
        $flags[] = '--fast-list';
    }

    return apply_filters('ptsb_rclone_default_flags', $flags, $context);
}

function ptsb_rclone_flags(array $context = []): array {
    $raw = ptsb_rclone_default_flags($context);
    $flags = [];

    foreach ((array) $raw as $flag) {
        $flag = trim((string) $flag);
        if ($flag !== '') {
            $flags[] = $flag;
        }
    }

    return array_values(array_unique($flags));
}

function ptsb_rclone_flags_string(array $context = []): string {
    return implode(' ', ptsb_rclone_flags($context));
}

function ptsb_rclone_apply_flags(string $command, array $context = []): string {
    $flags = ptsb_rclone_flags($context);
    if (!$flags) {
        return $command;
    }

    $redirect = '';
    if (preg_match('/\s+(2>\/dev\/null|1>\/dev\/null|2>&1)\s*$/', $command, $m)) {
        $command = substr($command, 0, -strlen($m[0]));
        $redirect = ' ' . $m[1];
    }

    return rtrim($command) . ' ' . implode(' ', $flags) . $redirect;
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

