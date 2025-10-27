<?php
/* ========================================================================
 * PARTE 1 — Núcleo / lógica, sem UI pesada (PARTE 2 vem depois)
 * MU Plugin: PT - Simple Backup GUI (rclone + scripts) + Agendamento + Renomear
 * Menu: Ferramentas -> Backup
 * ======================================================================*/

if (!defined('ABSPATH')) { exit; }
    
/* -------------------------------------------------------
 * Menu + assets (dashicons)
 * -----------------------------------------------------*/
add_action('admin_menu', function () {
    add_management_page('Backup', 'Backup', 'manage_options', 'pt-simple-backup', 'ptsb_render_backup_page'); // ptsb_render_backup_page é definida na PARTE 2
});
add_action('admin_enqueue_scripts', function($hook){
    if ($hook === 'tools_page_pt-simple-backup') {
        wp_enqueue_style('dashicons');
    }
});

// força no-cache nessa página do admin
add_action('load-tools_page_pt-simple-backup', function () {
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCDN'))      define('DONOTCDN', true);
    if (!defined('DONOTCACHEDB'))  define('DONOTCACHEDB', true);

    // PULA o cache de manifest APENAS quando houver ?force=1
    $force = isset($_GET['force']) && (int)$_GET['force'] === 1;
    if ($force && !defined('PTSB_SKIP_MANIFEST_CACHE')) {
        define('PTSB_SKIP_MANIFEST_CACHE', true);
    }

    if (defined('LSCWP_VERSION')) {
        do_action('litespeed_control_set_nocache');
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
});



/* -------------------------------------------------------
 * Config (com filtros p/ customização)
 * -----------------------------------------------------*/
function ptsb_cfg() {
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
        'log_max_mb' => 3, // tamanho máx. do log
'log_keep'   => 5, // quantos arquivos rotacionados manter

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
    return $cfg;
}

/* -------------------------------------------------------
 * Utils
 * -----------------------------------------------------*/
function ptsb_can_shell() {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return function_exists('shell_exec') && !in_array('shell_exec', $disabled, true);
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
function ptsb_log($msg) {
  $cfg = ptsb_cfg();
  ptsb_log_rotate_if_needed(); // NOVO
  $line = '['.ptsb_now_brt()->format('d-m-Y-H:i').'] '.strip_tags($msg)."\n";
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
    $running = @file_exists((string)$cfg['lock']);
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

    $running = @file_exists((string)$cfg['lock']);

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
 * MANIFEST (.json) + rótulos e LETRAS (para a coluna “Backup”)
 * -----------------------------------------------------*/
/** Lê o JSON sidecar do arquivo .tar.gz no remoto e devolve array (cache 10 min) */
function ptsb_manifest_read(string $tarFile): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) return [];

    $key       = 'ptsb_m_' . md5($tarFile);
    $skipCache = defined('PTSB_SKIP_MANIFEST_CACHE') && PTSB_SKIP_MANIFEST_CACHE;

    if (!$skipCache) {
        $cached = get_transient($key);
        if (is_array($cached)) return $cached;
    }

   $jsonPath = ptsb_tar_to_json($tarFile);
    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $out      = shell_exec($env.' rclone cat '.escapeshellarg($cfg['remote'].$jsonPath).' 2>/dev/null');

    $data = json_decode((string)$out, true);
    if (!is_array($data)) $data = [];

    // Só grava transient se não estivermos no admin dessa página
    if (!$skipCache) {
        // TTL menor (5 min) para evitar “grudar” tanto mesmo fora do admin
        set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
    }
    return $data;
}

/** Escreve/mescla o manifest JSON no remoto para o arquivo .tar.gz */
function ptsb_manifest_write(string $tarFile, array $add, bool $merge=true): bool {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $tarFile === '') return false;

 $jsonPath = ptsb_tar_to_json($tarFile);
    $cur = $merge ? ptsb_manifest_read($tarFile) : [];
    if (!is_array($cur)) $cur = [];

    $data    = array_merge($cur, $add);
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

    $tmp = @tempnam(sys_get_temp_dir(), 'ptsb');
    if ($tmp === false) return false;
    @file_put_contents($tmp, $payload);

    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' cat ' . escapeshellarg($tmp)
         . ' | rclone rcat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null';
    shell_exec($cmd);
    @unlink($tmp);

    // <<< NOVO: invalida o cache do manifest deste arquivo
    delete_transient('ptsb_m_' . md5($tarFile));

    return true;
}



/** "db,plugins,uploads,..." ? ['Banco','Plugins','Mídia',...], p/ mensagens */
function ptsb_parts_to_labels($partsStr): array {
    $map = [
        'db'      => 'Banco',
        'plugins' => 'Plugins',
        'themes'  => 'Temas',
        'uploads' => 'Mídia',
        'langs'   => 'Traduções',
        'config'  => 'Config',
        'core'    => 'Core',
        'scripts' => 'Scripts',
        'others'  => 'Outros',
    ];
    $out = [];
    foreach (array_filter(array_map('trim', explode(',', strtolower((string)$partsStr)))) as $p) {
        if (isset($map[$p])) $out[] = $map[$p];
    }
    return $out;
}

/** LETRAS a partir de "parts" (para badges P,T,W,S,M,O na tabela) */
function ptsb_letters_from_parts($partsStr): array {
    $p = array_filter(array_map('trim', explode(',', strtolower((string)$partsStr))));
    return [
        'p' => in_array('plugins',$p,true),
        't' => in_array('themes',$p,true),
        'w' => in_array('core',$p,true), // só core
        's' => in_array('scripts',$p,true),
        'm' => in_array('uploads',$p,true),
        'o' => in_array('others',$p,true) || in_array('langs',$p,true) || in_array('config',$p,true),
        'd' => in_array('db',$p,true),
    ];
}


/* -------------------------------------------------------
 * Seleção da UI (chips) ? PARTS= do script
 * -----------------------------------------------------*/
/** letras padrão marcadas na UI (DB é sempre incluído) */
function ptsb_ui_default_codes(){ 
    // Agora DB é um chip (default marcado)
    $def = ['d','p','t','w','s','m','o'];
    return apply_filters('ptsb_default_ui_codes', $def);
}

function ptsb_map_ui_codes_to_parts(array $codes): array {
    $codes = array_unique(array_map('strtolower', $codes));
    $parts = [];
    if (in_array('d',$codes,true)) $parts[] = 'db';
    if (in_array('p',$codes,true)) $parts[] = 'plugins';
    if (in_array('t',$codes,true)) $parts[] = 'themes';
    if (in_array('m',$codes,true)) $parts[] = 'uploads';
    if (in_array('w',$codes,true)) $parts[] = 'core';             // só core
    if (in_array('s',$codes,true)) $parts[] = 'scripts';
    if (in_array('o',$codes,true)) { $parts[]='others'; $parts[]='config'; $parts[]='langs'; }
    $parts = array_values(array_unique($parts));
    return apply_filters('ptsb_map_ui_codes_to_parts', $parts, $codes);
}


/* -------------------------------------------------------
 * Drive: quota e e-mail (best effort)
 * -----------------------------------------------------*/
function ptsb_drive_info() {
    $cfg  = ptsb_cfg();
    $info = ['email'=>'', 'used'=>null, 'total'=>null];
    if (!ptsb_can_shell()) return $info;

    $env      = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 ';
    $remote   = $cfg['remote'];
    $rem_name = rtrim($remote, ':');

    $aboutJson = shell_exec($env.' rclone about '.escapeshellarg($remote).' --json 2>/dev/null');
    $j = json_decode((string)$aboutJson, true);
    if (is_array($j)) {
        if (isset($j['used']))  $info['used']  = (int)$j['used'];
        if (isset($j['total'])) $info['total'] = (int)$j['total'];
    } else {
        $txt = (string)shell_exec($env.' rclone about '.escapeshellarg($remote).' 2>/dev/null');
        if (preg_match('/Used:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m))  $info['used']  = ptsb_size_to_bytes($m[1], $m[2]);
        if (preg_match('/Total:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) $info['total'] = ptsb_size_to_bytes($m[1], $m[2]);
    }

    // tenta userinfo
    $u = (string)shell_exec($env.' rclone backend userinfo '.escapeshellarg($remote).' 2>/dev/null');
    if (trim($u) === '') {
        $u = (string)shell_exec($env.' rclone config userinfo '.escapeshellarg($rem_name).' 2>/dev/null');
    }
    if ($u !== '') {
        $ju = json_decode($u, true);
        if (is_array($ju)) {
            if (!empty($ju['email']))                     $info['email'] = $ju['email'];
            elseif (!empty($ju['user']['email']))         $info['email'] = $ju['user']['email'];
            elseif (!empty($ju['user']['emailAddress']))  $info['email'] = $ju['user']['emailAddress'];
        } else {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $u, $m)) $info['email'] = $m[0];
        }
    }
    return $info;
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

/* -------------------------------------------------------
 * Plano "Sempre manter" (marca .keep no próximo arquivo gerado)
 * -----------------------------------------------------*/
function ptsb_plan_mark_keep_next($prefix){
    $prefix = (string)$prefix;
    if ($prefix === '') $prefix = ptsb_cfg()['prefix'];
    update_option('ptsb_mark_keep_plan', ['prefix'=>$prefix, 'set_at'=>time()], true);
}

function ptsb_apply_keep_sidecar($file){
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $file==='') return false;
    $touch = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
           . ' rclone touch ' . escapeshellarg($cfg['remote'].$file.'.keep') . ' --no-create-dirs';
    $rcat  = 'printf "" | /usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
           . ' rclone rcat ' . escapeshellarg($cfg['remote'].$file.'.keep');
    shell_exec($touch . ' || ' . $rcat);
    return true;
}


/* -------------------------------------------------------
 * AUTOMAÇÃO — opções (modo + cfg)
 * -----------------------------------------------------*/
function ptsb_auto_get() {
    $cfg   = ptsb_cfg();
    $en    = (bool) get_option('ptsb_auto_enabled', false);
    $qty   = max(1, min((int) get_option('ptsb_auto_qty', 1), $cfg['max_per_day']));
    $times = get_option('ptsb_auto_times', []); // legado
    if (!is_array($times)) $times = [];
    $times = array_values(array_filter(array_map('strval', $times)));

    $mode  = get_option('ptsb_auto_mode', 'daily');
    $mcfg  = get_option('ptsb_auto_cfg', []);
    if (!is_array($mcfg)) $mcfg = [];

    // estado (registro por slot + fila)
    $state = get_option('ptsb_auto_state', []);
    if (!is_array($state)) $state = [];
    $state += ['last_by_slot'=>[], 'queued_slot'=>'', 'queued_at'=>0];
    if (!is_array($state['last_by_slot'])) $state['last_by_slot'] = [];

    return ['enabled'=>$en, 'qty'=>$qty, 'times'=>$times, 'mode'=>$mode, 'cfg'=>$mcfg, 'state'=>$state];
}
function ptsb_auto_save($enabled, $qty, $times, $state=null, $mode=null, $mcfg=null) {
    $cfg = ptsb_cfg();
    update_option('ptsb_auto_enabled', (bool)$enabled, true);
    update_option('ptsb_auto_qty', max(1, min((int)$qty, $cfg['max_per_day'])), true);
    update_option('ptsb_auto_times', array_values($times), true); // legado
    if ($mode !== null) update_option('ptsb_auto_mode', $mode, true);
    if ($mcfg !== null) update_option('ptsb_auto_cfg', $mcfg, true);
    if ($state !== null) update_option('ptsb_auto_state', $state, true);
}

/* -------------------------------------------------------
 * Listagem Drive + mapa de .keep
 * -----------------------------------------------------*/
function ptsb_list_remote_files() {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) { return []; }
    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "tsp" --separator ";" --time-format RFC3339 '
         . ' --include ' . escapeshellarg('*.tar.gz') . ' --fast-list';
    $out = shell_exec($cmd);
    $rows = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $ln) {
        $parts = explode(';', $ln, 3);
        if (count($parts) === 3) $rows[] = ['time'=>$parts[0], 'size'=>$parts[1], 'file'=>$parts[2]];
    }
    usort($rows, fn($a,$b) => strcmp($b['time'], $a['time']));
    return $rows;
}

function ptsb_keep_map() {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) return [];
    $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "p" --separator ";" '
         . ' --include ' . escapeshellarg('*.tar.gz.keep') . ' --fast-list';
    $out = shell_exec($cmd);
    $map = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $p) {
        $base = preg_replace('/\.keep$/', '', $p);
        if ($base) $map[$base] = true;
    }
    return $map;
}

/* -------------------------------------------------------
 * Helpers de horário (agenda)
 * -----------------------------------------------------*/
function ptsb_parse_time_hm($s) {
    if (!preg_match('/^\s*([01]?\d|2[0-3])\s*:\s*([0-5]\d)\s*$/', $s, $m)) return null;
    return [(int)$m[1], (int)$m[2]];
}
function ptsb_times_sort_unique($times) {
    $seen = []; $out=[];
    foreach ($times as $t) {
        $hm = ptsb_parse_time_hm(trim($t)); if (!$hm) continue;
        $norm = sprintf('%02d:%02d', $hm[0], $hm[1]);
        if (!isset($seen[$norm])) { $seen[$norm]=1; $out[]=$norm; }
    }
    sort($out, SORT_STRING);
    return $out;
}
function ptsb_time_to_min($t){ [$h,$m]=ptsb_parse_time_hm($t); return $h*60+$m; }
function ptsb_min_to_time($m){ $m=max(0,min(1439,(int)round($m))); return sprintf('%02d:%02d', intdiv($m,60), $m%60); }

/** gera X horários igualmente espaçados na janela [ini..fim] inclusive */
function ptsb_evenly_distribute($x, $ini='00:00', $fim='23:59'){
    $x = max(1,(int)$x);
    $a = ptsb_time_to_min($ini); $b = ptsb_time_to_min($fim);
    if ($b < $a) $b = $a;
    if ($x === 1) return [ptsb_min_to_time($a)];
    $span = $b - $a;
    $step = $span / max(1, ($x-1));
    $out  = [];
    for($i=0;$i<$x;$i++){ $out[] = ptsb_min_to_time($a + $i*$step); }
    return ptsb_times_sort_unique($out);
}

/* ---- Cálculo de horários por modo ---- */
function ptsb_today_slots_by_mode($mode, $mcfg, DateTimeImmutable $refDay) {
    $mode = $mode ?: 'daily';
    $mcfg = is_array($mcfg) ? $mcfg : [];
    switch($mode){
        case 'weekly':
            $dow = (int)$refDay->format('w'); // 0=Dom
            $days = array_map('intval', $mcfg['days'] ?? []);
            if (!in_array($dow, $days, true)) return [];
            return ptsb_times_sort_unique($mcfg['times'] ?? []);
        case 'every_n':
            $n = max(1, min(30, (int)($mcfg['n'] ?? 1)));
            $startS = $mcfg['start'] ?? $refDay->format('Y-m-d');
            try { $start = new DateTimeImmutable($startS.' 00:00:00', ptsb_tz()); }
            catch(Throwable $e){ $start = $refDay->setTime(0,0); }
            $diffDays = (int)$start->diff($refDay->setTime(0,0))->days;
            if ($diffDays % $n !== 0) return [];
            return ptsb_times_sort_unique($mcfg['times'] ?? []);
        case 'x_per_day':
            $x = max(1, min(6, (int)($mcfg['x'] ?? 1)));
            $ws= (string)($mcfg['win_start'] ?? '00:00');
            $we= (string)($mcfg['win_end']   ?? '23:59');
            return ptsb_evenly_distribute($x, $ws, $we);
        case 'daily':
        default:
            return ptsb_times_sort_unique($mcfg['times'] ?? []);
    }
}

/** Próximas N execuções considerando o modo */
function ptsb_next_occurrences_adv($auto, $n = 5) {
    $now  = ptsb_now_brt();
    $list = [];
    $mode = $auto['mode'] ?? 'daily';
    $mcfg = $auto['cfg']  ?? [];
    for ($d=0; $d<60 && count($list)<$n; $d++) {
        $base = $now->setTime(0,0)->modify("+$d day");
        $slots = ptsb_today_slots_by_mode($mode, $mcfg, $base);
        foreach ($slots as $t) {
            [$H,$M] = ptsb_parse_time_hm($t);
            $dt = $base->setTime($H,$M);
            if ($d===0 && $dt <= $now) continue;
            $list[] = $dt;
        }
    }
    usort($list, fn($a,$b)=>$a<$b?-1:1);
    return array_slice($list, 0, $n);
}

/* -------------------------------------------------------
 * Helper Ignorar execuções futuras (por data/hora local)
 * -----------------------------------------------------*/
function ptsb_skipmap_get(): array {
    $m = get_option('ptsb_skip_slots', []);
    if (!is_array($m)) $m = [];
    $out = [];
    foreach ($m as $k=>$v) { $k = trim((string)$k); if ($k!=='') $out[$k] = true; }
    return $out;
}
function ptsb_skipmap_save(array $m): void { update_option('ptsb_skip_slots', $m, true); }
function ptsb_skip_key(DateTimeImmutable $dt): string { return $dt->format('Y-m-d H:i'); }

/* limpeza simples: mantém só itens até 3 dias após a data/hora */
function ptsb_skipmap_gc(): void {
    $map = ptsb_skipmap_get(); if (!$map) return;
    $now = ptsb_now_brt()->getTimestamp();
    $keep = [];
    foreach (array_keys($map) as $k) {
        try { $dt = new DateTimeImmutable($k.':00', ptsb_tz()); }
        catch(Throwable $e){ $dt = null; }
        if ($dt && ($dt->getTimestamp() + 3*86400) > $now) $keep[$k] = true;
    }
    ptsb_skipmap_save($keep);
}


/* ===================== CICLOS (rotinas) ===================== */

