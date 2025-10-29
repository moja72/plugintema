<?php
if (!defined('ABSPATH')) { exit; }

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
    $out      = ptsb_rclone_exec('cat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null');

    $data = json_decode((string)$out, true);
    if (!is_array($data)) $data = [];

    if (!$skipCache) {
        set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
    }
    return $data;
}

/** Escreve/mescla o manifest JSON no remoto para o arquivo .tar.gz */

function ptsb_manifest_write(string $tarFile, array $add, bool $merge = true): bool {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $tarFile === '') return false;

    $jsonPath = ptsb_tar_to_json($tarFile);
    $cur      = $merge ? ptsb_manifest_read($tarFile) : [];
    if (!is_array($cur)) $cur = [];

    $data    = array_merge($cur, $add);
    $payload = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    ptsb_rclone_exec_input('rcat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null', (string)$payload);

    delete_transient('ptsb_m_' . md5($tarFile));

    return true;
}

/* -------------------------------------------------------
 * Drive: quota e e-mail (best effort)
 * -----------------------------------------------------*/

function ptsb_rclone_remote_preflight(bool $force_refresh = false): bool {
    $cfg    = ptsb_cfg();
    $remote = (string) ($cfg['remote'] ?? '');

    if (!ptsb_can_shell() || $remote === '') {
        return false;
    }

    $cache_key = 'ptsb_rclone_preflight_' . md5($remote);
    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if ($cached === 'ok') {
            return true;
        }
        if ($cached === 'fail') {
            return false;
        }
    }

    if (!ptsb_shell_command_exists('rclone')) {
        ptsb_log_throttle('rclone_missing', 'rclone não encontrado ao validar o remoto.', 1800);
        set_transient($cache_key, 'fail', 300);
        return false;
    }

    $result = ptsb_rclone_exec_with_status('lsjson ' . escapeshellarg($remote) . ' --max-depth 0 --files-only --dirs-only --limit 1');

    if ((int) $result['exit_code'] === 0) {
        set_transient($cache_key, 'ok', 600);
        return true;
    }

    $error = trim($result['stderr']);
    if ($error === '') {
        $error = trim($result['stdout']);
    }
    if ($error === '') {
        $error = 'comando retornou código ' . (int) $result['exit_code'];
    }

    ptsb_log_throttle('rclone_preflight_fail', '[preflight] Falha ao validar o remoto: ' . $error, 600);
    set_transient($cache_key, 'fail', 300);

    return false;
}

function ptsb_drive_info(bool $force_refresh = false): array {
    $cfg  = ptsb_cfg();
    $info = ['email' => '', 'used' => null, 'total' => null, 'fetched_at' => null];
    if (!ptsb_can_shell()) return $info;

    $cache_key = 'ptsb_drive_info_v1';
    $ttl       = (int) apply_filters('ptsb_drive_info_ttl', 10 * MINUTE_IN_SECONDS);
    if ($ttl < 60) {
        $ttl = 60;
    }

    if ($force_refresh) {
        delete_transient($cache_key);
    } else {
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return array_merge($info, $cached);
        }
    }

    $remote   = $cfg['remote'];
    $rem_name = rtrim($remote, ':');

    $aboutFailed = false;
    $userinfoFailed = false;
    $errorNotes = [];

    $aboutResult = ptsb_rclone_exec_with_status('about ' . escapeshellarg($remote) . ' --json');
    $aboutJson   = $aboutResult['stdout'];
    if ((int) $aboutResult['exit_code'] !== 0 || trim((string) $aboutJson) === '') {
        $aboutFailed = true;
        $note = trim($aboutResult['stderr']);
        if ($note === '') {
            $note = trim((string) $aboutResult['stdout']);
        }
        if ($note !== '') {
            $errorNotes[] = $note;
        }
    } else {
        $j = json_decode((string)$aboutJson, true);
        if (is_array($j)) {
            if (isset($j['used']))  $info['used']  = (int)$j['used'];
            if (isset($j['total'])) $info['total'] = (int)$j['total'];
        } else {
            $aboutFailed = true;
            $errorNotes[] = 'JSON inválido retornado por rclone about.';
        }
    }

    if ($aboutFailed) {
        $txtResult = ptsb_rclone_exec_with_status('about ' . escapeshellarg($remote));
        $txt = $txtResult['stdout'];
        if ((int) $txtResult['exit_code'] !== 0 && trim((string) $txt) === '') {
            $aboutFailed = true;
            $note = trim($txtResult['stderr']);
            if ($note !== '') {
                $errorNotes[] = $note;
            }
        } else {
            $aboutFailed = false;
            if (preg_match('/Used:\s*([\d.,]+)\s*([KMGT]i?B)/i', (string)$txt, $m)) {
                $info['used'] = ptsb_size_to_bytes($m[1], $m[2]);
            }
            if (preg_match('/Total:\s*([\d.,]+)\s*([KMGT]i?B)/i', (string)$txt, $m)) {
                $info['total'] = ptsb_size_to_bytes($m[1], $m[2]);
            }
        }
    }

    $uResult = ptsb_rclone_exec_with_status('backend userinfo ' . escapeshellarg($remote));
    $u = $uResult['stdout'];
    if ((int) $uResult['exit_code'] !== 0 || trim((string) $u) === '') {
        $userinfoFailed = true;
        $note = trim($uResult['stderr']);
        if ($note !== '') {
            $errorNotes[] = $note;
        }
    }

    if (trim((string)$u) === '') {
        $cfgResult = ptsb_rclone_exec_with_status('config userinfo ' . escapeshellarg($rem_name));
        $u = $cfgResult['stdout'];
        if ((int) $cfgResult['exit_code'] !== 0 || trim((string) $u) === '') {
            $userinfoFailed = true;
            $note = trim($cfgResult['stderr']);
            if ($note !== '') {
                $errorNotes[] = $note;
            }
        } else {
            $userinfoFailed = false;
        }
    } else {
        $userinfoFailed = false;
    }

    if ($u !== null && $u !== '') {
        $ju = json_decode((string)$u, true);
        if (is_array($ju)) {
            if (!empty($ju['email']))                     $info['email'] = $ju['email'];
            elseif (!empty($ju['user']['email']))         $info['email'] = $ju['user']['email'];
            elseif (!empty($ju['user']['emailAddress']))  $info['email'] = $ju['user']['emailAddress'];
        } else {
            if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string)$u, $m)) $info['email'] = $m[0];
        }
    }

    $failures = [];
    if ($aboutFailed) {
        $failures[] = 'about';
    }
    if ($userinfoFailed) {
        $failures[] = 'userinfo';
    }

    if (!empty($failures)) {
        delete_transient($cache_key);
        $note = '';
        if ($errorNotes) {
            $note = trim((string) reset($errorNotes));
        }
        if ($note !== '') {
            ptsb_log(sprintf('[drive-info] Falha ao executar rclone (%s) em %s: %s', implode(', ', $failures), $remote, $note));
        } else {
            ptsb_log(sprintf('[drive-info] Falha ao executar rclone (%s) em %s.', implode(', ', $failures), $remote));
        }
        return $info;
    }

    $info['fetched_at'] = time();
    set_transient($cache_key, $info, $ttl);

    return $info;
}

