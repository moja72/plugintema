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

    $pluginDir     = dirname(__DIR__);
    $bundledScript = $pluginDir . '/scripts/wp-backup-to-gdrive.sh';
    $cronRunner    = $pluginDir . '/scripts/wp-run-wpcron.sh';
    $defaultScript = is_executable($bundledScript)
        ? $bundledScript
        : '/home/plugintema.com/Scripts/wp-backup-to-gdrive.sh';

    $cfg = [
        'remote'         => 'gdrive_backup:',
        'prefix'         => 'wpb-',
        'log'            => '/home/plugintema.com/Scripts/backup-wp.log',
        'lock'           => '/tmp/wpbackup.lock',
        'script_backup'  => $defaultScript,
        'script_restore' => '/home/plugintema.com/Scripts/wp-restore-from-archive.sh',
        'cron_runner'    => $cronRunner,
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
        'maintenance_window' => [              // janela diária permitida para execuções automáticas
            'enabled' => true,
            'start'   => '02:00',
            'end'     => '05:00',
        ],
        'process_limits' => [                  // limites de prioridade/processo
            'nice'          => 10,
            'ionice'        => ['class' => 2, 'priority' => 7],
            'cpu_limit_pct' => 60,
        ],
        'log_max_mb'     => 3,                 // tamanho máx. do log
        'log_keep'       => 5,                 // quantos arquivos rotacionados manter
        'rclone_fast_list' => true,            // habilita --fast-list nas operações internas
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

function ptsb_rclone_fast_list_enabled(): bool {
    $cfg = ptsb_cfg();
    $enabled = !empty($cfg['rclone_fast_list']);
    return (bool) apply_filters('ptsb_rclone_fast_list_enabled', $enabled);
}

function ptsb_rclone_fast_list_flag(): string {
    return ptsb_rclone_fast_list_enabled() ? ' --fast-list' : '';
}

function ptsb_rclone_uploads_filter_rules(): string {
    $months = (int) apply_filters('ptsb_rclone_uploads_filter_months', 2);
    $months = max(1, min($months, 12));

    try {
        $now = ptsb_now_brt();
    } catch (Throwable $e) {
        $now = new DateTimeImmutable('now');
    }

    $rules = ['+ /wp-content/uploads/'];
    $seen  = [];
    $dt    = $now->setDate((int) $now->format('Y'), (int) $now->format('m'), 1);

    for ($i = 0; $i < $months; $i++) {
        $key = $dt->format('Y-m');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $year  = $dt->format('Y');
            $month = $dt->format('m');
            $rules[] = '+ /wp-content/uploads/' . $year . '/';
            $rules[] = '+ /wp-content/uploads/' . $year . '/' . $month . '/';
            $rules[] = '+ /wp-content/uploads/' . $year . '/' . $month . '/**';
        }
        $dt = $dt->sub(new DateInterval('P1M'));
    }

    $rules[] = '- /wp-content/uploads/**';
    $rules[] = '+ /**';

    $rules = array_values(array_unique($rules));
    $rules = apply_filters('ptsb_rclone_uploads_filter_rules', $rules);

    return implode("\n", array_filter($rules, 'strlen'));
}

function ptsb_rclone_backup_env(): array {
    $defaults = [
        'RCLONE_TRANSFERS'        => '4',
        'RCLONE_CHECKERS'         => '6',
        'RCLONE_RETRIES'          => '5',
        'RCLONE_LOW_LEVEL_RETRIES'=> '10',
        'RCLONE_RETRY_BACKOFF'    => '5s',
        'RCLONE_UPDATE'           => 'true',
    ];

    $maxAgeHours = (int) apply_filters('ptsb_rclone_max_age_hours', 72);
    if ($maxAgeHours > 0) {
        $defaults['RCLONE_MAX_AGE'] = $maxAgeHours . 'h';
    }

    if (!ptsb_rclone_fast_list_enabled()) {
        $defaults['RCLONE_FAST_LIST'] = 'false';
    }

    $filterRules = ptsb_rclone_uploads_filter_rules();
    if ($filterRules !== '') {
        $defaults['RCLONE_FILTER'] = $filterRules;
    }

    $env = apply_filters('ptsb_rclone_backup_env', $defaults);

    return array_filter($env, static function ($value) {
        return $value !== null && $value !== '';
    });
}

function ptsb_maintenance_window_config(): array {
    $cfg  = ptsb_cfg();
    $base = isset($cfg['maintenance_window']) && is_array($cfg['maintenance_window'])
        ? $cfg['maintenance_window']
        : [];

    $merged  = array_merge(['enabled' => true, 'start' => '02:00', 'end' => '05:00'], $base);
    $enabled = !empty($merged['enabled']);

    $normalize = static function ($value, $fallback) {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value)) {
            [$h, $m] = array_map('intval', explode(':', $value, 2));
            return sprintf('%02d:%02d', $h, $m);
        }
        return $fallback;
    };

    $start = $normalize($merged['start'] ?? '02:00', '02:00');
    $end   = $normalize($merged['end']   ?? '05:00', '05:00');

    $window = [
        'enabled' => $enabled,
        'start'   => $start,
        'end'     => $end,
    ];

    return apply_filters('ptsb_maintenance_window_config', $window);
}

function ptsb_process_limits(): array {
    $cfg  = ptsb_cfg();
    $base = isset($cfg['process_limits']) && is_array($cfg['process_limits'])
        ? $cfg['process_limits']
        : [];

    $merged = array_merge([
        'nice'          => 10,
        'ionice'        => ['class' => 2, 'priority' => 7],
        'cpu_limit_pct' => 60,
    ], $base);

    $niceRaw = $merged['nice'];
    $nice    = null;
    if ($niceRaw !== false && $niceRaw !== null) {
        $nice = max(-20, min(19, (int) $niceRaw));
    }

    $ioniceRaw = $merged['ionice'] ?? [];
    $ionice    = null;
    if ($ioniceRaw !== false && $ioniceRaw !== null) {
        $ioniceArr = is_array($ioniceRaw) ? $ioniceRaw : [];
        $class     = isset($ioniceArr['class']) ? (int) $ioniceArr['class'] : 2;
        if (!in_array($class, [1, 2, 3], true)) {
            $class = 2;
        }
        $priority = isset($ioniceArr['priority']) ? (int) $ioniceArr['priority'] : 7;
        $priority = max(0, min(7, $priority));
        $ionice   = ['class' => $class, 'priority' => $priority];
    }

    $cpuLimit = isset($merged['cpu_limit_pct']) ? (int) $merged['cpu_limit_pct'] : 0;
    $cpuLimit = max(0, min(100, $cpuLimit));

    $limits = [
        'nice'          => $nice,
        'ionice'        => $ionice,
        'cpu_limit_pct' => $cpuLimit,
    ];

    return apply_filters('ptsb_process_limits', $limits);
}

function ptsb_shell_command_exists(string $command): bool {
    static $cache = [];

    $command = trim($command);
    if ($command === '') {
        return false;
    }

    if (array_key_exists($command, $cache)) {
        return (bool) $cache[$command];
    }

    if (!ptsb_can_shell()) {
        $cache[$command] = false;
        return false;
    }

    $result = shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null');
    $cache[$command] = is_string($result) && trim($result) !== '';

    return (bool) $cache[$command];
}

function ptsb_rclone_command(string $command): string {
    $command = ltrim($command);
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

/* ===== Tamanhos (usados no resumo do Drive) ===== */

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