/* UUID v4 simples p/ id de rotina */
function ptsb_uuid4(){
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/* ---- Store: ciclos, estado, global ---- */
function ptsb_cycles_get(){ $c = get_option('ptsb_cycles', []); return is_array($c)? $c: []; }
function ptsb_cycles_save(array $c){
    update_option('ptsb_cycles', array_values($c), true);
    // Qualquer alteração nas rotinas desativa a auto-migração para sempre
    update_option('ptsb_cycles_legacy_migrated', 1, true);
}


function ptsb_cycles_state_get(){
    $s = get_option('ptsb_cycles_state', []);
    if (!is_array($s)) $s = [];
    // 1 única fila global simplificada
    $s += ['by_cycle'=>[], 'queued'=>['cycle_id'=>'','time'=>'','letters'=>[],'cycle_ids'=>[],'prefix'=>'','keep_days'=>null,'keep_forever'=>0,'queued_at'=>0]];
    if (!is_array($s['by_cycle'])) $s['by_cycle']=[];
    if (!is_array($s['queued'])) $s['queued']=['cycle_id'=>'','time'=>'','letters'=>[],'queued_at'=>0];
    return $s;
}
function ptsb_cycles_state_save(array $s){ update_option('ptsb_cycles_state', $s, true); }

function ptsb_cycles_global_get(){
    $cfg = ptsb_cfg();
    $g = get_option('ptsb_cycles_global', []);
    if (!is_array($g)) $g = [];

$g += [
    'merge_dupes' => false,                   // sempre DESLIGADO
    'policy'      => 'queue',                 // sempre ENFILEIRAR
    'min_gap_min' => (int)$cfg['min_gap_min'] // 10 pelo cfg()
];
// reforça os valores, mesmo que exista algo salvo legado:
$g['merge_dupes'] = false;
$g['policy']      = 'queue';
$g['min_gap_min'] = (int)$cfg['min_gap_min'];
return $g;


}
function ptsb_cycles_global_save(array $g){
    $def = ptsb_cycles_global_get();
    $out = array_merge($def, $g);
    $out['merge_dupes'] = (bool)$out['merge_dupes'];
    $out['policy']      = in_array($out['policy'], ['skip','queue'], true) ? $out['policy'] : 'skip';
    $out['min_gap_min'] = max(1, (int)$out['min_gap_min']);
    update_option('ptsb_cycles_global', $out, true);
}

/* ---- Slots por rotina (inclui novo modo interval) ---- */
function ptsb_cycle_today_slots(array $cycle, DateTimeImmutable $refDay){
    $mode = $cycle['mode'] ?? 'daily';
    $cfg  = is_array($cycle['cfg'] ?? null) ? $cycle['cfg'] : [];
    switch ($mode) {

       case 'weekly':
    $dow  = (int)$refDay->format('w'); // 0=Dom
    $days = array_map('intval', $cfg['days'] ?? []);
    if (!in_array($dow, $days, true)) return [];
    // novo: aceita vários horários (compat com 'time')
    $times = $cfg['times'] ?? [];
    if (!$times && !empty($cfg['time'])) { $times = [$cfg['time']]; }
    return ptsb_times_sort_unique($times);

case 'every_n':
    $n = max(1, min(30, (int)($cfg['n'] ?? 1)));
    $startS = $cfg['start'] ?? $refDay->format('Y-m-d');
    try { $start = new DateTimeImmutable($startS.' 00:00:00', ptsb_tz()); }
    catch(Throwable $e){ $start = $refDay->setTime(0,0); }
    $diffDays = (int)$start->diff($refDay->setTime(0,0))->days;
    if ($diffDays % $n !== 0) return [];
    // novo: aceita vários horários (compat com 'time')
    $times = $cfg['times'] ?? [];
    if (!$times && !empty($cfg['time'])) { $times = [$cfg['time']]; }
    return ptsb_times_sort_unique($times);


                case 'interval':
            // every: {"value":2,"unit":"hour"|"minute"|"day"}
            // win  : {"start":"08:00","end":"20:00","disabled":1|0}
            $every = $cfg['every'] ?? ['value'=>60,'unit'=>'minute'];
            $val   = max(1, (int)($every['value'] ?? 60));
            $unit  = strtolower((string)($every['unit'] ?? 'minute'));

            // agora aceita "day"
            if ($unit === 'day') {
                $stepMin = $val * 1440;        // N dias
            } elseif ($unit === 'hour') {
                $stepMin = $val * 60;          // N horas
            } else {
                $stepMin = $val;               // N minutos
            }

            $winDisabled = !empty($cfg['win']['disabled']);

            // se a janela estiver desativada, usa o dia inteiro
            $ws = $winDisabled ? '00:00' : (string)($cfg['win']['start'] ?? '00:00');
            $we = $winDisabled ? '23:59' : (string)($cfg['win']['end']   ?? '23:59');

            $a = ptsb_time_to_min($ws); $b = ptsb_time_to_min($we);
            if ($b < $a) $b = $a;

            $out=[]; $m=$a;
            while($m <= $b){
                $out[] = ptsb_min_to_time($m);
                $m += $stepMin;
            }
            return ptsb_times_sort_unique($out);

        case 'daily':
        default:
            $times = $cfg['times'] ?? [];
            return ptsb_times_sort_unique($times);
    }
}

/** Ocorrências consolidadas para UMA data (YYYY-mm-dd) */
function ptsb_cycles_occurrences_for_date(array $cycles, DateTimeImmutable $day): array {
    $now = ptsb_now_brt();
    $list = [];
    $map  = []; // 'HH:MM' => ['letters'=>set,'names'=>[]]

    foreach ($cycles as $cy) {
        if (empty($cy['enabled'])) continue;
        $slots = ptsb_cycle_today_slots($cy, $day);
        foreach ($slots as $t) {
            // se for hoje, ignora horários já passados
            if ($day->format('Y-m-d') === $now->format('Y-m-d')) {
                [$H,$M] = ptsb_parse_time_hm($t);
                if ($day->setTime($H,$M) <= $now) continue;
            }
            if (!isset($map[$t])) $map[$t] = ['letters'=>[], 'names'=>[]];
            $map[$t]['names'][] = (string)($cy['name'] ?? 'Rotina');
            foreach ((array)($cy['letters'] ?? []) as $L) $map[$t]['letters'][strtoupper($L)] = true;
        }
    }

    $times = array_keys($map); sort($times, SORT_STRING);
    foreach ($times as $t) {
        [$H,$M] = ptsb_parse_time_hm($t);
        $dt = $day->setTime($H,$M);
        $list[] = [
            'dt'      => $dt,
            'letters' => array_keys($map[$t]['letters']),
            'names'   => $map[$t]['names'],
        ];
    }
    return $list;
}


/* Próximas N execuções (todas as rotinas, já mescladas) */
function ptsb_cycles_next_occurrences(array $cycles, $n=6){
    $g = ptsb_cycles_global_get();
    $now = ptsb_now_brt();
    $list = []; // cada item: ['dt'=>DateTimeImmutable,'letters'=>[],'names'=>[]]
    // gera por até 60 dias adiante (suficiente p/ consolidar N slots)
    for($d=0; $d<60 && count($list)<$n; $d++){
        $base = $now->setTime(0,0)->modify("+$d day");
        $map = []; // 'HH:MM' => ['letters'=>set,'names'=>[]]
        foreach ($cycles as $cy) {
            if (empty($cy['enabled'])) continue;
            $slots = ptsb_cycle_today_slots($cy, $base);
            foreach ($slots as $t) {
                if ($d===0 && $base->format('Y-m-d')===$now->format('Y-m-d') && $base->setTime(...ptsb_parse_time_hm($t)) <= $now) {
                    continue;
                }
                $key = $t;
                if (!isset($map[$key])) $map[$key] = ['letters'=>[], 'names'=>[]];
                $map[$key]['names'][] = (string)($cy['name'] ?? 'Rotina');
                foreach ((array)($cy['letters'] ?? []) as $L) $map[$key]['letters'][strtoupper($L)] = true;
            }
        }
        $times = array_keys($map); sort($times, SORT_STRING);
        foreach ($times as $t){
            $dt = $base->setTime(...ptsb_parse_time_hm($t));
            $letters = array_keys($map[$t]['letters']);
            $names   = $map[$t]['names'];
            $list[] = ['dt'=>$dt,'letters'=>$letters,'names'=>$names];
            if (count($list) >= $n) break 2;
        }
    }
    return $list;
}

/* Migração: config antiga -> 1 rotina */
function ptsb_cycles_migrate_from_legacy(){
    // Rode no máximo uma vez
    if (get_option('ptsb_cycles_legacy_migrated')) return;

    $have = ptsb_cycles_get();
    if ($have) { // se já existem rotinas, considere migração concluída
        update_option('ptsb_cycles_legacy_migrated', 1, true);
        return;
    }

    // Só migra se houver algo legado para importar (evita criar "do nada")
    $auto = ptsb_auto_get(); // legado
    $mode = $auto['mode'] ?? 'daily';
    $hasLegacyCfg = !empty($auto['enabled']) || !empty($auto['cfg']) || !empty($auto['times']);
    if (!$hasLegacyCfg) {
        update_option('ptsb_cycles_legacy_migrated', 1, true);
        return;
    }

    // === cria a rotina migrada (igual ao seu código atual) ===
    $enabled = !empty($auto['enabled']);
    $name = 'Rotina migrada';
    if     ($mode==='daily')   $name = 'Diário (migrado)';
    elseif ($mode==='weekly')  $name = 'Semanal (migrado)';
    elseif ($mode==='every_n') $name = 'A cada N dias (migrado)';
    $letters = ['D','P','T','W','S','M','O'];

    $cycle = [
        'id'        => ptsb_uuid4(),
        'enabled'   => (bool)$enabled,
        'name'      => $name,
        'mode'      => in_array($mode,['daily','weekly','every_n'],true)?$mode:'daily',
        'cfg'       => is_array($auto['cfg'] ?? null) ? $auto['cfg'] : [],
        'letters'   => $letters,
        'policy'    => 'queue',
        'priority'  => 0,
        'created_at'=> gmdate('c'),
        'updated_at'=> gmdate('c'),
    ];
    ptsb_cycles_save([$cycle]);

    // marca como migrado para não recriar no futuro (mesmo que excluam tudo depois)
    update_option('ptsb_cycles_legacy_migrated', 1, true);
}
add_action('init', 'ptsb_cycles_migrate_from_legacy', 5);



/* -------------------------------------------------------
 * Cron — agenda minutely
 * -----------------------------------------------------*/
add_filter('cron_schedules', function($s){
    $s['ptsb_minutely'] = ['interval'=>60, 'display'=>'PTSB a cada 1 minuto'];
    return $s;
});
add_action('init', function(){
    $cfg  = ptsb_cfg();
    $hook = $cfg['cron_hook'];

    $auto_enabled = !empty(ptsb_auto_get()['enabled']);
    $has_enabled_cycle = false;
    foreach (ptsb_cycles_get() as $cy) {
        if (!empty($cy['enabled'])) { $has_enabled_cycle = true; break; }
    }

    if ($auto_enabled || $has_enabled_cycle) {
        if (!wp_next_scheduled($hook)) {
            wp_schedule_event(time()+30, $cfg['cron_sched'], $hook);
        }
    } else {
        wp_clear_scheduled_hook($hook);
    }
});


add_action('ptsb_cron_tick', function(){
    $cfg  = ptsb_cfg();
    $now  = ptsb_now_brt();
    $today= $now->format('Y-m-d');
    $miss = (int)$cfg['miss_window'];

 $cycles = ptsb_cycles_get();
if (!$cycles) {
    return; // Sem rotinas = nada a fazer (desliga o legado)
}


    // ====== NOVA ENGINE: rotinas ======
    $g       = ptsb_cycles_global_get();
    $state   = ptsb_cycles_state_get();
    $running = file_exists($cfg['lock']);
    // carregar/limpar mapa de execuções a ignorar
    ptsb_skipmap_gc();
    $skipmap = ptsb_skipmap_get();

    // Se tem fila pendente e não está rodando, executa-a
    if (!$running && !empty($state['queued']['time'])) {
        $letters = (array)$state['queued']['letters'];
        $partsCsv = function_exists('ptsb_letters_to_parts_csv')
            ? ptsb_letters_to_parts_csv($letters)
            : implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower',$letters)));
        
            $qpref = $state['queued']['prefix'] ?? null;
$qdays = $state['queued']['keep_days'] ?? null;

if (!empty($state['queued']['keep_forever'])) {
    ptsb_plan_mark_keep_next($qpref ?: ptsb_cfg()['prefix']);
}

  // ?? salva intenção da rotina em execução
    update_option('ptsb_last_run_intent', [
        'prefix'       => ($qpref ?: ptsb_cfg()['prefix']),
        'keep_days'    => ($qdays === null ? (int)ptsb_settings()['keep_days'] : (int)$qdays),
        'keep_forever' => !empty($state['queued']['keep_forever']) ? 1 : 0,
        'origin'       => 'routine',
        'started_at'   => time(),
    ], true);

ptsb_start_backup($partsCsv, $qpref, $qdays);

        
        // marca as rotinas afetadas como executadas hoje no slot
        $qtime = $state['queued']['time'];
        foreach ((array)$state['queued']['cycle_ids'] as $cid){
            $cst = $state['by_cycle'][$cid] ?? ['last_by_slot'=>[],'queued_slot'=>'','queued_at'=>0];
            $cst['last_by_slot'][$qtime] = $today;
            $state['by_cycle'][$cid] = $cst;
        }
$state['queued'] = [
  'cycle_id'     => '',
  'time'         => '',
  'letters'      => [],
  'cycle_ids'    => [],
  'prefix'       => '',
  'keep_days'    => null,
  'keep_forever' => 0,
  'queued_at'    => 0,
];

        ptsb_cycles_state_save($state);
        return;
    }

    // 1) gerar slots de hoje por rotina
    $cand = []; // cada item: ['time'=>'HH:MM','letters'=>set,'cycle_ids'=>[]]
    foreach ($cycles as $cy) {
        if (empty($cy['enabled'])) continue;
        $cid   = (string)$cy['id'];
        $times = ptsb_cycle_today_slots($cy, $now);
        $cst   = $state['by_cycle'][$cid] ?? ['last_by_slot'=>[],'queued_slot'=>'','queued_at'=>0];
        
       $cy_prefix   = ptsb_slug_prefix((string)($cy['name'] ?? ''));
$raw_days    = $cy['keep_days'] ?? null;
$cy_forever  = (isset($raw_days) && (int)$raw_days === 0);
$cy_days     = (isset($raw_days) && !$cy_forever) ? max(1, (int)$raw_days) : null;

        
        foreach ($times as $t) {
            $ran = isset($cst['last_by_slot'][$t]) && $cst['last_by_slot'][$t] === $today;
            if ($ran) continue;
            if ($g['merge_dupes']) {
                $idx = array_search($t, array_column($cand,'time'), true);
                if ($idx === false) {
                  $cand[] = [
  'time'=>$t,
  'letters'=>array_fill_keys(array_map('strtoupper', (array)($cy['letters']??[])), true),
  'cycle_ids'=>[$cid],
  'policies'=>[(string)($cy['policy']??'skip')],
  'prefix'=>$cy_prefix,
  'keep_days'=>$cy_days,
  'keep_forever'=>$cy_forever
];

                } else {
                    foreach ((array)($cy['letters']??[]) as $L) $cand[$idx]['letters'][strtoupper($L)] = true;
                    $cand[$idx]['cycle_ids'][] = $cid;
                    $cand[$idx]['policies'][]  = (string)($cy['policy']??'skip');
                    if (empty($cand[$idx]['prefix'])) $cand[$idx]['prefix'] = $cy_prefix;
                    if (empty($cand[$idx]['keep_days'])) $cand[$idx]['keep_days'] = $cy_days;
                }
            } else {
               $cand[] = [
  'time'=>$t,
  'letters'=>array_fill_keys(array_map('strtoupper', (array)($cy['letters']??[])), true),
  'cycle_ids'=>[$cid],
  'policies'=>[(string)($cy['policy']??'skip')],
  'prefix'=>$cy_prefix,
  'keep_days'=>$cy_days,
  'keep_forever'=>$cy_forever
];

            }
        }
    }
    if (!$cand) return;

    // ordena por horário
    usort($cand, fn($a,$b)=>strcmp($a['time'],$b['time']));

    foreach ($cand as $slot) {
        [$H,$M] = ptsb_parse_time_hm($slot['time']);
        $dt     = $now->setTime($H,$M);
        $diff   = $now->getTimestamp() - $dt->getTimestamp();
        
        // >>> ignorar esta execução se marcada no painel
    $key = ptsb_skip_key($dt);
    if (!empty($skipmap[$key])) {
        ptsb_log('Execução ignorada por marcação do painel: '.$key.' (BRT).');

        // marca TODAS as rotinas do mesmo minuto como "processadas hoje"
        foreach ($cand as $slot2) {
            if ($slot2['time'] !== $slot['time']) continue;
            foreach ($slot2['cycle_ids'] as $cid){
                $cst = $state['by_cycle'][$cid] ?? ['last_by_slot'=>[],'queued_slot'=>'','queued_at'=>0];
                $cst['last_by_slot'][$slot2['time']] = $today;
                $state['by_cycle'][$cid] = $cst;
            }
        }

        // consome a marca (é "uma vez só") e persiste
        unset($skipmap[$key]);
        ptsb_skipmap_save($skipmap);
        ptsb_cycles_state_save($state);
        return; // 1 ação por tick
    }
        
        if ($diff >= 0 && $diff <= ($miss*60)) {
            // dentro da janela do minuto
            $letters = array_keys($slot['letters']);
            $wantQueue = in_array('queue', $slot['policies'], true) || $g['policy']==='queue';

            if ($running) {
    if ($wantQueue && empty($state['queued']['time'])) {
        $state['queued'] = [
          'cycle_id'     => '', // mantido para compat
          'time'         => $slot['time'],
          'letters'      => array_keys($slot['letters']),
          'cycle_ids'    => (array)$slot['cycle_ids'],
          'prefix'       => (string)($slot['prefix'] ?? ''),
          'keep_days'    => $slot['keep_days'] ?? null,
          'keep_forever' => !empty($slot['keep_forever']) ? 1 : 0,
          'queued_at'    => time(),
        ];
        ptsb_log('Execução adiada: outra em andamento; enfileirado '.$slot['time'].'.');
    } else {
        ptsb_log('Execução pulada: já em andamento; política=skip.');
    }
                // marca como "processado no dia" (não tenta de novo)
                foreach ($slot['cycle_ids'] as $cid){
                    $cst = $state['by_cycle'][$cid] ?? ['last_by_slot'=>[],'queued_slot'=>'','queued_at'=>0];
                    $cst['last_by_slot'][$slot['time']] = $today;
                    $state['by_cycle'][$cid] = $cst;
                }
                ptsb_cycles_state_save($state);
                return;
            }

            // dispara agora
            $partsCsv = function_exists('ptsb_letters_to_parts_csv')
                ? ptsb_letters_to_parts_csv($letters)
                : implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower',$letters)));
            ptsb_log('Backup (rotinas) às '.$slot['time'].' (BRT).');
            //  "sempre manter" (rotina em execução imediata)
if (!empty($slot['keep_forever'])) {
    ptsb_plan_mark_keep_next(($slot['prefix'] ?? '') ?: ptsb_cfg()['prefix']);
}

// ?? salva intenção da rotina em execução
update_option('ptsb_last_run_intent', [
    'prefix'       => (($slot['prefix'] ?? '') ?: ptsb_cfg()['prefix']),
    'keep_days'    => (isset($slot['keep_days']) && $slot['keep_days'] !== null)
                        ? (int)$slot['keep_days']
                        : (int)ptsb_settings()['keep_days'],
    'keep_forever' => !empty($slot['keep_forever']) ? 1 : 0,
    'origin'       => 'routine',
    'started_at'   => time(),
], true);

             ptsb_start_backup($partsCsv, $slot['prefix'] ?? null, $slot['keep_days'] ?? null);
            foreach ($slot['cycle_ids'] as $cid){
                $cst = $state['by_cycle'][$cid] ?? ['last_by_slot'=>[],'queued_slot'=>'','queued_at'=>0];
                $cst['last_by_slot'][$slot['time']] = $today;
                $state['by_cycle'][$cid] = $cst;
            }
            ptsb_cycles_state_save($state);
            return;
        }
        if ($diff > ($miss*60)) {
            // janela perdida -> marca
            foreach ($slot['cycle_ids'] as $cid){
                $cst = $state['by_cycle'][$cid] ?? ['last_by_slot'=>[],'queued_slot'=>'','queued_at'=>0];
                $cst['last_by_slot'][$slot['time']] = $today;
                $state['by_cycle'][$cid] = $cst;
            }
            ptsb_cycles_state_save($state);
        }
    }

    // timeout da fila global
    if (!empty($state['queued']['time']) && (time() - (int)$state['queued']['queued_at']) > (int)$cfg['queue_timeout']) {
    ptsb_log('Fila global descartada por timeout.');
    $state['queued'] = [
      'cycle_id'     => '',
      'time'         => '',
      'letters'      => [],
      'cycle_ids'    => [],
      'prefix'       => '',
      'keep_days'    => null,
      'keep_forever' => 0,
      'queued_at'    => 0,
    ];
    ptsb_cycles_state_save($state);
}

});

/* -------------------------------------------------------
 * DISPARO do backup — agora aceita override de PREFIX e KEEP_DAYS
 * -----------------------------------------------------*/
/**
 * Dispara o .sh de backup. Se $partsCsv vier vazio, usa:
 *  - última seleção da UI (option 'ptsb_last_parts_ui'), ou
 *  - fallback: apply_filters('ptsb_default_parts', 'db,plugins,themes,uploads,langs,config,scripts')
 *
 * Observação: permite KEEP_DAYS = 0 (sentinela "sempre manter"), sem forçar para 1.
 */
function ptsb_start_backup($partsCsv = null, $overridePrefix = null, $overrideDays = null){
    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    if (!ptsb_can_shell()) return;
    if (file_exists($cfg['lock'])) { return; }

    ptsb_log_rotate_if_needed();

    // 1) tenta última seleção (letras D,P,T,W,S,M,O)
    if ($partsCsv === null) {
        $last = get_option('ptsb_last_parts_ui', implode(',', ptsb_ui_default_codes()));
        $letters = array_values(array_intersect(
            array_map('strtoupper', array_filter(array_map('trim', explode(',', (string)$last)))) ,
            ['D','P','T','W','S','M','O']
        ));
        if (!$letters) { $letters = array_map('strtoupper', ptsb_ui_default_codes()); }
        if (function_exists('ptsb_letters_to_parts_csv')) {
            $partsCsv = ptsb_letters_to_parts_csv($letters);
        } else {
            $partsCsv = implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower', $letters)));
        }
    }

    // 2) fallback final personalizável
    if (!$partsCsv) {
        $partsCsv = apply_filters('ptsb_default_parts', 'db,plugins,themes,uploads,langs,config,scripts');
    }

    $prefix = ($overridePrefix !== null && $overridePrefix !== '') ? $overridePrefix : $cfg['prefix'];

    // >>> ALTERAÇÃO: permitir 0 (sentinela "sempre manter")
    if ($overrideDays !== null) {
        $keepDays = max(0, (int)$overrideDays);   // 0 = sempre manter; >0 = dias; null = usa padrão
    } else {
        $keepDays = (int)$set['keep_days'];
    }

    $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . 'REMOTE='           . escapeshellarg($cfg['remote'])     . ' '
         . 'WP_PATH='          . escapeshellarg(ABSPATH)            . ' '
         . 'PREFIX='           . escapeshellarg($prefix)            . ' '
         . 'KEEP_DAYS='        . escapeshellarg($keepDays)          . ' '
         . 'KEEP='             . escapeshellarg($keepDays)          . ' '
         . 'RETENTION_DAYS='   . escapeshellarg($keepDays)          . ' '
         . 'RETENTION='        . escapeshellarg($keepDays)          . ' '
         . 'KEEP_FOREVER='     . escapeshellarg($keepDays === 0 ? 1 : 0) . ' ' // opcional p/ scripts que queiram esse flag
         . 'PARTS='            . escapeshellarg($partsCsv);

    // guarda as partes usadas neste disparo (fallback para a notificação)
    update_option('ptsb_last_run_parts', (string)$partsCsv, true);

    $cmd = '/usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_backup'])
         . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';

    shell_exec($cmd);
}