function ptsb_drive_info_clear_cache(): void {
    delete_transient('ptsb_drive_info_v1');
}

/* -------------------------------------------------------
 * Plano "Sempre manter" (marca .keep no próximo arquivo gerado)
 * -----------------------------------------------------*/

function ptsb_plan_mark_keep_next($prefix){
    $prefix = (string)$prefix;
    if ($prefix === '') $prefix = ptsb_cfg()['prefix'];
    update_option('ptsb_mark_keep_plan', ['prefix'=>$prefix, 'set_at'=>time()], false);
}

function ptsb_apply_keep_sidecar($file){
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell() || $file==='') return false;
    $touch = ptsb_rclone_exec('touch ' . escapeshellarg($cfg['remote'] . $file . '.keep') . ' --no-create-dirs');
    if ($touch === null || trim((string)$touch) === '') {
        ptsb_rclone_exec_input('rcat ' . escapeshellarg($cfg['remote'] . $file . '.keep'), '');
    }
    ptsb_remote_cache_flush();
    return true;
}

/* -------------------------------------------------------
 * Listagem Drive + mapa de .keep
 * -----------------------------------------------------*/

function ptsb_list_remote_files(bool $force_refresh = false): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) { return []; }

    $key = 'ptsb_remote_files_v1';
    if (!$force_refresh) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $cmd = 'lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "tsp" --separator ";" --time-format RFC3339 '
         . ' --include ' . escapeshellarg('*.tar.gz')
         . ptsb_rclone_fast_list_flag();
    $out = ptsb_rclone_exec($cmd);
    $rows = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $ln) {
        $parts = explode(';', $ln, 3);
        if (count($parts) === 3) $rows[] = ['time'=>$parts[0], 'size'=>$parts[1], 'file'=>$parts[2]];
    }
    usort($rows, fn($a,$b) => strcmp($b['time'], $a['time']));
    set_transient($key, $rows, 5 * MINUTE_IN_SECONDS);
    return $rows;
}

function ptsb_keep_map(bool $force_refresh = false): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) return [];

    $key = 'ptsb_keep_map_v1';
    if (!$force_refresh) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $cmd = 'lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "p" --separator ";" '
         . ' --include ' . escapeshellarg('*.tar.gz.keep')
         . ptsb_rclone_fast_list_flag();
    $out = ptsb_rclone_exec($cmd);
    $map = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $p) {
        $base = preg_replace('/\.keep$/', '', $p);
        if ($base) $map[$base] = true;
    }
    set_transient($key, $map, 5 * MINUTE_IN_SECONDS);
    return $map;
}