/* -------------------------------------------------------
 * Ações (admin-post.php)
 * -----------------------------------------------------*/
add_action('admin_post_ptsb_do', function () {
    if (!current_user_can('manage_options')) { wp_die('forbidden'); }
    check_admin_referer('ptsb_nonce');

    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    $act = isset($_POST['ptsb_action']) ? sanitize_text_field($_POST['ptsb_action']) : '';

        /* ===== Limpar log + rotações ===== */
    if ($act === 'clear_log') {
        ptsb_log_clear_all();
        add_settings_error('ptsb','log_cleared','Log limpo (incluindo rotações).','updated');
        ptsb_back();
    }


   /* ===== Disparar manual (topo) — lendo as letras D,P,T,W,S,M,O ===== */
if ($act === 'backup_now') {
    if (!ptsb_can_shell()) { add_settings_error('ptsb', 'noshell', 'shell_exec desabilitado no PHP.', 'error'); ptsb_back(); }
    if (file_exists($cfg['lock'])) {
        add_settings_error('ptsb', 'bk_running', 'J&aacute; existe um backup em execu&ccedil;&atilde;o. Aguarde concluir antes de iniciar outro.', 'error');
        ptsb_back();
    }

    // 1) Preferir as letras vindas dos chips (parts_sel[]). Se não vier, aceitar parts_ui[] (legado).
    $letters = [];
    if (isset($_POST['parts_sel']) && is_array($_POST['parts_sel'])) {
        $letters = array_values(array_unique(array_map('strtoupper', array_map('strval', $_POST['parts_sel']))));
    } elseif (isset($_POST['parts_ui']) && is_array($_POST['parts_ui'])) {
        $letters = array_values(array_unique(array_map('strtoupper', array_map('strval', $_POST['parts_ui']))));
    } else {
        $letters = array_map('strtoupper', ptsb_ui_default_codes()); // default = tudo ON (inclui D)
    }
    // manter apenas letras válidas
    $letters = array_values(array_intersect($letters, ['D','P','T','W','S','M','O']));
    if (!$letters) { $letters = ['D','P','T','W','S','M','O']; }

    // 2) Lembrar para pré-marcar na UI
    update_option('ptsb_last_parts_ui', implode(',', $letters), true);

    // 3) Montar PARTS= para o script
    if (function_exists('ptsb_letters_to_parts_csv')) {
        $partsCsv = ptsb_letters_to_parts_csv($letters);
    } else {
        // fallback (se PARTE 2 não existir por algum motivo)
        $partsCsv = implode(',', ptsb_map_ui_codes_to_parts(array_map('strtolower', $letters)));
    }

// 4) Nome e retenção (Backup Manual) — obrigatório, salvo se "Sempre manter"
$manual_name  = sanitize_text_field($_POST['manual_name'] ?? '');
$keep_forever = !empty($_POST['manual_keep_forever']);
$manual_days  = null;

if ($keep_forever) {
    // sentinel 0 para "sempre manter" (manifest cuida disso depois)
    $manual_days = 0;
} else {
    $raw = isset($_POST['manual_keep_days']) ? trim((string)$_POST['manual_keep_days']) : '';
    if ($raw === '' || !is_numeric($raw)) {
        add_settings_error('ptsb','mkd_required','Informe a quantidade de dias de retenção (ou marque "Sempre manter").','error');
        ptsb_back();
    }
    $manual_days = max(1, min((int)$raw, 3650));
}

// o usuário digita só o apelido; nós geramos "wpb-<apelido>-"
$nick    = $manual_name !== '' ? ptsb_slug_prefix($manual_name) : '';
$prefix  = $manual_name !== '' ? (ptsb_cfg()['prefix'] . $nick) : null;

$effPrefix = ($prefix !== null && $prefix !== '') ? $prefix : ptsb_cfg()['prefix'];
if (!empty($_POST['manual_keep_forever'])) {
    ptsb_plan_mark_keep_next($effPrefix);
}

update_option('ptsb_last_run_intent', [
    'prefix'       => $effPrefix,
    'keep_days'    => (int)$manual_days,                      // usa exatamente o informado (0 ou >0)
    'keep_forever' => $keep_forever ? 1 : 0,
    'origin'       => 'manual',
    'started_at'   => time(),
], true);

ptsb_start_backup($partsCsv, $prefix, $manual_days);


    // 6) Mensagem
    $human = ptsb_parts_to_labels($partsCsv);
    $txt = 'Backup disparado'.($human ? ' (incluindo: '.esc_html(implode(', ', $human)).')' : '').'. Acompanhe abaixo.';
    add_settings_error('ptsb', 'bk_started', $txt, 'updated');
    ptsb_back();
}


    /* ===== Salvar TUDO (retenção + agenda/mode) ===== */
    if (in_array($act, ['save_all','save_settings','save_auto'], true)) {

        // retenção (apenas por dias)
        $d = isset($_POST['keep_days']) ? (int) $_POST['keep_days'] : $cfg['keep_days_def'];
        $d = max(1, min($d, 3650));
        update_option('ptsb_keep_days', $d, true);
      
        // ======= modo/frequência =======
        $en   = !empty($_POST['auto_enabled']);
        $mode = isset($_POST['auto_mode']) ? sanitize_text_field($_POST['auto_mode']) : 'daily';
        $mode = in_array($mode, ['daily','weekly','every_n'], true) ? $mode : 'daily';

        $qty  = 1; $timesForLegacy = []; $mcfg = [];

        $normTimes = function($arr){
            $out=[];
            foreach ((array)$arr as $s){
                $s = trim((string)$s);
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m)) {
                    $out[] = sprintf('%02d:%02d', min(23,max(0,(int)$m[1])), min(59,max(0,(int)$m[2])));
                }
            }
            return ptsb_times_sort_unique($out);
        };
        $minGapCheck = function($times) use ($cfg){
            $times = ptsb_times_sort_unique($times);
            if (count($times) <= 1) return;
            $mins = array_map('ptsb_time_to_min', $times);
            sort($mins);
            $mins[] = $mins[0] + 1440;
            $minGap = 1440;
            for($i=0;$i<count($mins)-1;$i++){ $gap = $mins[$i+1]-$mins[$i]; if($gap < $minGap) $minGap=$gap; }
            if ($minGap < (int)$cfg['min_gap_min']) {
                add_settings_error('ptsb','gap_warn','Aviso: intervalos menores que '.$cfg['min_gap_min'].' min entre execu&ccedil;&otilde;es podem sobrecarregar o servidor.','warning');
            }
        };

        if ($mode === 'daily') {
            $qty   = max(1, min((int)($_POST['auto_qty'] ?? 1), $cfg['max_per_day']));
            $times = $normTimes($_POST['auto_times'] ?? []);
            if (count($times) !== $qty) {
                add_settings_error('ptsb','auto_err_qty','Informe exatamente '.$qty.' hor&aacute;rio(s) v&aacute;lido(s) no formato 24h (HH:MM).','error');
                ptsb_back();
            }
            $timesForLegacy = $times;
            $mcfg = ['times'=>$times, 'qty'=>$qty];
            $minGapCheck($times);

        } elseif ($mode === 'weekly') {
            $days  = array_map('intval', (array)($_POST['wk_days'] ?? []));
            $days  = array_values(array_unique(array_filter($days, fn($d)=>$d>=0 && $d<=6)));
            $timeS = trim((string)($_POST['wk_time'] ?? ''));
            $times = $normTimes([$timeS]);
            if (!$days) { add_settings_error('ptsb','auto_err_wk_days','Selecione pelo menos 1 dia da semana.','error'); ptsb_back(); }
            if (!$times){ add_settings_error('ptsb','auto_err_wk_time','Informe 1 hor&aacute;rio (HH:MM).','error'); ptsb_back(); }
            $mcfg = ['days'=>$days, 'times'=>$times];
            $qty  = 1;

        } else { // every_n
            $n      = max(1, min(30, (int)($_POST['ndays_n'] ?? 1)));
            $start  = sanitize_text_field($_POST['ndays_start'] ?? ptsb_now_brt()->format('Y-m-d'));
            $timeS  = trim((string)($_POST['ndays_time'] ?? ''));
            $times  = $normTimes([$timeS]);
            if (!$times) { add_settings_error('ptsb','auto_err_nd_t','Informe 1 hor&aacute;rio (HH:MM).','error'); ptsb_back(); }
            $mcfg   = ['n'=>$n, 'start'=>$start, 'times'=>$times];
            $qty    = 1;
        }

        $state = get_option('ptsb_auto_state', []);
        if (!is_array($state)) $state=[];
        $state += ['last_by_slot'=>[], 'queued_slot'=>'', 'queued_at'=>0];

        ptsb_auto_save($en, $qty, $timesForLegacy, $state, $mode, $mcfg);

        if ($en) {
            if (!wp_next_scheduled($cfg['cron_hook'])) {
                wp_schedule_event(time()+30, $cfg['cron_sched'], $cfg['cron_hook']);
            }
            $autoSaved = ptsb_auto_get();
            $next = ptsb_next_occurrences_adv($autoSaved, 1);
            if ($next) {
                add_settings_error('ptsb','all_saved','Agenda atualizada. Pr&oacute;ximo disparo: '.$next[0]->format('d/m/Y H:i').' (BRT).','updated');
            } else {
                add_settings_error('ptsb','all_saved2','Agenda atualizada.','updated');
            }
        } else {
            wp_clear_scheduled_hook($cfg['cron_hook']);
            add_settings_error('ptsb','auto_off','Backups autom&aacute;ticos desativados.','updated');
        }

        add_settings_error('ptsb', 'ret_saved', 'Configura&ccedil;&otilde;es de reten&ccedil;&atilde;o salvas.', 'updated');
        ptsb_back();
    }

    /* ===== Renomear arquivo no Drive ===== */
    if ($act === 'rename') {
        if (!ptsb_can_shell()) { add_settings_error('ptsb','noshell','shell_exec desabilitado no PHP.', 'error'); ptsb_back(); }

        $old = isset($_POST['old_file']) ? sanitize_text_field($_POST['old_file']) : '';
        $in  = isset($_POST['new_file']) ? sanitize_text_field($_POST['new_file']) : '';

        if ($old === '' || $in === '') {
            add_settings_error('ptsb','rn_empty','Nome antigo/novo inválido.', 'error'); ptsb_back();
        }
        if (strpos($old,'/')!==false || strpos($old,'\\')!==false) {
            add_settings_error('ptsb','rn_slash','Nome antigo inválido.', 'error'); ptsb_back();
        }

        $prefix = ptsb_cfg()['prefix']; // "wpb-"

        // Aceita apelido OU nome completo -> normaliza para wpb-<nick>.tar.gz
        $nick = trim($in);
        $nick = preg_replace('/\.tar\.gz$/i','', $nick);
        $nick = preg_replace('/^'.preg_quote($prefix,'/').'/','', $nick);
        $nick = preg_replace('/[^A-Za-z0-9._-]+/', '-', $nick);
        $nick = trim($nick, '.-_');

        if ($nick === '') {
            add_settings_error('ptsb','rn_badnick','Apelido inválido.', 'error'); ptsb_back();
        }

        $new = $prefix . $nick . '.tar.gz';

        if (!preg_match('/^[A-Za-z0-9._-]+\.tar\.gz$/', $new)) {
            add_settings_error('ptsb','rn_pat','Use apenas letras, números, ponto, hífen e sublinhado, terminando em .tar.gz.', 'error'); ptsb_back();
        }

        if ($old === $new) {
            add_settings_error('ptsb','rn_same','O nome não foi alterado.', 'updated'); ptsb_back();
        }

        // já existe arquivo com o novo nome?
        $exists = shell_exec(
            '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
            ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
            ' --include '.escapeshellarg($new).' --fast-list'
        );
        if (trim((string)$exists) !== '') {
            add_settings_error('ptsb','rn_exists','Já existe um arquivo com esse nome no Drive.', 'error'); ptsb_back();
        }

        // renomeia o arquivo principal
        $mv = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
            . ' rclone moveto ' . escapeshellarg($cfg['remote'].$old) . ' ' . escapeshellarg($cfg['remote'].$new) . ' --fast-list 2>&1';
        $out = shell_exec($mv);

        // checa sucesso
        $chkOld = shell_exec(
            '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
            ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
            ' --include '.escapeshellarg($old).' --fast-list'
        );

// renomeia .json (se existir): .tar.gz -> .json
$oldJson = ptsb_tar_to_json($old);
$newJson = ptsb_tar_to_json($new);

$hasJson = shell_exec(
    '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
  . ' rclone lsf ' . escapeshellarg($cfg['remote'])
  . ' --files-only --format "p" --include ' . escapeshellarg($oldJson) . ' --fast-list'
);

if (trim((string)$hasJson) !== '') {
    $mvj = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . ' rclone moveto ' . escapeshellarg($cfg['remote'].$oldJson) . ' ' . escapeshellarg($cfg['remote'].$newJson) . ' --fast-list';
    shell_exec($mvj);
}


        
        $chkNew = shell_exec(
            '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
            ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
            ' --include '.escapeshellarg($new).' --fast-list'
        );

        if (trim((string)$chkOld) === '' && trim((string)$chkNew) !== '') {
            // renomeia .keep (se existir)
            $oldKeep = $old.'.keep';
            $newKeep = $new.'.keep';
            $hasKeep = shell_exec(
                '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '.
                ' rclone lsf '.escapeshellarg($cfg['remote']).' --files-only --format "p" '.
                ' --include '.escapeshellarg($oldKeep).' --fast-list'
            );
            if (trim((string)$hasKeep) !== '') {
                $mvk = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                     . ' rclone moveto ' . escapeshellarg($cfg['remote'].$oldKeep).' '.escapeshellarg($cfg['remote'].$newKeep).' --fast-list';
                shell_exec($mvk);
            }

            ptsb_log('Arquivo renomeado via painel: '.$old.' ? '.$new);
            add_settings_error('ptsb','rn_ok','Arquivo renomeado para: '.$new, 'updated');
        } else {
            add_settings_error('ptsb','rn_fail','Falha ao renomear o arquivo. '.(is_string($out)?htmlspecialchars($out):''), 'error');
        }

        ptsb_back();
    }

    /* ===== Toggle Sempre manter ===== */
    if ($act === 'keep_toggle' && !empty($_POST['file'])) {
        $file = sanitize_text_field($_POST['file']);
        $keep = isset($_POST['keep']) && $_POST['keep'] === '1';

        if ($keep) {
            $touch = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                   . ' rclone touch ' . escapeshellarg($cfg['remote'].$file.'.keep') . ' --no-create-dirs';
            $rcat  = 'printf "" | /usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                   . ' rclone rcat ' . escapeshellarg($cfg['remote'].$file.'.keep');
            shell_exec($touch . ' || ' . $rcat);
            add_settings_error('ptsb', 'keep_on', 'Marcado como "Sempre manter".', 'updated');
        } else {
            $cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                 . ' rclone deletefile ' . escapeshellarg($cfg['remote'].$file.'.keep') . ' --fast-list';
            shell_exec($cmd);
            add_settings_error('ptsb', 'keep_off', 'Marca "Sempre manter" removida.', 'updated');
        }
        ptsb_back();
    }

    /* ===== Restaurar / Apagar ===== */
    if (($act === 'restore' || $act === 'delete') && !empty($_POST['file'])) {
        $file = sanitize_text_field($_POST['file']);

        if ($act === 'restore') {
            $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
                 . 'REMOTE=' . escapeshellarg($cfg['remote']) . ' '
                 . 'FILE='   . escapeshellarg($file)        . ' '
                 . 'WP_PATH='. escapeshellarg(ABSPATH);
            $cmd = '/usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_restore'])
                 . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';
            shell_exec($cmd);
            add_settings_error('ptsb', 'rs_started', 'Restaura&ccedil;&atilde;o iniciada para: '.$file.'.', 'updated');

     } else {
    $chk = shell_exec(
        '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
      . ' rclone lsf ' . escapeshellarg($cfg['remote'])
      . ' --files-only --format "p" --include ' . escapeshellarg($file.'.keep') . ' --fast-list'
    );
    if (trim((string)$chk) !== '') {
        add_settings_error('ptsb', 'del_block', 'Este arquivo está marcado como "Sempre manter". Remova a marca antes de apagar.', 'error');
        ptsb_back();
    }

// apaga o .tar.gz
$cmd = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
     . ' rclone deletefile ' . escapeshellarg($cfg['remote'].$file) . ' --fast-list';
shell_exec($cmd);

// apaga o sidecar JSON correto: foo.tar.gz -> foo.json
$jsonPath = ptsb_tar_to_json($file);
$cmd_json = '/usr/bin/env PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
          . ' rclone deletefile ' . escapeshellarg($cfg['remote'].$jsonPath) . ' --fast-list';
shell_exec($cmd_json);

delete_transient('ptsb_m_' . md5($file));

  

add_settings_error('ptsb', 'del_done', 'Arquivo (e JSON) removidos do Drive: '.$file, 'updated');

}

        ptsb_back();
    }

    ptsb_back();
});

/* redireciona de volta à página */
function ptsb_back() {
    $tab = 'backup';
    $args = ['page'=>'pt-simple-backup', '_ts'=>time()];
    $ref = wp_get_referer();
    if ($ref) {
        $q = [];
        parse_str(parse_url($ref, PHP_URL_QUERY) ?? '', $q);
        if (!empty($q['tab']) && in_array($q['tab'], ['backup','cycles','next','last','settings'], true)) {
            $tab = $q['tab'];
        }
        if (!empty($q['per']))   { $args['per']   = max(1, (int)$q['per']); }
        if (!empty($q['paged'])) { $args['paged'] = max(1, (int)$q['paged']); }

        // >>> NOVO: mantém filtros/pg da aba "next"
if (!empty($q['per_next']))   { $args['per_next']   = max(1, (int)$q['per_next']); }
if (!empty($q['page_next']))  { $args['page_next']  = max(1, (int)$q['page_next']); }
if (!empty($q['next_date']))  { $args['next_date']  = preg_replace('/[^0-9\-]/','', (string)$q['next_date']); }

// >>> NOVO: mantém filtros/pg da aba "last"
if (!empty($q['per_last']))   { $args['per_last']   = max(1, (int)$q['per_last']); }
if (!empty($q['page_last']))  { $args['page_last']  = max(1, (int)$q['page_last']); }

// >>> NOVO: mantém filtros de vencidos
if (isset($q['last_exp'])) { $args['last_exp'] = (int)!!$q['last_exp']; }
if (isset($q['last_ok']))  { $args['last_ok']  = (int)!!$q['last_ok']; }


    }
    $args['tab'] = $tab;
    wp_safe_redirect( add_query_arg($args, admin_url('tools.php')) );
    exit;
}





/* ===================== FIM DA PARTE 1 ===================== */
// A PARTE 2 trará: UI da página (chips P,T,W,S,M,O acima do botão),
// tabela com coluna “Backup” (letras acesas), AJAX de status e barra de progresso,
// e o CSS/JS necessário para tudo funcionar.


/*PARTE 2*/
/** Meta de ícones por letra (Dashicons) */
function ptsb_letter_meta($L){
    switch (strtoupper(trim((string)$L))) {
        case 'D': return ['class'=>'dashicons-database',         'label'=>'Banco'];
        case 'P': return ['class'=>'dashicons-admin-plugins',    'label'=>'Plugins'];
        case 'T': return ['class'=>'dashicons-admin-appearance', 'label'=>'Temas'];
        case 'W': return ['class'=>'dashicons-wordpress-alt',    'label'=>'Core'];
        case 'S': return ['class'=>'dashicons-editor-code',      'label'=>'Scripts'];
        case 'M': return ['class'=>'dashicons-admin-media',      'label'=>'Mídia'];
        case 'O': return ['class'=>'dashicons-image-filter',     'label'=>'Outros'];
        default : return ['class'=>'dashicons-marker',           'label'=>strtoupper($L)];
    }
}

/* -------------------------------------------------------
 * Helpers específicos da PARTE 2
 * -----------------------------------------------------*/
 function ptsb_mode_label_br($mode){
    $m = strtolower((string)$mode);
    return $m === 'weekly'   ? 'semanal'
         : ($m === 'every_n' ? 'a cada N dias'
         : ($m === 'interval'? 'intervalo'
         : 'diário'));
}

function ptsb_guess_cycle_mode_from_filename(string $file): ?string {
    $cycles = ptsb_cycles_get(); if (!$cycles) return null;
    $cfg = ptsb_cfg(); $bestLen = 0; $bestMode = null;
    foreach ($cycles as $c) {
        $slug = ptsb_slug_prefix((string)($c['name'] ?? '')); if ($slug === '') continue;
        foreach ([$cfg['prefix'].$slug, $slug] as $cand) {
            if ($cand !== '' && strpos($file, $cand) === 0) {
                $L = strlen($cand);
                if ($L > $bestLen) { $bestLen = $L; $bestMode = (string)($c['mode'] ?? 'daily'); }
            }
        }
    }
    return $bestMode;
}

function ptsb_run_kind_label(array $manifest, string $file): string {
    $origin = strtolower((string)($manifest['origin'] ?? ''));
    $mmode  = strtolower((string)($manifest['routine_mode'] ?? ($manifest['mode'] ?? $manifest['auto_mode'] ?? '')));

    if ($origin === 'manual') return 'manual';
    if (in_array($mmode, ['daily','weekly','every_n','interval'], true)) {
        return ptsb_mode_label_br($mmode);
    }
    $gm = ptsb_guess_cycle_mode_from_filename($file);
    if ($gm) return ptsb_mode_label_br($gm);
    return 'manual';
}

 
 
/* ===== Retenção: helpers ===== */

/** Pega keep_days a partir do manifest (se existir); 0 = sempre manter (sentinela). Fallback: $default */
function ptsb_manifest_keep_days(array $man, ?int $default=null): ?int {
    if (isset($man['keep_days'])) {
        $d = (int)$man['keep_days'];
        if ($d >= 0) return $d;
    }
    // compat: alguns scripts podem ter salvo "keep"
    if (isset($man['keep'])) {
        $d = (int)$man['keep'];
        if ($d >= 0) return $d;
    }
    return $default;
}

/** Calcula progresso X/Y (X = dia “corrente”, inclusivo; Y = total). Retorna ['x'=>int,'y'=>int,'pct'=>int] */
function ptsb_retention_calc(string $iso, int $keepDays): array {
    try { $created = new DateTimeImmutable($iso); }
    catch (Throwable $e) { $created = ptsb_now_brt(); }
    $now      = ptsb_now_brt();
    $diffSec  = max(0, $now->getTimestamp() - $created->getTimestamp());
    $elapsed  = (int) floor($diffSec / 86400);
    $x        = min($keepDays, $elapsed + 1); // inclusivo: no dia do backup = 1
    $pct      = (int) round(($x / max(1,$keepDays)) * 100);
    return ['x'=>$x,'y'=>$keepDays,'pct'=>$pct];
}

/** Converte CSV de parts -> letras (DB não aparece nos chips) */
function ptsb_parts_to_letters($partsStr): array {
    // Agora DB é opcional (D) e "Outros" agrega langs+config
    $letters = [];
    foreach (array_filter(array_map('trim', explode(',', strtolower((string)$partsStr)))) as $p) {
        if ($p === 'db')        $letters['D'] = true;
        if ($p === 'plugins')   $letters['P'] = true;
        if ($p === 'themes')    $letters['T'] = true;
        if ($p === 'core')      $letters['W'] = true;
        if ($p === 'scripts')   $letters['S'] = true;
        if ($p === 'uploads')   $letters['M'] = true;
        if (in_array($p, ['others','langs','config'], true)) $letters['O'] = true;
    }
    return array_keys($letters);
}

/** Monta CSV de parts com base nas letras (DB, langs, config sempre inclusos) */
function ptsb_letters_to_parts_csv(array $letters): string {
    // Nada "sempre incluso": DB virou chip; langs+config agora vão junto de "Outros"
    $parts = [];
    foreach ($letters as $L) {
        switch (strtoupper(trim($L))) {
            case 'D': $parts[] = 'db'; break;
            case 'P': $parts[] = 'plugins'; break;
            case 'T': $parts[] = 'themes'; break;
            case 'W': $parts[] = 'core'; break;
            case 'S': $parts[] = 'scripts'; break;
            case 'M': $parts[] = 'uploads'; break;
            case 'O': $parts[] = 'others'; $parts[] = 'langs'; $parts[] = 'config'; break;
        }
    }
    return implode(',', array_values(array_unique($parts)));
}

/** Inicia backup com PARTS customizadas (bypass do ptsb_start_backup padrão) */
function ptsb_start_backup_with_parts(string $partsCsv): void {
    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    if (!ptsb_can_shell()) return;
    if (file_exists($cfg['lock'])) return;

    $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . 'REMOTE='     . escapeshellarg($cfg['remote'])     . ' '
         . 'WP_PATH='    . escapeshellarg(ABSPATH)            . ' '
         . 'PREFIX='     . escapeshellarg($cfg['prefix'])     . ' '
         . 'KEEP_DAYS='  . escapeshellarg($set['keep_days'])  . ' '
         . 'KEEP='       . escapeshellarg($set['keep_days']) . ' '
         . 'PARTS='      . escapeshellarg($partsCsv);

    $cmd = '/usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_backup'])
         . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';
    shell_exec($cmd);
}

/* -------------------------------------------------------
 * AJAX status (progresso + tail)
 * -----------------------------------------------------*/
function ptsb_tail_log_raw($path, $n = 50) {
    if (!@file_exists($path)) return "Log nao encontrado em: $path";
    if (ptsb_can_shell()) {
        $txt = shell_exec('tail -n '.intval($n).' '.escapeshellarg($path));
        if ($txt !== null && $txt !== false && $txt !== '') return ptsb_to_utf8((string)$txt);
    }
    $f = @fopen($path, 'rb');
    if (!$f) return "Sem acesso de leitura ao log: $path";
    $lines = []; $buffer = '';
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
    $lines = array_slice($lines, -$n);
    return ptsb_to_utf8(implode("\n", $lines));
}

add_action('wp_ajax_ptsb_status', function () {
    // no-cache também no AJAX
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
    if (!wp_verify_nonce($nonce, 'ptsb_nonce')) wp_send_json_error('bad nonce', 403);

    $cfg  = ptsb_cfg();
    $tail = ptsb_tail_log_raw($cfg['log'], 50);

    $percent = 0; $stage = 'idle';
    if ($tail) {
        $lines = explode("\n", $tail);
        $start_ix = 0;
        for ($i=count($lines)-1; $i>=0; $i--) {
            if (strpos($lines[$i], '=== Start WP backup') !== false) { $start_ix = $i; break; }
        }
        $section = implode("\n", array_slice($lines, $start_ix));
        $map = [
            'Dumping DB'                          => 15, // compat novo
            'Dumping database'                    => 15, // compat antigo
            'Archiving selected parts'            => 35,
            'Creating final bundle'               => 55,
            'Uploading to'                        => 75,
            'Uploaded and removing local bundle'  => 85,
            'Applying retention'                  => 95,
            'Backup finished successfully.'       => 100,
            'Backup finalizado com sucesso.'      => 100,
        ];
        foreach ($map as $k=>$p) {
            if (strpos($section, $k) !== false) { $percent = max($percent, $p); $stage = $k; }
        }
    }
    $running = file_exists($cfg['lock']) && $percent < 100;

    wp_send_json_success([
        'running' => (bool)$running,
        'percent' => (int)$percent,
        'stage'   => (string)$stage,
        'log'     => (string)$tail,
    ]);
});

/* ===================== Ações das Rotinas ===================== */
add_action('admin_post_ptsb_cycles', function(){
    if (!current_user_can('manage_options')) wp_die('forbidden');
    check_admin_referer('ptsb_nonce');

    $act = isset($_POST['do']) ? sanitize_text_field($_POST['do']) : '';
    $cycles = ptsb_cycles_get();

      // Anotamos o timestamp da próxima execução de cada rotina (ou "infinito" se não houver)
  $cycles_sorted = [];
  foreach ($cycles as $c) {
      $next = ptsb_cycles_next_occurrences([$c], 1);
      // ts = timestamp da próxima execução; se não houver, manda pro final
      $ts = $next ? $next[0]['dt']->getTimestamp() : PHP_INT_MAX;

      // Se quiser empurrar rotinas desativadas ainda mais para o fim:
      // if (empty($c['enabled'])) { $ts = PHP_INT_MAX - 1; }

      $cycles_sorted[] = ['ts' => $ts, 'cycle' => $c];
  }

  // Ordena crescente pelo timestamp
  usort($cycles_sorted, function($a, $b){
      if ($a['ts'] === $b['ts']) {
          // critério secundário opcional: nome
          return strcasecmp($a['cycle']['name'] ?? '', $b['cycle']['name'] ?? '');
      }
      return $a['ts'] <=> $b['ts'];
  });

  // Substitui $cycles pela versão ordenada
  $cycles = array_map(fn($it) => $it['cycle'], $cycles_sorted);

    if ($act === 'save_global') {
        $valid = [12,24,36];
        $n = (int)($_POST['preview_count'] ?? 12);
        if (!in_array($n, $valid, true)) { $n = 12; }
        update_option('ptsb_preview_count', $n, true);

        // mantém globais fixos por código (merge=false, policy=queue, min-gap=10)
        add_settings_error('ptsb','cg_ok','Preferências salvas.', 'updated');
        ptsb_back();
    }
    
    if ($act === 'skip_toggle') {
    // recebe time como 'YYYY-mm-dd HH:ii' (no seu fuso configurado)
    $time = sanitize_text_field($_POST['time'] ?? '');
    $on   = !empty($_POST['skip']);

    // normaliza a chave
    try {
        $dt  = new DateTimeImmutable($time.':00', ptsb_tz());
        $key = ptsb_skip_key($dt);
    } catch (Throwable $e) {
        $key = preg_replace('/[^0-9:\-\s]/', '', $time);
    }

    $map = ptsb_skipmap_get();
    if ($on)  { $map[$key] = true; $msg = 'Execução marcada para ser ignorada: '.$key; }
    else      { unset($map[$key]);  $msg = 'Execução recolocada: '.$key; }
    ptsb_skipmap_save($map);

    add_settings_error('ptsb','skip_ok', $msg, 'updated');
    ptsb_back();
}


    if ($act === 'toggle') {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($cycles as &$c) {
            if ($c['id'] === $id) {
                $c['enabled'] = !empty($_POST['enabled']);
                $c['updated_at'] = gmdate('c');
                break;
            }
        }
        ptsb_cycles_save($cycles);
        add_settings_error('ptsb','cx_onoff','Rotina atualizada.','updated');
        ptsb_back();
    }

  if ($act === 'delete') {
    $id = sanitize_text_field($_POST['id'] ?? '');
    $cycles = array_values(array_filter($cycles, fn($c)=>$c['id'] !== $id));
    ptsb_cycles_save($cycles);
    update_option('ptsb_cycles_legacy_migrated', 1, true); // garante que não remigre
    add_settings_error('ptsb','cx_del','Rotina removida.','updated');
    ptsb_back();
}


    if ($act === 'dup') {
        $id = sanitize_text_field($_POST['id'] ?? '');
        foreach ($cycles as $c) if ($c['id']===$id) {
            $copy = $c;
            $copy['id'] = ptsb_uuid4();
            $copy['name'] = ($c['name'] ?? 'Rotina').' (cópia)';
            $copy['enabled'] = false;
            $copy['created_at']=gmdate('c'); $copy['updated_at']=gmdate('c');
            $cycles[] = $copy;
            ptsb_cycles_save($cycles);
            add_settings_error('ptsb','cx_dup','Rotina duplicada (desativada).','updated');
            break;
        }
        ptsb_back();
    }

    if ($act === 'save_one') {
        $id   = sanitize_text_field($_POST['id'] ?? '');
        $is_new = ($id === '');
        if ($is_new) $id = ptsb_uuid4();

        $name = sanitize_text_field($_POST['name'] ?? '');
if ($name === '') {
    add_settings_error('ptsb','cx_name_req','Informe um <strong>nome</strong> para a rotina.','error');
    ptsb_back();
}

        $enabled = !empty($_POST['enabled']);
        $mode = sanitize_text_field($_POST['mode'] ?? 'daily');
        if (!in_array($mode, ['daily','weekly','every_n','interval'], true)) $mode = 'daily';

        // letters (chips)
        $letters = array_values(array_unique(array_map('strtoupper', (array)($_POST['letters'] ?? []))));
        $letters = array_values(array_intersect($letters, ['D','P','T','W','S','M','O']));
        if (!$letters) $letters = ['D','P','T','W','S','M','O'];

        // policy: sempre enfileirar
        $policy = 'queue';

      // retenção por rotina: 0 = sempre manter (sentinela)
if (!empty($_POST['keep_forever'])) {
    $keep_days = 0;
} else {
    $keep_days = isset($_POST['keep_days']) ? max(1, min((int)$_POST['keep_days'], 3650)) : null;
}


        // cfg por modo
     $cfg = [];
$fail_and_back = function($code,$msg){
    add_settings_error('ptsb', $code, $msg, 'error');
    ptsb_back();
};

$normalize_times = function($arr){
    // remove vazios, normaliza e ordena
    $arr = array_map('strval', (array)$arr);
    $arr = array_values(array_filter($arr, fn($s)=>trim($s) !== ''));
    return ptsb_times_sort_unique($arr);
};

if ($mode === 'daily') {
    $times = $normalize_times($_POST['times'] ?? []);
    if (!$times) $fail_and_back('cx_time_req','Informe pelo menos <strong>1 horário</strong> (HH:MM).');
    $qty   = max(1, min(12, (int)($_POST['qty'] ?? count($times))));
    if (count($times) !== $qty) {
        $fail_and_back('cx_time_qty','Preencha todos os <strong>'.$qty.'</strong> horários.');
    }
    $cfg   = ['times'=>$times, 'qty'=>$qty];

} elseif ($mode === 'weekly') {
    $days = array_map('intval', (array)($_POST['wk_days'] ?? []));
    $days = array_values(array_unique(array_filter($days, fn($d)=>$d>=0 && $d<=6)));
    if (!$days) $fail_and_back('cx_wk_days','Selecione pelo menos <strong>1 dia da semana</strong>.');

    $times = $normalize_times($_POST['times'] ?? []);
    if (!$times) $fail_and_back('cx_wk_time','Informe pelo menos <strong>1 horário</strong> (HH:MM).');
    $qty   = max(1, min(12, (int)($_POST['qty'] ?? count($times))));
    if (count($times) !== $qty) {
        $fail_and_back('cx_wk_qty','Preencha todos os <strong>'.$qty.'</strong> horários.');
    }
    $cfg   = ['days'=>$days, 'times'=>$times, 'qty'=>$qty];

} elseif ($mode === 'every_n') {
    $n     = max(1, min(30, (int)($_POST['n'] ?? 1)));
    $start = sanitize_text_field($_POST['start'] ?? ptsb_now_brt()->format('Y-m-d'));

    $times = $normalize_times($_POST['times'] ?? []);
    if (!$times) $fail_and_back('cx_en_time','Informe pelo menos <strong>1 horário</strong> (HH:MM).');
    $qty   = max(1, min(12, (int)($_POST['qty'] ?? count($times))));
    if (count($times) !== $qty) {
        $fail_and_back('cx_en_qty','Preencha todos os <strong>'.$qty.'</strong> horários.');
    }
    $cfg   = ['n'=>$n, 'start'=>$start, 'times'=>$times, 'qty'=>$qty];

} else { // interval
    // (sem alteração aqui)
    $val  = max(1, (int)($_POST['every_val'] ?? 60));
    $unit = strtolower(sanitize_text_field($_POST['every_unit'] ?? 'minute'));
    if (!in_array($unit, ['minute','hour','day'], true)) $unit = 'minute';
    $ws   = trim((string)($_POST['win_start'] ?? '00:00'));
    $we   = trim((string)($_POST['win_end']   ?? '23:59'));
    $wdis = !empty($_POST['win_disable']) ? 1 : 0;

    $cfg = ['every'=>['value'=>$val,'unit'=>$unit],'win'=>['start'=>$ws,'end'=>$we,'disabled'=>$wdis]];
}


        // grava
        $found=false;
        foreach ($cycles as &$c) {
            if ($c['id'] === $id) {
                $c['enabled']=$enabled; $c['name']=$name; $c['mode']=$mode; $c['cfg']=$cfg; $c['letters']=$letters; $c['policy']=$policy; $c['keep_days']=$keep_days;
                $c['updated_at']=gmdate('c');
                $found=true; break;
            }
        }
        if (!$found) {
            $cycles[] = [
                'id'=>$id,'enabled'=>$enabled,'name'=>$name,'mode'=>$mode,'cfg'=>$cfg,'letters'=>$letters,'policy'=>$policy,'keep_days'=>$keep_days,
                'priority'=>0,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c')
            ];
        }
        ptsb_cycles_save($cycles);
        add_settings_error('ptsb','cx_saved','Rotina salva.','updated');
        ptsb_back();
    }

    ptsb_back();
});

/* -------------------------------------------------------
 * UI
 * -----------------------------------------------------*/
/* Gera o texto de parâmetros para exibir na tabela das rotinas */
function ptsb_cycle_params_label_ui(array $cycle): string {
    $mode = (string)($cycle['mode'] ?? 'daily');
    $cfg  = is_array($cycle['cfg'] ?? null) ? $cycle['cfg'] : [];

    // Junta SEMPRE: times[] + time (legado) e normaliza
    $times = [];
    if (!empty($cfg['times']) && is_array($cfg['times'])) $times = array_map('strval', $cfg['times']);
    if (!empty($cfg['time'])) $times[] = (string)$cfg['time'];
    $times = array_values(array_filter($times, fn($s)=>trim($s) !== ''));

    if (function_exists('ptsb_times_sort_unique')) {
        $times = ptsb_times_sort_unique($times);
    }

    if ($mode === 'daily') {
        return $times ? implode(', ', $times) : '—';
    }

    if ($mode === 'weekly') {
        $labels = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];
        $days   = array_map('intval', $cfg['days'] ?? []);
        $days   = array_values(array_filter($days, fn($d)=>$d>=0 && $d<=6));
        $dias   = $days ? implode(', ', array_map(fn($d)=>$labels[$d], $days)) : '—';
        $horas  = $times ? implode(', ', $times) : '—';
        return "{$dias} · {$horas}";
    }

    if ($mode === 'every_n') {
        // >>> NOVO: só horários (sem "N=" e sem "início=")
        $horas = $times ? implode(', ', $times) : '—';
        return $horas;
    }

  if ($mode === 'interval') {
    $u    = strtolower($cfg['every']['unit'] ?? 'minute');
    $u_br = ($u === 'day' ? 'dia(s)' : ($u === 'hour' ? 'hora(s)' : 'minuto(s)'));
    $val  = (int)($cfg['every']['value'] ?? 1);

    $win_on = empty($cfg['win']['disabled']);
    $winTxt = $win_on ? (($cfg['win']['start'] ?? '00:00').'–'.($cfg['win']['end'] ?? '23:59')) : '';

    $base = "cada {$val} {$u_br}";
    return $base . ($win_on ? ' · ' . $winTxt : '');
}


    return '—';
}




function ptsb_render_backup_page() {
    if (!current_user_can('manage_options')) return;

    $cfg     = ptsb_cfg();
    $set     = ptsb_settings();
    $rows    = ptsb_list_remote_files();
    $keepers = ptsb_keep_map();
    $auto    = ptsb_auto_get();

    // === Abas (roteamento) ===
$tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'backup';
if (!in_array($tab, ['backup','cycles','next','last','settings'], true)) $tab = 'backup';


    $h1 = [
    'backup'    => 'Backups (Google Drive)',
    'cycles'    => 'Rotinas de Backup',
    'next'      => 'Próximas Execuções',
    'last'      => 'Últimas Execuções',
    'settings'  => 'Configurações',
][$tab];

    // Diagnóstico
    $diag = [];
    $diag[] = 'shell_exec '.(ptsb_can_shell() ? 'OK' : 'DESABILITADO');
    $diag[] = 'log '.(ptsb_is_readable($cfg['log']) ? 'legivel' : 'sem leitura');
    $diag[] = 'backup.sh '.(@is_executable($cfg['script_backup']) ? 'executavel' : 'sem permissao');
    $diag[] = 'restore.sh '.(@is_executable($cfg['script_restore']) ? 'executavel' : 'sem permissao');

    $nonce = wp_create_nonce('ptsb_nonce');

    // Drive (resumo)
  $drive = ptsb_drive_info();

// se vier ?force=1, zera o cache dos totais
if (isset($_GET['force']) && (int)$_GET['force'] === 1) {
    delete_transient('ptsb_totals_v1');
}
$tot    = ptsb_backups_totals_cached();
$bk_count       = (int)$tot['count'];
$backups_total  = (int)$tot['bytes'];

$usedStr  = ($drive['used']  !== null) ? ptsb_hsize_compact($drive['used'])  : '?';
$totalStr = ($drive['total'] !== null) ? ptsb_hsize_compact($drive['total']) : '?';
$bkStr    = number_format_i18n($bk_count) . ' ' . ($bk_count === 1 ? 'item' : 'itens') . ' / ' . ptsb_hsize_compact($backups_total);


    // Navegação das abas
    $base = admin_url('tools.php?page=pt-simple-backup');
   $tabs = [
    'backup'    => 'Backup',
    'cycles'    => 'Rotinas de Backup',
    'next'      => 'Próximas Execuções',
    'last'      => 'Últimas Execuções',
    'settings'  => 'Configurações',
];

    // CSS leve compartilhado (chips + “pílulas”)
    ?>
    <div class="wrap">
      <h1><?php echo esc_html($h1); ?></h1>
      <p style="opacity:.7;margin:.3em 0 1em">
        Armazenamento: <strong><?php echo esc_html($usedStr.' / '.$totalStr); ?></strong> |
        Backups no Drive: <strong><?php echo esc_html($bkStr); ?></strong>
      </p>

      <h2 class="nav-tab-wrapper" style="margin-top:8px">
        <?php foreach ($tabs as $slug => $label):
          $url = esc_url( add_query_arg('tab', $slug, $base) );
          $cls = 'nav-tab' . ($tab === $slug ? ' nav-tab-active' : '');
        ?>
          <a class="<?php echo $cls; ?>" href="<?php echo $url; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
      </h2>

      <style>        
/* Label + input em linha */
.ptsb-inline-field{
  display:inline-flex; align-items:center; gap:8px; margin:6px 0 !important;
}

/* “Respiro” acima da grade + grade de horários (4 por linha) */
.ptsb-times-grid{
  display:grid;
  grid-template-columns: repeat(4, minmax(120px,1fr));
  gap:6px;
  margin-top:6px !important;
}
.ptsb-times-grid input[type="time"]{ width:100%; }

/* Espaçamento de seção */
.ptsb-section-gap{ display:inline-block; margin-top:12px; }

/* ===== Paginação ===== */
.ptsb-pager{
  display:flex; align-items:center; gap:8px;
  margin:14px 0 8px;
  justify-content:flex-end;
  margin-left:0;
}
.ptsb-pager .btn{
  display:inline-flex; align-items:center; justify-content:center;
  width:36px; height:36px;
  border:1px solid #555; border-radius:10px;
  background:#1e1e1e; text-decoration:none;
  transition:all .15s ease;
}
.ptsb-pager .btn .dashicons{ font-size:18px; line-height:1; }
.ptsb-pager .btn:hover{ border-color:#2271b1; box-shadow:0 0 0 2px rgba(34,113,177,.15) inset; }
.ptsb-pager .btn.is-disabled{ opacity:.45; pointer-events:none; filter:grayscale(1); }
.ptsb-pager .status{
  display:inline-flex; align-items:center; gap:6px;
  padding:0 6px; font-size:13px; opacity:.9;
}
.ptsb-pager .status .current{
  width:72px; text-align:center; height:32px; border-radius:8px;
}
.ptsb-pager .status .sep{ opacity:.6 }

/* Controle “Exibindo N de M” */
.ptsb-list-controls{
 background:#111; border:1px solid #333; border-radius:10px;
  padding:8px 12px;
  display:inline-flex; align-items:center; gap:10px;
  min-height:36px;      /* <- mesma altura para os 2 blocos */;
}

.ptsb-toolbar{
  display:inline-flex; align-items:center; gap:12px; flex-wrap:wrap;
  margin:8px 0 10px;
}
#ptsb-per-form input[name="per"]{
  height:32px; border-radius:8px; text-align:center;
}

/* Botão renomear (ícone apenas) */
.ptsb-rename-btn{ background:none !important; border:none !important; }

/* Chips de seleção */
.ptsb-chips{ display:flex; flex-wrap:wrap; gap:6px; margin-bottom:24px !important; }
.ptsb-chip{
  display:inline-flex; align-items:center; gap:6px;
  padding:6px 10px; border:1px solid #666; border-radius:999px;
  cursor:pointer; user-select:none;
}
.ptsb-chip .dashicons{ font-size:16px; line-height:1; vertical-align:-2px; }
/* Pinta quando ativo (via classe) */
.ptsb-chip.active{ background:#2271b1; color:#fff; border-color:#2271b1; }
/* Compat: pinta quando o <input> interno está marcado */
.ptsb-chip:has(input:checked){ background:#2271b1; color:#fff; border-color:#2271b1; }

/* Coluna “Backup”: impedir quebra */
td.ptsb-backup-cell{ white-space:nowrap; }

/* Mini chips (ícones) */
.ptsb-mini{
  display:inline-flex; align-items:center; justify-content:center;
  width:18px; height:18px; margin:0 2px; padding:0;
  border:0; border-radius:0;
}
.ptsb-mini .dashicons{ font-size:16px; line-height:1; }

/* Retenção (badge) */
.ptsb-ret{
  display:inline-block; min-width:44px; text-align:center;
  border:1px solid #666; border-radius:999px; padding:2px 8px;
}
.ptsb-ret.sempre{ background:#2271b1; color:#fff; border-color:#2271b1; }

/* Linha marcada como vencida (expirada pela retenção — não mantida) */
tr.ptsb-expired { opacity:.6; }
tr.ptsb-expired td { color:#bfbfbf; }

/* Selo “vencido” (badge pequeno) */
.ptsb-tag{
  display:inline-block; padding:2px 6px; border-radius:999px;
  border:1px solid #555; font-size:11px; line-height:1; margin-left:6px;
}
.ptsb-tag.vencido{
  background:#3b3b3b; border-color:#666;
  text-transform:uppercase; letter-spacing:.2px;
}

/* Toggle switch */
.ptsb-switch{ position:relative; display:inline-block; width:46px; height:26px; vertical-align:middle; }
.ptsb-switch input{ display:none; }
.ptsb-slider{
  position:absolute; cursor:pointer; top:0; left:0; right:0; bottom:0;
  background:#888; transition:.2s; border-radius:999px;
}
.ptsb-slider:before{
  position:absolute; content:""; height:20px; width:20px;
  left:3px; bottom:3px; background:#fff; transition:.2s; border-radius:50%;
}
.ptsb-switch input:checked + .ptsb-slider{ background:#2271b1; }
.ptsb-switch input:checked + .ptsb-slider:before{ transform:translateX(20px); }

/* Retenção: badge + toggle + texto em uma linha */
.ptsb-keep-wrap{
  display:inline-flex; align-items:center; gap:10px; white-space:nowrap;
}
.ptsb-keep-form{ display:inline; margin:0; }
.ptsb-keep-toggle{
  display:inline-flex; align-items:center; gap:8px; vertical-align:middle;
}
.ptsb-keep-toggle .ptsb-keep-txt{ display:inline-block; color:#aaa !important; opacity:.8 !important; }

/* Espaço abaixo das tabs */
.wrap .nav-tab-wrapper{ margin-bottom:36px !important; }

/* ===== Ações: botões na MESMA linha ===== */
td.ptsb-actions{ white-space:nowrap; }
td.ptsb-actions details{
  display:inline-block; vertical-align:middle; margin-right:6px;
}
/* remove o marcador padrão do <summary> */
td.ptsb-actions details > summary::-webkit-details-marker{ display:none; }
td.ptsb-actions details > summary{ list-style:none; }
/* forms como botões inline */
td.ptsb-actions form{
  display:inline-block !important; vertical-align:middle; margin-left:6px;
}
/* alinhamento vertical dos botões do WP */
td.ptsb-actions .button{ vertical-align:middle; }



      </style>

      <?php if ($tab === 'backup'): ?>

        <!-- ===== ABA: BACKUP ===== -->

        <h2 style="margin-top:24px !important">Fazer Backup</h2>

 <p class="description">
           Escolha quais partes do site incluir no backup. Para um backup completo, mantenha todos selecionados.
          </p>

        <!-- Disparar manual -->
        <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="ptsb-now-form" style="margin:12px 0;">
          <?php wp_nonce_field('ptsb_nonce'); ?>
          <input type="hidden" name="action" value="ptsb_do"/>
          <input type="hidden" name="ptsb_action" value="backup_now"/>
          <input type="hidden" name="parts_sel[]" value="" id="ptsb-parts-hidden-sentinel" />

       <div class="ptsb-chips" id="ptsb-chips">

  <label class="ptsb-chip" data-letter="D">
    <input type="checkbox" checked data-letter="D">
    <span class="dashicons dashicons-database"></span> Banco de Dados
  </label>
  <label class="ptsb-chip" data-letter="P">
    <input type="checkbox" checked data-letter="P">
    <span class="dashicons dashicons-admin-plugins"></span> Plugins
  </label>
  <label class="ptsb-chip" data-letter="T">
    <input type="checkbox" checked data-letter="T">
    <span class="dashicons dashicons-admin-appearance"></span> Temas
  </label>
  <label class="ptsb-chip" data-letter="W">
    <input type="checkbox" checked data-letter="W">
    <span class="dashicons dashicons-wordpress-alt"></span> Core
  </label>
  <label class="ptsb-chip" data-letter="S">
    <input type="checkbox" checked data-letter="S">
    <span class="dashicons dashicons-editor-code"></span> Scripts
  </label>
  <label class="ptsb-chip" data-letter="M">
    <input type="checkbox" checked data-letter="M">
    <span class="dashicons dashicons-admin-media"></span> Mídia
  </label>
  <label class="ptsb-chip" data-letter="O">
    <input type="checkbox" checked data-letter="O">
    <span class="dashicons dashicons-image-filter"></span> Outros
  </label>
</div>


       

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin:8px 0 2px">
            <label>Nome do backup:
              <input type="text" name="manual_name" placeholder="Opcional" style="min-width:280px">
            </label>
            <label>Armazenar por quantos dias?
              <input type="number" name="manual_keep_days" min="1" max="3650"
                     placeholder="Máx: 3650" required style="width:120px">
            </label>
            <div class="ptsb-keep-toggle" style="align-self:flex-end;margin-top:4px">
  <label class="ptsb-switch" title="Sempre manter">
    <input type="checkbox" id="ptsb-man-keep-forever" name="manual_keep_forever" value="1">
    <span class="ptsb-slider" aria-hidden="true"></span>
  </label>
  <span class="ptsb-keep-txt">Sempre manter</span>
</div>

          </div>

         <div class="ptsb-btns" style="margin-top: 18px;">
  <button class="button button-primary">Fazer backup agora</button>
  <a class="button" target="_blank" rel="noopener" href="<?php echo esc_url($cfg['drive_url']); ?>">Ver no Drive</a>
</div>

        </form>

        <!-- Barra de progresso -->
        <div id="ptsb-progress" style="display:none;margin:16px 0;border:1px solid #444;background:#1b1b1b;height:22px;position:relative;border-radius:4px;overflow:hidden;">
          <div id="ptsb-progress-bar" style="height:100%;width:5%;background:#2271b1;transition:width .4s ease"></div>
          <div id="ptsb-progress-text" style="position:absolute;left:8px;top:0;height:100%;line-height:22px;color:#fff;opacity:.9;font-size:12px;">Iniciando…</div>
        </div>

        <!-- Arquivos no Drive -->
       <!-- Arquivos no Drive -->
<h2 style="margin-top:24px !important">Arquivos no Google Drive  <a class="button button-small" style="margin-left:8px"
     href="<?php echo esc_url( add_query_arg(['force'=>1], $base) ); ?>">Forçar atualizar</a></h2>
<?php
  // ====== PAGINAÇÃO (lista do Drive) ======
  $total = count($rows);

  // valor padrão salvo (opcional) + query string
$per_default = (int) get_option('ptsb_list_per_page', 25);
$per = isset($_GET['per']) ? (int) $_GET['per'] : ($per_default > 0 ? $per_default : 25);

  $per = max(1, min($per, 500));              // limite de sanidade
  if (isset($_GET['per'])) update_option('ptsb_list_per_page', $per, false); // lembra preferência

  $paged = max(1, (int)($_GET['paged'] ?? 1));
  $total_pages = max(1, (int) ceil($total / $per));
  if ($paged > $total_pages) $paged = $total_pages;

  $offset    = ($paged - 1) * $per;
  $rows_page = array_slice($rows, $offset, $per);

  // URL base para os links de paginação
  $base_admin = admin_url('tools.php');
  $make_url = function($p) use ($base_admin, $per) {
      return esc_url( add_query_arg([
          'page'  => 'pt-simple-backup',
          'tab'   => 'backup',
          'per'   => $per,
          'paged' => (int) $p
      ], $base_admin) );
  };
?>

<!-- Filtro "Exibindo N de M" -->
 <!-- “Exibindo N de M” -->
  <form method="get" id="ptsb-last-per-form" class="ptsb-list-controls" style="margin:0">
    <input type="hidden" name="page" value="pt-simple-backup">
    <input type="hidden" name="tab"  value="last">
    <input type="hidden" name="page_last" value="1">
    <span>Exibindo</span>
    <input type="number" name="per_last" min="1" max="500" value="<?php echo (int)$per_last; ?>" style="width:auto">
    <span>de <?php echo (int)$total_last; ?> execuções — página <?php echo (int)$page_last; ?> de <?php echo (int)$total_pages_l; ?></span>
  </form>
</div>

<script>
(function(){
  var f=document.getElementById('ptsb-per-form'); if(!f) return;
  var i=f.querySelector('input[name="per"]');
  i.addEventListener('change', function(){ f.submit(); });
})();
</script>

<table class="widefat striped">

          <thead>
            <tr>
              <th>Data/Hora</th>
              <th>Arquivo</th>
              <th>Rotina</th>
              <th>Backup</th>
              <th>Tamanho</th>
              <th>Retenção</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
       <?php if ($total === 0): ?>
  <tr><td colspan="7"><em>Nenhum backup encontrado.</em></td></tr>

<?php else:
  foreach ($rows_page as $r):

    $time = $r['time']; $file = $r['file']; $size = (int)($r['size'] ?? 0);
    $is_kept = !empty($keepers[$file]);
    $manifest = ptsb_manifest_read($file);
    $letters = [];
    if (!empty($manifest['parts'])) $letters = ptsb_parts_to_letters($manifest['parts']);
    if (!$letters) $letters = ['D','P','T','W','S','M','O'];
    $keepDays  = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);
    $rotina_label = ptsb_run_kind_label($manifest, $file);

    // NOVO: calcular se está vencido (não-keep e X/Y >= Y/Y)
    $ri = null; $is_expired = false;
    if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
        $ri = ptsb_retention_calc($time, $keepDays);
        $is_expired = ($ri['x'] >= $ri['y']);
    }
    
?>

<?php
  $time = $r['time'];
  $file = $r['file'];
  $size = (int)($r['size'] ?? 0);
  $is_kept = !empty($keepers[$file]); // mantém só o mapa .keep (1 listagem rclone)
  $row_classes = [];
  if ($is_expired) $row_classes[] = 'ptsb-expired';
?>
<tr<?php echo $row_classes ? ' class="'.esc_attr(implode(' ', $row_classes)).'"' : ''; ?>
    data-file="<?php echo esc_attr($file); ?>"
    data-time="<?php echo esc_attr($time); ?>"
    data-kept="<?php echo $is_kept ? 1 : 0; ?>">

  <td><?php echo esc_html( ptsb_fmt_local_dt($time) ); ?></td>

  <td>
    <span class="ptsb-filename"><?php echo esc_html($file); ?></span>
    <?php if ($is_expired): ?>
      <span class="ptsb-tag vencido">vencido</span>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>"
          class="ptsb-rename-form" style="display:inline">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="rename"/>
      <input type="hidden" name="old_file" value="<?php echo esc_attr($file); ?>"/>
      <input type="hidden" name="new_file" value=""/>
      <button type="button" class="ptsb-rename-btn" title="Renomear" data-old="<?php echo esc_attr($file); ?>">
        <span class="dashicons dashicons-edit" aria-hidden="true"></span>
        <span class="screen-reader-text">Renomear</span>
      </button>
    </form>
  </td>

  <td class="ptsb-col-rotina">
    <?php if ($rotina_label !== ''): ?>
      <span class="ptsb-rotina"><?php echo esc_html($rotina_label); ?></span>
    <?php else: ?>
      <span class="description">—</span>
    <?php endif; ?>
  </td>

  <td class="ptsb-col-letters" aria-label="Partes incluídas">
    <?php foreach ($letters as $L): $meta = ptsb_letter_meta($L); ?>
      <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
        <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
      </span>
    <?php endforeach; ?>
  </td>

  <td><?php echo esc_html( ptsb_hsize($size) ); ?></td>

  <td class="ptsb-col-ret">
    <?php
    if ($is_kept || $keepDays === 0) {
        echo '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
    } elseif (is_int($keepDays) && $keepDays > 0) {
        $ri = $ri ?? ptsb_retention_calc($time, $keepDays);
        echo '<span class="ptsb-ret" title="'.esc_attr('Dia '.$ri['x'].' de '.$ri['y']).'">'
           .esc_html($ri['x'].'/'.$ri['y']).'</span>';
    } else {
        echo '<span class="description">—</span>';
    }
    ?>
  </td>

  <!-- AÇÕES (inalterado) -->
  <td class="ptsb-actions">
    <!-- Restaurar -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:0;"
          onsubmit="return confirm('Restaurar <?php echo esc_js($file); ?>? Isso vai sobrescrever arquivos e banco.');">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <button class="button button-secondary" name="ptsb_action" value="restore" <?php disabled(!ptsb_can_shell()); ?>>Restaurar</button>
    </form>

    <!-- Apagar -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:6px;"
          onsubmit="return confirm('Apagar DEFINITIVAMENTE do Drive: <?php echo esc_js($file); ?>?');">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <button class="button" name="ptsb_action" value="delete"
              <?php disabled(!ptsb_can_shell() || $is_kept); ?>
              <?php echo $is_kept ? 'title="Desative &quot;Sempre manter&quot; antes de apagar"' : ''; ?>>
        Apagar
      </button>
    </form>

    <!-- Toggle “Sempre manter” (inalterado) -->
    <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" class="ptsb-keep-form">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="keep_toggle"/>
      <input type="hidden" name="file" value="<?php echo esc_attr($file); ?>"/>
      <div class="ptsb-keep-toggle">
        <label class="ptsb-switch" title="<?php echo $is_kept ? 'Desativar' : 'Ativar'; ?>">
          <input type="checkbox" name="keep" value="1" <?php checked($is_kept); ?> onchange="this.form.submit()">
          <span class="ptsb-slider" aria-hidden="true"></span>
        </label>
        <span class="ptsb-keep-txt">Sempre manter</span>
      </div>
    </form>
  </td>
</tr>

  
          <?php endforeach; endif; ?>
          </tbody>
        </table>

        <script>
(function(){
  const ajaxUrl = window.ajaxurl || "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const nonce   = "<?php echo esc_js($nonce); ?>";

  // coleta arquivos visíveis da página atual
  function collectFiles(){
    return Array.from(document.querySelectorAll('table.widefat tbody tr[data-file]'))
      .map(tr => tr.getAttribute('data-file'));
  }

  // util: desenha os ícones de letras
  function letterIcon(L){
    const map = {
      'D': 'dashicons-database',
      'P': 'dashicons-admin-plugins',
      'T': 'dashicons-admin-appearance',
      'W': 'dashicons-wordpress-alt',
      'S': 'dashicons-editor-code',
      'M': 'dashicons-admin-media',
      'O': 'dashicons-image-filter'
    };
    const cls = map[L] || 'dashicons-marker';
    return '<span class="ptsb-mini" title="'+L+'"><span class="dashicons '+cls+'"></span></span>';
  }

  // util: calcula badge de retenção (“sempre” | X/Y | —) e vencido
  function renderRetentionCell(tr, keepDays){
    const kept = tr.getAttribute('data-kept') === '1';
    const td   = tr.querySelector('.ptsb-col-ret'); if (!td) return;

    if (kept) {
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      return;
    }
    if (keepDays === null) {
      td.textContent = '—';
      return;
    }
    if (keepDays === 0) {
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      return;
    }
    const iso = tr.getAttribute('data-time');
    const created = new Date(iso);
    const now = new Date();
    const elapsedDays = Math.max(0, Math.floor((now - created) / 86400000));
    const x = Math.min(keepDays, elapsedDays + 1);
    const expired = (x >= keepDays);

    td.innerHTML = '<span class="ptsb-ret" title="Dia '+x+' de '+keepDays+'">'+x+'/'+keepDays+'</span>';

    // aplica classe “vencido” na linha + badge no nome do arquivo (se quiser)
    if (expired && !kept) {
      tr.classList.add('ptsb-expired');
      const nameCell = tr.querySelector('.ptsb-filename');
      if (nameCell && !nameCell.nextElementSibling?.classList?.contains('ptsb-tag')) {
        const tag = document.createElement('span');
        tag.className = 'ptsb-tag vencido';
        tag.textContent = 'vencido';
        nameCell.insertAdjacentElement('afterend', tag);
      }
    }
  }

  function hydrate(){
    const files = collectFiles();
    if (!files.length) return;

    const body = new URLSearchParams();
    body.set('action', 'ptsb_details_batch');
    body.set('nonce', nonce);
    files.forEach(f => body.append('files[]', f));

    fetch(ajaxUrl, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body.toString()})
      .then(r => r.json()).then(res => {
        if (!res || !res.success || !res.data) return;
        const data = res.data;

        // preenche cada linha
        files.forEach(file => {
          const tr = document.querySelector('tr[data-file="'+CSS.escape(file)+'"]');
          if (!tr) return;
          const d  = data[file] || {};

          // Rotina
          const cR = tr.querySelector('.ptsb-col-rotina');
          if (cR) cR.textContent = d.routine_label || '—';

          // Letras
          const cL = tr.querySelector('.ptsb-col-letters');
          if (cL) {
            const letters = (d.parts_letters && d.parts_letters.length) ? d.parts_letters : ['D','P','T','W','S','M','O'];
            cL.innerHTML = letters.map(letterIcon).join('');
          }

          // Retenção (e marca "vencido")
          renderRetentionCell(tr, (d.keep_days === null ? null : parseInt(d.keep_days,10)));
        });
      })
      .catch(()=>{ /* silencioso */ });
  }

  // roda após a tabela existir
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hydrate);
  } else {
    hydrate();
  }
})();
</script>

        
        <?php if ($total_pages > 1): ?>
  <nav class="ptsb-pager" aria-label="Paginação dos backups">
    <a class="btn <?php echo $paged<=1?'is-disabled':''; ?>"
       href="<?php echo $paged>1 ? $make_url(1) : '#'; ?>" aria-disabled="<?php echo $paged<=1?'true':'false'; ?>"
       title="Primeira página">
      <span class="dashicons dashicons-controls-skipback"></span>
    </a>

    <a class="btn <?php echo $paged<=1?'is-disabled':''; ?>"
       href="<?php echo $paged>1 ? $make_url($paged-1) : '#'; ?>" aria-disabled="<?php echo $paged<=1?'true':'false'; ?>"
       title="Página anterior">
      <span class="dashicons dashicons-arrow-left-alt2"></span>
    </a>

    <span class="status">
      <input id="ptsb-pager-input" class="current" type="number"
             min="1" max="<?php echo (int)$total_pages; ?>" value="<?php echo (int)$paged; ?>">
      <span class="sep">de</span>
      <span class="total"><?php echo (int)$total_pages; ?></span>
    </span>

    <a class="btn <?php echo $paged>=$total_pages?'is-disabled':''; ?>"
       href="<?php echo $paged<$total_pages ? $make_url($paged+1) : '#'; ?>" aria-disabled="<?php echo $paged>=$total_pages?'true':'false'; ?>"
       title="Próxima página">
      <span class="dashicons dashicons-arrow-right-alt2"></span>
    </a>

    <a class="btn <?php echo $paged>=$total_pages?'is-disabled':''; ?>"
       href="<?php echo $paged<$total_pages ? $make_url($total_pages) : '#'; ?>" aria-disabled="<?php echo $paged>=$total_pages?'true':'false'; ?>"
       title="Última página">
      <span class="dashicons dashicons-controls-skipforward"></span>
    </a>
  </nav>

<script>
  (function(){
    var i=document.getElementById('ptsb-pager-input');
    if(!i) return;
    function go(){
      var min=parseInt(i.min,10)||1, max=parseInt(i.max,10)||1;
      var v = Math.max(min, Math.min(max, parseInt(i.value,10)||min));
      // >>> TROCA: mantém na aba "backup" e usa $per/$paged
      location.href = '<?php echo esc_js( add_query_arg([
        'page'  => 'pt-simple-backup',
        'tab'   => 'backup',
        'per'   => $per,
        'paged' => '__P__',
      ], admin_url('tools.php')) ); ?>'.replace('__P__', v);
    }
    i.addEventListener('change', go);
    i.addEventListener('keyup', function(e){ if(e.key==='Enter'){ go(); }});
  })();
</script>

<?php endif; ?>


        <script>
        (function(){
          // Chips -> envia letters em parts_sel[]
          const chipsBox = document.getElementById('ptsb-chips');
          const formNow  = document.getElementById('ptsb-now-form');
          function getActiveLetters(){
            const arr=[]; chipsBox.querySelectorAll('.ptsb-chip').forEach(c=>{
              if(c.classList.contains('active')) arr.push(String(c.dataset.letter||'').toUpperCase());
            }); return arr;
          }
         function getActiveLetters(){
  const sel = chipsBox.querySelectorAll('input[type="checkbox"][data-letter]:checked');
  return Array.from(sel).map(i => String(i.dataset.letter||'').toUpperCase());
}

          formNow.addEventListener('submit', function(){
            const sentinel = document.getElementById('ptsb-parts-hidden-sentinel');
            if(sentinel) sentinel.parentNode.removeChild(sentinel);
            formNow.querySelectorAll('input[name="parts_sel[]"]').forEach(i=>i.remove());
            const L = getActiveLetters();
            (L.length ? L : ['D','P','T','W','S','M','O']).forEach(letter=>{
              const i=document.createElement('input'); i.type='hidden'; i.name='parts_sel[]'; i.value=letter; formNow.appendChild(i);
            });
          });
        })();
        (function(){
          const cb   = document.getElementById('ptsb-man-keep-forever');
          const days = document.querySelector('#ptsb-now-form input[name="manual_keep_days"]');
          if (!cb || !days) return;
          function sync(){ days.disabled = cb.checked; days.style.opacity = cb.checked ? .5 : 1; }
          cb.addEventListener('change', sync); sync();
        })();

       (function(){
  const ajaxUrl = window.ajaxurl || "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
  const nonce   = "<?php echo esc_js($nonce); ?>";

  const barBox  = document.getElementById('ptsb-progress');
  const bar     = document.getElementById('ptsb-progress-bar');
  const btxt    = document.getElementById('ptsb-progress-text');

  let wasRunning=false, didReload=false;

  function poll(){
    const body = new URLSearchParams({action:'ptsb_status', nonce:nonce}).toString();
    fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body})
      .then(r=>r.json()).then(res=>{
        if(!res || !res.success) return;
        const s = res.data || {};
        if(s.running){
          wasRunning = true; barBox.style.display='block';
          const pct = Math.max(5, Math.min(100, s.percent|0));
          bar.style.width = pct + '%';
          btxt.textContent = (pct<100 ? (pct+'% - '+(s.stage||'executando…')) : '100%');
        } else {
          if(wasRunning && (s.percent|0) >= 100 && !didReload){
            didReload = true; bar.style.width='100%'; btxt.textContent='100% - concluído';
            setTimeout(function(){ location.reload(); }, 1200);
          } else {
            barBox.style.display='none';
          }
          wasRunning = false;
        }
      }).catch(()=>{});
  }
  poll(); setInterval(poll, 2000);
})();

        (function(){
          // Renomear por apelido
          document.addEventListener('click', function(ev){
            const btn = ev.target.closest('.ptsb-rename-btn'); if(!btn) return;
            const form = btn.closest('form.ptsb-rename-form'); if(!form) return;
            const oldFull = btn.getAttribute('data-old')||'';
            const prefix  = "<?php echo esc_js( ptsb_cfg()['prefix'] ); ?>";
            let currentNick = oldFull.replace(new RegExp('^'+prefix), '').replace(/\.tar\.gz$/i,'');
            let nick = window.prompt('Novo apelido (apenas a parte entre "'+prefix+'" e ".tar.gz"):', currentNick);
            if(nick === null) return;
            nick = (nick||'').trim().replace(/\.tar\.gz$/i,'').replace(new RegExp('^'+prefix),'').replace(/[^A-Za-z0-9._-]+/g,'-');
            if(!nick){ alert('Apelido inválido.'); return; }
            const newFull = prefix + nick + '.tar.gz';
            if(newFull === oldFull){ alert('O nome não foi alterado.'); return; }
            if(!/^[A-Za-z0-9._-]+\.tar\.gz$/.test(newFull)){ alert('Use apenas letras, números, ponto, hífen e sublinhado. A extensão deve ser .tar.gz.'); return; }
            form.querySelector('input[name="new_file"]').value = newFull;
            form.submit();
          });
        })();
        </script>

     <?php elseif ($tab === 'cycles'): ?>

  <!-- ===== ABA: ROTINAS ===== -->
  <h2 style="margin-top:22px">Rotinas de backup</h2>

  <!-- NOVO: botão/abridor "Adicionar rotina" (sem ícone) logo abaixo do título -->
  <div style="margin:10px 0 14px;">
  <details>
    <summary class="button button-primary">Adicionar rotina</summary>
    <div style="padding:10px 0">
      <form id="ptsb-add-cycle-form" method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
        <?php wp_nonce_field('ptsb_nonce'); ?>
        <input type="hidden" name="action" value="ptsb_cycles"/>
        <input type="hidden" name="do" value="save_one"/>

    
<p class="description" style="margin-top:0">
  Selecione as partes do site a incluir no backup. Para um backup completo, mantenha todas selecionadas.
</p>


<div class="ptsb-chips" id="ptsb-add-letters" style="margin-bottom:16px">
  <label class="ptsb-chip" title="Banco de Dados">
    <input type="checkbox" checked data-letter="D">
    <span class="dashicons dashicons-database"></span> Banco de Dados
  </label>
  <label class="ptsb-chip" title="Plugins">
    <input type="checkbox" checked data-letter="P">
    <span class="dashicons dashicons-admin-plugins"></span> Plugins
  </label>
  <label class="ptsb-chip" title="Temas">
    <input type="checkbox" checked data-letter="T">
    <span class="dashicons dashicons-admin-appearance"></span> Temas
  </label>
  <label class="ptsb-chip" title="Core">
    <input type="checkbox" checked data-letter="W">
    <span class="dashicons dashicons-wordpress-alt"></span> Core
  </label>
  <label class="ptsb-chip" title="Scripts">
    <input type="checkbox" checked data-letter="S">
    <span class="dashicons dashicons-editor-code"></span> Scripts
  </label>
  <label class="ptsb-chip" title="Mídia">
    <input type="checkbox" checked data-letter="M">
    <span class="dashicons dashicons-admin-media"></span> Mídia
  </label>
  <label class="ptsb-chip" title="Outros">
    <input type="checkbox" checked data-letter="O">
    <span class="dashicons dashicons-image-filter"></span> Outros
  </label>
</div>

<script>
(function(){
  // mantém os chips dentro do form e gera letters[] no submit
  const form = document.getElementById('ptsb-add-cycle-form');
  if (!form) return;
  const wrap = form.querySelector('#ptsb-add-letters');
  if (!wrap) return;

  form.addEventListener('submit', function(){
    // limpa restos
    form.querySelectorAll('input[name="letters[]"]').forEach(i => i.remove());
    // cria letters[] pelos chips marcados
    wrap.querySelectorAll('input[type="checkbox"][data-letter]:checked').forEach(cb => {
      const h = document.createElement('input');
      h.type = 'hidden';
      h.name = 'letters[]';
      h.value = String(cb.dataset.letter || '').toUpperCase();
      form.appendChild(h);
    });
  });
})();
</script>

         <label>Nome da Rotina:
  <input type="text" name="name" value="" required aria-required="true"
         style="min-width:260px" placeholder="Ex.: Diário completo">
</label>


<label>Armazenar por quantos dias?
  <input type="number" name="keep_days" min="1" max="3650"
         placeholder="Máx: 3650" required style="width:120px">
</label>


<!-- Toggle: Sempre manter -->
<div class="ptsb-keep-toggle" style="margin-left:8px">
  <label class="ptsb-switch" title="Sempre manter">
    <input type="checkbox" name="keep_forever" value="1">
    <span class="ptsb-slider" aria-hidden="true"></span>
  </label>
  <span class="ptsb-keep-txt">Sempre manter</span>
</div>

<script>
(function(form){
  if(!form) return;
  var cb   = form.querySelector('input[name="keep_forever"]');
  var days = form.querySelector('input[name="keep_days"]');
  if(!cb || !days) return;
  function sync(){ days.disabled = cb.checked; days.style.opacity = cb.checked ? .5 : 1; }
  cb.addEventListener('change', sync); sync();
})(document.currentScript.closest('form'));
</script>


          <br>

         <label class="ptsb-section-gap">Tipo:
  <select name="mode" onchange="this.closest('form').querySelectorAll('[data-new]').forEach(el=>el.style.display='none'); this.closest('form').querySelector('[data-new='+this.value+']').style.display='';">
    <option value="daily" selected>Diário</option>
    <option value="weekly">Semanal</option>
    <option value="every_n">Recorrente</option>
    <option value="interval">Intervalo</option>
  </select>
  <script>
(function(sel){
  if(!sel) return;
  const form = sel.closest('form');
  function toggleSections(){
    const val = sel.value;
    form.querySelectorAll('[data-new],[data-sec]').forEach(box=>{
      const active = (box.getAttribute('data-new')===val) || (box.getAttribute('data-sec')===val);
      box.style.display = active ? '' : 'none';
      // desabilita/habilita TODOS inputs/ selects/ textareas da seção
      box.querySelectorAll('input, select, textarea').forEach(el=>{
        el.disabled = !active;
      });
    });
  }
  sel.addEventListener('change', toggleSections);
  toggleSections(); // inicial
})(document.currentScript.previousElementSibling);
</script>

  <script>
    (function(sel){ if(!sel) return; sel.dispatchEvent(new Event('change')); })
    (document.currentScript.previousElementSibling);
  </script>
</label>


          <div data-new="daily">
  <div class="ptsb-inline-field" style="margin-top:6px">Quantos horários por dia?</div>
  <input type="number" name="qty" min="1" max="12" value="3" style="width:80px" id="new-daily-qty">
  <div id="new-daily-times" class="ptsb-times-grid"></div>

  <script>
 (function(qId, boxId){
  var q = document.getElementById(qId), box = document.getElementById(boxId);
  if(!q || !box) return;
  function rebuild(){
    var n = Math.max(1, Math.min(12, parseInt(q.value,10)||1));
    var old = Array.from(box.querySelectorAll('input[type="time"]')).map(i=>i.value);
    box.innerHTML = '';
    for(var i=0;i<n;i++){
      var inp = document.createElement('input');
      inp.type='time'; inp.step=60; inp.name='times[]'; inp.style.width='100%';
      if(old[i]) inp.value = old[i];
      box.appendChild(inp);
    }
    // NOVO: re-aplica a habilitação da seção ativa (desabilita o resto)
    var sel = box.closest('form')?.querySelector('select[name="mode"]');
    if (sel) sel.dispatchEvent(new Event('change'));
  }
  q.addEventListener('input', rebuild);
  rebuild();
})('new-daily-qty','new-daily-times');

  </script>
</div>

          <div data-new="weekly" style="display:none">
<div class="ptsb-inline-field" style="margin-top:8px">Quantos horários por dia?</div>
  <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-weekly-qty">
  <div id="new-weekly-times" class="ptsb-times-grid"></div>
  <div>
  <p>Defina em quais dias da semana o backup será feito:</p>
</div>
<div class="ptsb-chips" id="wk_new">

  <span class="ptsb-chip" data-day="0" title="Domingo"        aria-label="Domingo">D</span>
  <span class="ptsb-chip" data-day="1" title="Segunda-feira"   aria-label="Segunda-feira">S</span>
  <span class="ptsb-chip" data-day="2" title="Terça-feira"     aria-label="Terça-feira">T</span>
  <span class="ptsb-chip" data-day="3" title="Quarta-feira"    aria-label="Quarta-feira">Q</span>
  <span class="ptsb-chip" data-day="4" title="Quinta-feira"    aria-label="Quinta-feira">Q</span>
  <span class="ptsb-chip" data-day="5" title="Sexta-feira"     aria-label="Sexta-feira">S</span>
  <span class="ptsb-chip" data-day="6" title="Sábado"          aria-label="Sábado">S</span>
</div>
<input type="text" name="wk_days_guard" id="wk_new_guard"
       style="position:absolute;left:-9999px;width:1px;height:1px" tabindex="-1"
         aria-hidden="true" disabled>




  <script>(function(wrap){
    if(!wrap) return;
    function sync(){
      const f = wrap.closest('form');
      f.querySelectorAll('input[name="wk_days[]"]').forEach(n=>n.remove());
      wrap.querySelectorAll('.ptsb-chip.active').forEach(ch=>{
        const i=document.createElement('input');
        i.type='hidden'; i.name='wk_days[]'; i.value=String(ch.dataset.day||''); f.appendChild(i);
      });
    }
    wrap.addEventListener('click', e=>{ const ch=e.target.closest('.ptsb-chip'); if(!ch) return; ch.classList.toggle('active'); sync(); });
    sync();
  })(document.getElementById('wk_new'));</script>

  


  <script>
 (function(qId, boxId){
  var q = document.getElementById(qId), box = document.getElementById(boxId);
  if(!q || !box) return;
  function rebuild(){
    var n = Math.max(1, Math.min(12, parseInt(q.value,10)||1));
    var old = Array.from(box.querySelectorAll('input[type="time"]')).map(i=>i.value);
    box.innerHTML = '';
    for(var i=0;i<n;i++){
      var inp = document.createElement('input');
      inp.type='time'; inp.step=60; inp.name='times[]'; inp.style.width='100%';
      if(old[i]) inp.value = old[i];
      box.appendChild(inp);
    }
    var sel = box.closest('form')?.querySelector('select[name="mode"]');
    if (sel) sel.dispatchEvent(new Event('change'));
  }
  q.addEventListener('input', rebuild);
  rebuild();
})('new-weekly-qty','new-weekly-times');

  </script>
</div>



         <div data-new="every_n" style="display:none">




<div class="ptsb-inline-field" style="margin-left:6px">Quantos horários por dia?</div>

  <input type="number" name="qty" min="1" max="12" value="1" style="width:80px" id="new-everyn-qty">
<div>
<label style="margin-top:10px;display:inline-block">Repetir a cada quantos dias? <input type="number" min="1" max="30" name="n" value="3" style="width:80px"></label>
</div>
<div id="new-everyn-times" class="ptsb-times-grid"></div>


  <script>
  (function(qId, boxId){
  var q = document.getElementById(qId), box = document.getElementById(boxId);
  if(!q || !box) return;
  function rebuild(){
    var n = Math.max(1, Math.min(12, parseInt(q.value,10)||1));
    var old = Array.from(box.querySelectorAll('input[type="time"]')).map(i=>i.value);
    box.innerHTML = '';
    for(var i=0;i<n;i++){
      var inp = document.createElement('input');
      inp.type='time'; inp.step=60; inp.name='times[]'; inp.style.width='100%';
      if(old[i]) inp.value = old[i];
      box.appendChild(inp);
    }
    var sel = box.closest('form')?.querySelector('select[name="mode"]');
    if (sel) sel.dispatchEvent(new Event('change'));
  }
  q.addEventListener('input', rebuild);
  rebuild();
})('new-everyn-qty','new-everyn-times');

  </script>

  

</div>


          <div data-new="interval" style="display:none">
  <label>Repetir a cada
    <input type="number" name="every_val" value="2" min="1" style="width:48px">
    <select name="every_unit">
      <option value="minute">minuto(s)</option>
      <option value="hour"  selected>hora(s)</option>
      <option value="day">dia(s)</option> <!-- NOVO -->
    </select>
  </label>

  <!-- NOVO: toggle para desativar a janela (ligado por padrão) -->
  <label class="ptsb-keep-toggle" style="margin-left:10px" title="Ignorar início/fim; usar o dia inteiro">
    <label class="ptsb-switch" style="margin-right:6px">
      <input type="checkbox" name="win_disable" value="1" checked>
      <span class="ptsb-slider" aria-hidden="true"></span>
    </label>
    <span class="ptsb-keep-txt">Desativar janela de tempo</span>
  </label>

  <label style="margin-left:10px">Janela:
    <input type="time" name="win_start" value="08:00" style="width:120px"> –
    <input type="time" name="win_end"   value="20:00" style="width:120px">
  </label>
</div>

<script>
// NOVO: desabilita/oculta a janela quando o toggle está ligado
(function(wrap){
  if(!wrap) return;
  var dis = wrap.querySelector('input[name="win_disable"]');
  var s   = wrap.querySelector('input[name="win_start"]');
  var e   = wrap.querySelector('input[name="win_end"]');
  function sync(){
    var on = dis && dis.checked;
    [s,e].forEach(function(i){
      if(!i) return;
      i.disabled = on;
      i.style.opacity = on ? .5 : 1;
    });
  }
  dis && dis.addEventListener('change', sync);
  sync(); // padrão: ligado
})(document.currentScript.previousElementSibling);
</script>



                   <input type="hidden" name="policy_one" value="queue">
          <div style="margin-top:10px"><button class="button button-primary">Salvar rotina</button>
 <!-- Toggle "Ativar ao salvar" agora ao lado do botão -->
  <div class="ptsb-keep-toggle" title="Ativar ao salvar">
    <label class="ptsb-switch">
      <input type="checkbox" name="enabled" value="1" checked>
      <span class="ptsb-slider" aria-hidden="true"></span>
    </label>
    <span class="ptsb-keep-txt">Ativar ao salvar</span>
  </div>
</div>

          </div>
        </form>



      </div>
    </details>
  </div>

  <?php
  $cycles = ptsb_cycles_get();
  ?>
  <table class="widefat striped">

          <thead>
            <tr>
              <th>Ativo</th>
              <th>Nome</th>
              <th>Frequência</th>
              <th>Dias e Horários</th>
              <th>Backup</th>
              <th>Retenção</th>
              <th>Próx. execução.</th>
              <th>Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$cycles): ?>
              <tr><td colspan="8"><em>Nenhuma rotina ainda. Use “Adicionar rotina”.</em></td></tr>
            <?php else:
              foreach ($cycles as $c):
                $cid = esc_attr($c['id']);
                $parts_letters = array_values(array_intersect(array_map('strtoupper', (array)($c['letters']??[])), ['D','P','T','W','S','M','O']));
               $mode = strtolower($c['mode'] ?? 'daily');
if ($mode === 'daily') {
    $freq = 'Diário';
} elseif ($mode === 'weekly') {
    $freq = 'Semanal';
} elseif ($mode === 'every_n') {
    $n = max(1, (int)($c['cfg']['n'] ?? 1));
    // Ex.: "Cada 2 dias / Recorrente"
     $freq = 'Recorrente · A cada ' . $n . ' dias';
} elseif ($mode === 'interval') {
    $freq = 'Intervalo';
} else {
    $freq = ucfirst($mode);
}



$p = ptsb_cycle_params_label_ui($c);  // << usar o helper
$next1 = ptsb_cycles_next_occurrences([$c], 1);
$nx    = $next1 ? esc_html($next1[0]['dt']->format('d/m/Y H:i')) : '(—)';



                $defDays = (int)($set['keep_days'] ?? 0);
            ?>
              <tr>
                <td>
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>">
                    <?php wp_nonce_field('ptsb_nonce'); ?>
                    <input type="hidden" name="action" value="ptsb_cycles"/>
                    <input type="hidden" name="do" value="toggle"/>
                    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
                    <label class="ptsb-switch">
                      <input type="checkbox" name="enabled" value="1" <?php checked(!empty($c['enabled'])); ?> onchange="this.form.submit()">
                      <span class="ptsb-slider"></span>
                    </label>
                  </form>
                </td>
                <td><strong><?php echo esc_html($c['name'] ?? ''); ?></strong></td>
                <td><?php echo esc_html($freq); ?></td>
                <td style="white-space:nowrap"><?php echo esc_html($p); ?></td>
                <td>
                  <?php foreach ($parts_letters as $L): $meta = ptsb_letter_meta($L); ?>
                    <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                      <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td>
                  <?php
                  if (isset($c['keep_days']) && (int)$c['keep_days'] === 0) {
                    echo '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
                  } elseif (isset($c['keep_days']) && (int)$c['keep_days'] > 0) {
                    $d = (int)$c['keep_days'];
                    echo '<span class="ptsb-ret" title="'.esc_attr(sprintf('Reter por %d dias', $d)).'">'.esc_html($d).' d</span>';
                  } else {
                    echo '<span class="ptsb-ret" title="'.esc_attr(sprintf('Padrão do painel: %d dias', $defDays)).'">'.esc_html($defDays).' d</span>';
                  }
                  ?>
                </td>
              <td><?php echo $nx; ?></td>

            <td class="ptsb-actions">
  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:0"
        onsubmit="return confirm('Duplicar esta rotina?');">
    <?php wp_nonce_field('ptsb_nonce'); ?>
    <input type="hidden" name="action" value="ptsb_cycles"/>
    <input type="hidden" name="do" value="dup"/>
    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
    <button class="button">Duplicar</button>
  </form>

  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline;margin-left:6px"
        onsubmit="return confirm('Remover esta rotina?');">
    <?php wp_nonce_field('ptsb_nonce'); ?>
    <input type="hidden" name="action" value="ptsb_cycles"/>
    <input type="hidden" name="do" value="delete"/>
    <input type="hidden" name="id" value="<?php echo $cid; ?>"/>
    <button class="button">Remover</button>
  </form>
</td>

            
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>

        <script>
(function(){
  document.addEventListener('submit', function(ev){
    const f = ev.target;
    // só nos forms de rotinas (adicionar/editar)
    if (!f.matches('form') || !f.querySelector('input[name="action"][value="ptsb_cycles"]')) return;

    const modeSel = f.querySelector('select[name="mode"]');
    if (!modeSel) return;
    const mode = modeSel.value;

    // pega a seção ativa (nova ou editar)
    const sec = f.querySelector('[data-new="'+mode+'"],[data-sec="'+mode+'"]') || f;

    // valida horários (todos required)
    const times = sec.querySelectorAll('input[type="time"]:not([disabled])');
    for (const inp of times) {
      inp.required = true;
      if (!inp.value) { ev.preventDefault(); inp.reportValidity(); return; }
    }

    // Semanal: exige pelo menos 1 dia
    if (mode === 'weekly') {
      const guard = f.querySelector('input[name="wk_days_guard"]');
      const hasDay = !!sec.querySelector('.ptsb-chips [data-day].active');
      if (guard) {
        if (!hasDay) {
          guard.value=''; guard.setCustomValidity('Selecione pelo menos 1 dia da semana.');
          ev.preventDefault(); guard.reportValidity(); return;
        } else {
          guard.value='ok'; guard.setCustomValidity('');
        }
      }
    }
  }, true);
})();
</script>
    

          <?php elseif ($tab === 'next'): ?>

        <!-- ===== ABA: PRÓXIMAS EXECUÇÕES (filtro por data + paginação) ===== -->
        <?php
        $cycles  = ptsb_cycles_get();
        $skipmap = ptsb_skipmap_get();

        // ====== CONTROLES ======
        // per/página (1..100), lembrando preferência
        $per_default = (int) get_option('ptsb_next_per_page', 12);
        $per_next = isset($_GET['per_next']) ? (int) $_GET['per_next'] : ($per_default > 0 ? $per_default : 12);
        $per_next = max(1, min($per_next, 100));
        if (isset($_GET['per_next'])) update_option('ptsb_next_per_page', $per_next, false);

        $page_next = max(1, (int)($_GET['page_next'] ?? 1));

        // filtro de data (YYYY-mm-dd)
        $next_date_raw = isset($_GET['next_date']) ? preg_replace('/[^0-9\-]/','', (string)$_GET['next_date']) : '';
        $next_date     = '';
        $dayObj        = null;
        if ($next_date_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $next_date_raw)) {
            try { $dayObj = new DateTimeImmutable($next_date_raw.' 00:00:00', ptsb_tz()); $next_date = $next_date_raw; }
            catch (Throwable $e) { $dayObj = null; }
        }

        if ($dayObj) {
    $today0 = ptsb_now_brt()->setTime(0,0);
    if ($dayObj < $today0) {
        $dayObj    = $today0;
        $next_date = $dayObj->format('Y-m-d'); // mantém coerente no input
    }
}

        // Carrega a lista:
        // - com data: todas as ocorrências daquele dia
        // - sem data: estratégia "per * page" (futuro ilimitado)
        if ($cycles) {
            if ($dayObj) {
                $all = ptsb_cycles_occurrences_for_date($cycles, $dayObj);
                $total_loaded = count($all);
                $has_next = false; // sabemos o total do dia; não há "futuro" dentro do mesmo dia
            } else {
                $need  = $per_next * $page_next;
                $all   = ptsb_cycles_next_occurrences($cycles, $need);
                $total_loaded = count($all);
                $has_next = ($total_loaded === $need);
            }
        } else {
            $all=[]; $total_loaded=0; $has_next=false;
        }

        // Fatia para a página atual
        $offset    = ($page_next - 1) * $per_next;
        $rows_page = array_slice($all, $offset, $per_next);

        // Helpers de URL (preservando o filtro de data)
        $base_admin = admin_url('tools.php');
        $make_url = function($p, $per, $date='') use ($base_admin) {
            $args = [
                'page'      => 'pt-simple-backup',
                'tab'       => 'next',
                'per_next'  => (int) $per,
                'page_next' => (int) $p,
            ];
            if ($date) $args['next_date'] = $date;
            return esc_url( add_query_arg($args, $base_admin) );
        };
        ?>

        <h2 style="margin-top:8px">Próximas Execuções</h2>

        <?php if (!$cycles): ?>
          <p><em>Sem rotinas ativas.</em></p>
        <?php elseif (!$all): ?>
          <p><em><?php echo $dayObj ? 'Nenhuma execução neste dia.' : 'Nenhuma execução prevista. Confira as rotinas e horários.'; ?></em></p>
        <?php else: ?>

          <!-- Controles: Filtro por data + "Exibindo N por página" -->
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin:8px 0 10px">
            <form method="get" id="ptsb-next-date-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:8px;margin:0">
              <input type="hidden" name="page" value="pt-simple-backup">
              <input type="hidden" name="tab"  value="next">
              <input type="hidden" name="per_next"  value="<?php echo (int)$per_next; ?>">
              <input type="hidden" name="page_next" value="1"><!-- mudar a data volta pra pág. 1 -->
              <span>Ver execuções do dia:</span>
              <input type="date"
       name="next_date"
       value="<?php echo esc_attr($next_date); ?>"
       min="<?php echo esc_attr( ptsb_now_brt()->format('Y-m-d') ); ?>"
       style="width:auto">

              <?php if ($next_date): ?>
                <a class="button" href="<?php echo esc_url( add_query_arg(['page'=>'pt-simple-backup','tab'=>'next','per_next'=>$per_next,'page_next'=>1], $base_admin) ); ?>">Limpar</a>
              <?php endif; ?>
            </form>

            <form method="get" id="ptsb-next-per-form" class="ptsb-list-controls" style="display:flex;align-items:center;gap:6px;margin:0">
              <input type="hidden" name="page" value="pt-simple-backup">
              <input type="hidden" name="tab" value="next">
              <?php if ($next_date): ?><input type="hidden" name="next_date" value="<?php echo esc_attr($next_date); ?>"><?php endif; ?>
              <input type="hidden" name="page_next" value="1"><!-- mudar per volta pra pág. 1 -->
              <span>Exibindo</span>
              <input type="number" name="per_next" min="1" max="100" value="<?php echo (int)$per_next; ?>" style="width:auto">
              <span>próximas execuções — página <?php echo (int)$page_next; ?></span>
            </form>
          </div>

          <script>
          (function(){
            var f1=document.getElementById('ptsb-next-date-form');
            if(f1){ var d=f1.querySelector('input[name="next_date"]'); d&&d.addEventListener('change', function(){ f1.submit(); }); }
            var f2=document.getElementById('ptsb-next-per-form');
            if(f2){ var i=f2.querySelector('input[name="per_next"]'); i&&i.addEventListener('change', function(){ f2.submit(); }); }
          })();
          </script>

          <table class="widefat striped">
            <thead>
              <tr>
                <th>Data/Hora</th>
                <th>Rotinas</th>
                <th>Backup</th>
                <th>Ignorar</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows_page as $it):
              $dtKey = $it['dt']->format('Y-m-d H:i');
              $isIgnored = !empty($skipmap[$dtKey]);
            ?>
              <tr>
                <td><?php echo esc_html( $it['dt']->format('d/m/Y H:i') ); ?></td>
                <td><?php echo esc_html( implode(' + ', (array)$it['names']) ); ?></td>
                <td>
                  <?php foreach ((array)$it['letters'] as $L): $meta = ptsb_letter_meta($L); ?>
                    <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
                      <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
                    </span>
                  <?php endforeach; ?>
                </td>
                <td>
                  <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" style="display:inline">
                    <?php wp_nonce_field('ptsb_nonce'); ?>
                    <input type="hidden" name="action" value="ptsb_cycles"/>
                    <input type="hidden" name="do" value="skip_toggle"/>
                    <input type="hidden" name="time" value="<?php echo esc_attr($dtKey); ?>"/>
                    <div class="ptsb-keep-toggle">
                      <label class="ptsb-switch" title="<?php echo $isIgnored ? 'Recolocar esta execução' : 'Ignorar esta execução'; ?>">
                        <input type="checkbox" name="skip" value="1" <?php checked($isIgnored); ?> onchange="this.form.submit()">
                        <span class="ptsb-slider" aria-hidden="true"></span>
                      </label>
                      <span class="ptsb-keep-txt">Ignorar esta execução</span>
                    </div>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <!-- Paginação: primeira / anterior / próxima -->
          <nav class="ptsb-pager" aria-label="Paginação das próximas execuções">
            <?php
              $is_first = ($page_next <= 1);
              $prev_url = $is_first ? '#' : $make_url($page_next - 1, $per_next, $next_date);
              // quando há filtro de data, $has_next é false; a paginação considera apenas os itens do dia
              $has_next_effective = $dayObj ? (($offset + $per_next) < $total_loaded) : $has_next;
              $next_url = $has_next_effective ? $make_url($page_next + 1, $per_next, $next_date) : '#';
            ?>
            <a class="btn <?php echo $is_first?'is-disabled':''; ?>"
               href="<?php echo $is_first ? '#' : $make_url(1, $per_next, $next_date); ?>" aria-disabled="<?php echo $is_first?'true':'false'; ?>"
               title="Primeira página">
              <span class="dashicons dashicons-controls-skipback"></span>
            </a>

            <a class="btn <?php echo $is_first?'is-disabled':''; ?>"
               href="<?php echo $prev_url; ?>" aria-disabled="<?php echo $is_first?'true':'false'; ?>"
               title="Página anterior">
              <span class="dashicons dashicons-arrow-left-alt2"></span>
            </a>

            <span class="status">
              <input id="ptsb-next-pager-input" class="current" type="number" min="1" value="<?php echo (int)$page_next; ?>">
              <span class="sep">página</span>
            </span>

            <a class="btn <?php echo !$has_next_effective?'is-disabled':''; ?>"
               href="<?php echo $next_url; ?>" aria-disabled="<?php echo !$has_next_effective?'true':'false'; ?>"
               title="Próxima página">
              <span class="dashicons dashicons-arrow-right-alt2"></span>
            </a>
          </nav>

          <script>
            (function(){
              var i=document.getElementById('ptsb-next-pager-input');
              if(!i) return;
              function go(){
                var v = Math.max(1, parseInt(i.value,10)||1);
                var url = new URL('<?php echo esc_js( add_query_arg(['page'=>'pt-simple-backup','tab'=>'next','per_next'=>$per_next,'page_next'=>'__P__'] + ($next_date ? ['next_date'=>$next_date] : []), admin_url('tools.php')) ); ?>'.replace('__P__', v));
                location.href = url.toString();
              }
              i.addEventListener('change', go);
              i.addEventListener('keyup', function(e){ if(e.key==='Enter'){ go(); }});
            })();
          </script>

        <?php endif; ?>



      <?php elseif ($tab === 'last'): ?>

  <!-- ===== ABA: ÚLTIMAS EXECUÇÕES (com filtro "Exibindo N" + paginação) ===== -->
  <?php

  // Filtros: mostrar vencidos e/ou em dia (padrão: ambos ligados)
$last_exp = isset($_GET['last_exp']) ? (int)!!$_GET['last_exp'] : 1; // 0 ou 1
$last_ok  = isset($_GET['last_ok'])  ? (int)!!$_GET['last_ok']  : 1; // 0 ou 1


 // >>> ADIÇÃO: parâmetros de paginação desta aba
  $per_default_l = (int) get_option('ptsb_last_per_page', 12);
  $per_last = isset($_GET['per_last']) ? (int) $_GET['per_last'] : ($per_default_l > 0 ? $per_default_l : 12);
  $per_last = max(1, min($per_last, 500));
  if (isset($_GET['per_last'])) update_option('ptsb_last_per_page', $per_last, false);

  $page_last = max(1, (int)($_GET['page_last'] ?? 1));

   // 1) filtra por vencidos/ok
$filtered = [];
foreach ($rows as $r) {
  $time = $r['time']; $file = $r['file'];
  $is_kept  = !empty($keepers[$file]);

  $manifest = ptsb_manifest_read($file);
  $keepDays = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);

  $is_expired = false;
  if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
      $ri = ptsb_retention_calc($time, $keepDays);
      $is_expired = ($ri['x'] >= $ri['y']);
  }

  // aplica o filtro
  if ( ($is_expired && !$last_exp) || (!$is_expired && !$last_ok) ) {
      continue;
  }
  $filtered[] = $r;
}

$total_last   = count($filtered);
$total_pages_l= max(1, (int) ceil($total_last / $per_last));
if ($page_last > $total_pages_l) $page_last = $total_pages_l;

$offset_last = ($page_last - 1) * $per_last;
$rows_last   = array_slice($filtered, $offset_last, $per_last);


    $base_admin = admin_url('tools.php');
$make_url_l = function($p, $per) use ($base_admin, $last_exp, $last_ok) {
  return esc_url( add_query_arg([
    'page'      => 'pt-simple-backup',
    'tab'       => 'last',
    'per_last'  => (int)$per,
    'page_last' => (int)$p,
    'last_exp'  => (int)!!$last_exp,
    'last_ok'   => (int)!!$last_ok,
  ], $base_admin) );
};


  ?>

  <h2 style="margin-top:8px">Últimas execuções</h2>

  <?php if (!$rows_last): ?>
    <p><em>Nenhum backup concluído encontrado no Drive.</em></p>
  <?php else: ?>

    <!-- Toolbar: filtros (esq) + Exibindo (dir) -->
<div class="ptsb-toolbar" style="display:inline-flex;gap:12px;flex-wrap:wrap;align-items:center;margin:8px 0 10px">
  <!-- Checkboxes -->
  <form method="get" id="ptsb-last-filter-form" class="ptsb-list-controls" style="margin:0">
    <input type="hidden" name="page" value="pt-simple-backup">
    <input type="hidden" name="tab"  value="last">
    <input type="hidden" name="per_last"  value="<?php echo (int)$per_last; ?>">
    <input type="hidden" name="page_last" value="1">
    <label style="display:inline-flex;align-items:center;gap:6px">
      <input type="checkbox" name="last_exp" value="1" <?php checked($last_exp); ?>>
      <span>Mostrar vencidos</span>
    </label>
    <label style="display:inline-flex;align-items:center;gap:6px">
      <input type="checkbox" name="last_ok" value="1" <?php checked($last_ok); ?>>
      <span>Mostrar em dia</span>
    </label>
  </form>

  <!-- “Exibindo …” alinhado à direita -->
  <form method="get" id="ptsb-last-per-form"
        class="ptsb-list-controls"
        style="display:flex;align-items:center;gap:6px;margin:0;margin-left:auto">
    <input type="hidden" name="page" value="pt-simple-backup">
    <input type="hidden" name="tab"  value="last">
    <input type="hidden" name="page_last" value="1">
    <!-- PRESERVA OS FILTROS ATUAIS -->
    <input type="hidden" name="last_exp" value="<?php echo (int)$last_exp; ?>">
    <input type="hidden" name="last_ok"  value="<?php echo (int)$last_ok; ?>">

    <span>Exibindo</span>
    <input type="number" name="per_last" min="1" max="500"
           value="<?php echo (int)$per_last; ?>" style="width:auto">
    <span>de <?php echo (int)$total_last; ?> execuções — página
      <?php echo (int)$page_last; ?> de <?php echo (int)$total_pages_l; ?></span>
  </form>
</div>

<script>
(function(){
  var f=document.getElementById('ptsb-last-filter-form');
  if(f){ f.addEventListener('change', function(){ f.submit(); }); }

  var g=document.getElementById('ptsb-last-per-form');
  if(g){
    var i=g.querySelector('input[name="per_last"]');
    if(i){ i.addEventListener('change', function(){ g.submit(); }); }
  }
})();
</script>


    <table class="widefat striped">
      <thead>
        <tr>
          <th>Data/Hora</th>
          <th>Arquivo</th>
          <th>Rotina</th>
          <th>Backup</th>
          <th>Retenção</th>
          <th>Tamanho</th>
        </tr>
      </thead>
      <tbody>
      
<?php foreach ($rows_last as $r):
  $time = $r['time']; $file = $r['file']; $size = (int)($r['size'] ?? 0);
  $manifest     = ptsb_manifest_read($file);
  $rotina_label = ptsb_run_kind_label($manifest, $file);
  $letters      = [];
  if (!empty($manifest['parts'])) $letters = ptsb_parts_to_letters($manifest['parts']);
  if (!$letters) $letters = ['D','P','T','W','S','M','O'];
  $is_kept  = !empty($keepers[$file]);
  $keepDays = ptsb_manifest_keep_days($manifest, (int)$set['keep_days']);

  // >>> NOVO: detecção de vencido (somente se não for "sempre manter")
  $ri = null; $is_expired = false;
  if (!$is_kept && is_int($keepDays) && $keepDays > 0) {
      $ri = ptsb_retention_calc($time, $keepDays);
      $is_expired = ($ri['x'] >= $ri['y']);
  }
    $tr_class = ($is_expired ? ' class="ptsb-expired"' : '');
?>
<tr>
    <tr<?php echo $tr_class; ?>>
  <td><?php echo esc_html( ptsb_fmt_local_dt($time) ); ?></td>
  <td><?php echo esc_html($file); ?></td>
  <td><?php echo esc_html($rotina_label); ?></td>
  <td>
    <?php foreach ($letters as $L): $meta = ptsb_letter_meta($L); ?>
      <span class="ptsb-mini" title="<?php echo esc_attr($meta['label']); ?>">
        <span class="dashicons <?php echo esc_attr($meta['class']); ?>"></span>
      </span>
    <?php endforeach; ?>
  </td>
  <td>
    <?php if ($is_kept): ?>
      <span class="ptsb-ret sempre" title="Sempre manter">sempre</span>
    <?php elseif (is_int($keepDays) && $keepDays > 0):
      $ri = $ri ?: ptsb_retention_calc($time, $keepDays); ?>
      <span class="ptsb-ret" title="<?php echo esc_attr('Dia '.$ri['x'].' de '.$ri['y']); ?>">
        <?php echo (int)$ri['x'].'/'.(int)$ri['y']; ?>
      </span>
      <?php if ($is_expired): ?>
        <!-- >>> NOVO: selo VENCIDO nesta aba -->
        <span class="ptsb-tag vencido">VENCIDO</span>
      <?php endif; ?>
    <?php else: ?>
      —
    <?php endif; ?>
  </td>
  <td><?php echo esc_html( ptsb_hsize($size) ); ?></td>
</tr>
<?php endforeach; ?>

      
      </tbody>
    </table>

    <?php if ($total_pages_l > 1): ?>
      <nav class="ptsb-pager" aria-label="Paginação das últimas execuções">
        <a class="btn <?php echo $page_last<=1?'is-disabled':''; ?>"
           href="<?php echo $page_last>1 ? $make_url_l(1, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last<=1?'true':'false'; ?>"
           title="Primeira página">
          <span class="dashicons dashicons-controls-skipback"></span>
        </a>

        <a class="btn <?php echo $page_last<=1?'is-disabled':''; ?>"
           href="<?php echo $page_last>1 ? $make_url_l($page_last-1, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last<=1?'true':'false'; ?>"
           title="Página anterior">
          <span class="dashicons dashicons-arrow-left-alt2"></span>
        </a>

        <span class="status">
          <input id="ptsb-last-pager-input" class="current" type="number"
                 min="1" max="<?php echo (int)$total_pages_l; ?>" value="<?php echo (int)$page_last; ?>">
          <span class="sep">de</span>
          <span class="total"><?php echo (int)$total_pages_l; ?></span>
        </span>

        <a class="btn <?php echo $page_last>=$total_pages_l?'is-disabled':''; ?>"
           href="<?php echo $page_last<$total_pages_l ? $make_url_l($page_last+1, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last>=$total_pages_l?'true':'false'; ?>"
           title="Próxima página">
          <span class="dashicons dashicons-arrow-right-alt2"></span>
        </a>

        <a class="btn <?php echo $page_last>=$total_pages_l?'is-disabled':''; ?>"
           href="<?php echo $page_last<$total_pages_l ? $make_url_l($total_pages_l, $per_last) : '#'; ?>" aria-disabled="<?php echo $page_last>=$total_pages_l?'true':'false'; ?>"
           title="Última página">
          <span class="dashicons dashicons-controls-skipforward"></span>
        </a>
      </nav>

      <script>
        (function(){
          var i=document.getElementById('ptsb-last-pager-input');
          if(!i) return;
          function go(){
            var min=parseInt(i.min,10)||1, max=parseInt(i.max,10)||1;
            var v = Math.max(min, Math.min(max, parseInt(i.value,10)||min));
            location.href = '<?php echo esc_js( add_query_arg([
  'page'=>'pt-simple-backup','tab'=>'last',
  'per_last'=>$per_last,'page_last'=>'__P__',
  'last_exp'=>(int)$last_exp,'last_ok'=>(int)$last_ok
], admin_url('tools.php')) ); ?>'.replace('__P__', v);

          }
          i.addEventListener('change', go);
          i.addEventListener('keyup', function(e){ if(e.key==='Enter'){ go(); }});
        })();
      </script>
    <?php endif; ?>

  <?php endif; // rows_last ?>


<?php elseif ($tab === 'settings'): ?>

  <!-- ===== ABA: CONFIGURAÇÕES ===== -->
  <h2 style="margin-top:8px">Log</h2>



  <?php $init_log = ptsb_tail_log_raw($cfg['log'], 50); ?>

  <p style="opacity:.7;margin:.3em 0 1em">
        Status: <?php echo esc_html(implode(' | ', $diag)); ?>
      </p>

  <div style="display:flex;align-items:center;gap:8px;margin:10px 0 6px">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
          onsubmit="return confirm('Limpar todo o log (incluindo rotações)?');" style="margin-left:auto">
      <?php wp_nonce_field('ptsb_nonce'); ?>
      <input type="hidden" name="action" value="ptsb_do"/>
      <input type="hidden" name="ptsb_action" value="clear_log"/>
      <button class="button">Limpar log</button>
    </form>
  </div>

  <pre id="ptsb-log" style="max-height:420px;overflow:auto;padding:10px;background:#111;border:1px solid #444;border-radius:4px;"><?php 
      echo esc_html($init_log ?: '(sem linhas)'); 
  ?></pre>
  <p><small>Mostrando as últimas 50 linhas. A rotação cria <code>backup-wp.log.1</code>, <code>.2</code>… até <?php echo (int)$cfg['log_keep']; ?>.</small></p>

  <script>
  (function(){
    const ajaxUrl = window.ajaxurl || "<?php echo esc_js( admin_url('admin-ajax.php') ); ?>";
    const nonce   = "<?php echo esc_js($nonce); ?>";
    const logEl   = document.getElementById('ptsb-log');
    if(!logEl) return;

    let lastLog = logEl.textContent || '';
    let autoStick = true;
    logEl.addEventListener('scroll', function(){
      const nearBottom = (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight) < 24;
      autoStick = nearBottom;
    });

    function renderLog(txt){
      if(txt === lastLog) return;
      const shouldStick = autoStick;
      logEl.textContent = txt;
      if(shouldStick){ requestAnimationFrame(()=>{ logEl.scrollTop = logEl.scrollHeight; }); }
      lastLog = txt;
    }

    function poll(){
      const body = new URLSearchParams({action:'ptsb_status', nonce:nonce}).toString();
      fetch(ajaxUrl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body})
        .then(r=>r.json()).then(res=>{
          if(!res || !res.success) return;
          const s   = res.data || {};
          const txt = (s.log && String(s.log).trim()) ? s.log : '(sem linhas)';
          renderLog(txt);
        }).catch(()=>{});
    }
    poll(); setInterval(poll, 2000);
  })();
  </script>

      <?php endif; // fim roteamento abas ?>
    </div>
    <?php
    settings_errors('ptsb');
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
    if (file_exists($cfg['lock'])) {
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
        $partsCsv = (string)($man['parts'] ?? get_option('ptsb_last_run_parts', ''));
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
        ];

        // dispara o evento; outro plugin/integração cuida de enviar e-mails
        do_action('ptsb_backup_done', $payload);

      // === FALLBACK de e-mail (só se NÃO houver OU pt_done OU pt_finished) ===
if (!has_action('ptsb_backup_done') && !has_action('ptsb_backup_finished') && function_exists('wp_mail')) {
    ptsb_notify_send_email_fallback($payload);
}


        // marca como notificado
        update_option('ptsb_last_notified_backup_file', (string)$latest['file'], true);
        update_option('ptsb_last_notified_payload', $payload, true);

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



// checar notificação no admin e também no cron do plugin
add_action('admin_init', 'ptsb_maybe_notify_backup_done');
add_action('ptsb_cron_tick', 'ptsb_maybe_notify_backup_done');

