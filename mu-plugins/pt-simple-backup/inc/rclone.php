<?php
if (!defined('ABSPATH')) { exit; }

/* -------------------------------------------------------
 * MANIFEST (.json) + rótulos e LETRAS (para a coluna “Backup”)
 * -----------------------------------------------------*/

function ptsb_manifest_cache_ttl(): int {
    return (int) apply_filters('ptsb_manifest_cache_ttl', 6 * HOUR_IN_SECONDS);
}

function ptsb_manifest_cache_key(string $tarFile): string {
    return md5($tarFile);
}

function ptsb_manifest_cache_dir(): ?string {
    static $dir = null;
    static $checked = false;

    if ($checked) {
        return $dir;
    }
    $checked = true;

    if (!function_exists('wp_upload_dir')) {
        return $dir = null;
    }

    $uploads = wp_upload_dir();
    if (!empty($uploads['error']) || empty($uploads['basedir'])) {
        return $dir = null;
    }

    $base = rtrim((string) $uploads['basedir'], '/');
    if ($base === '') {
        return $dir = null;
    }

    $target = $base . '/ptsb-manifests';

    if (!is_dir($target)) {
        if (function_exists('wp_mkdir_p')) {
            if (!@wp_mkdir_p($target) && !is_dir($target)) {
                return $dir = null;
            }
        } else {
            if (!@mkdir($target, 0755, true) && !is_dir($target)) {
                return $dir = null;
            }
        }
    }

    return $dir = $target;
}

function ptsb_manifest_cache_index(): array {
    $idx = get_option('ptsb_manifest_cache_index_v1', []);
    return is_array($idx) ? $idx : [];
}

function ptsb_manifest_cache_index_save(array $index): void {
    update_option('ptsb_manifest_cache_index_v1', $index, false);
}

function ptsb_manifest_cache_entry(string $tarFile): ?array {
    $index = ptsb_manifest_cache_index();
    $key   = ptsb_manifest_cache_key($tarFile);
    if (!isset($index[$key]) || !is_array($index[$key])) {
        return null;
    }

    $entry = $index[$key];
    $file  = (string) ($entry['file'] ?? '');
    if ($file === '' || !is_file($file) || !is_readable($file)) {
        ptsb_manifest_cache_forget($tarFile);
        return null;
    }

    return $entry;
}

function ptsb_manifest_cache_forget(?string $tarFile = null): void {
    $index = ptsb_manifest_cache_index();
    $changed = false;

    if ($tarFile === null) {
        foreach ($index as $entry) {
            $file = (string) ($entry['file'] ?? '');
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
        }
        $index = [];
        $changed = true;
    } else {
        $key = ptsb_manifest_cache_key($tarFile);
        if (isset($index[$key])) {
            $file = (string) ($index[$key]['file'] ?? '');
            if ($file !== '' && is_file($file)) {
                @unlink($file);
            }
            unset($index[$key]);
            $changed = true;
        }
    }

    if ($changed) {
        ptsb_manifest_cache_index_save($index);
    }
}

function ptsb_manifest_cache_entry_is_fresh(array $entry): bool {
    $expires = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;
    return $expires > time();
}

function ptsb_manifest_cache_entry_matches_meta(array $entry, array $meta): bool {
    $metaTime = isset($meta['time']) ? (string) $meta['time'] : '';
    $metaSize = isset($meta['size']) ? (int) $meta['size'] : null;

    if ($metaTime === '' && $metaSize === null) {
        return false;
    }

    $entryTime = isset($entry['remote_time']) ? (string) $entry['remote_time'] : '';
    $entrySize = isset($entry['remote_size']) ? (int) $entry['remote_size'] : null;

    if ($metaTime !== '' && $entryTime !== '' && $metaTime !== $entryTime) {
        return false;
    }

    if ($metaSize !== null && $entrySize !== null && $metaSize !== $entrySize) {
        return false;
    }

    return true;
}

function ptsb_manifest_cache_refresh_expiry(string $tarFile, array $entry): void {
    $index = ptsb_manifest_cache_index();
    $key   = ptsb_manifest_cache_key($tarFile);
    if (!isset($index[$key]) || !is_array($index[$key])) {
        return;
    }

    $index[$key]['expires_at'] = time() + ptsb_manifest_cache_ttl();
    ptsb_manifest_cache_index_save($index);
}

function ptsb_manifest_cache_entry_read(string $tarFile, array $entry): ?array {
    $file = (string) ($entry['file'] ?? '');
    if ($file === '' || !is_file($file) || !is_readable($file)) {
        ptsb_manifest_cache_forget($tarFile);
        return null;
    }

    $json = @file_get_contents($file);
    if ($json === false) {
        ptsb_manifest_cache_forget($tarFile);
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        ptsb_manifest_cache_forget($tarFile);
        return null;
    }

    return $data;
}

function ptsb_manifest_cache_cleanup(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $index = ptsb_manifest_cache_index();
    $changed = false;
    $now = time();
    $ttl = ptsb_manifest_cache_ttl();

    foreach ($index as $key => $entry) {
        $file = (string) ($entry['file'] ?? '');
        $expires = isset($entry['expires_at']) ? (int) $entry['expires_at'] : 0;

        if ($file === '' || !is_file($file) || !is_readable($file)) {
            unset($index[$key]);
            $changed = true;
            continue;
        }

        if ($expires && $expires < ($now - $ttl)) {
            @unlink($file);
            unset($index[$key]);
            $changed = true;
        }
    }

    if ($changed) {
        ptsb_manifest_cache_index_save($index);
    }
}

function ptsb_manifest_cache_store(string $tarFile, array $data, array $remoteMeta): void {
    $dir = ptsb_manifest_cache_dir();
    if ($dir === null) {
        return;
    }

    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    ptsb_manifest_cache_cleanup();

    $key  = ptsb_manifest_cache_key($tarFile);
    $file = $dir . '/' . $key . '.json';

    if (@file_put_contents($file, $json, LOCK_EX) === false) {
        return;
    }

    $entry = [
        'file'        => $file,
        'hash'        => md5($json),
        'cached_at'   => time(),
        'expires_at'  => time() + ptsb_manifest_cache_ttl(),
    ];

    if (!empty($remoteMeta)) {
        if (isset($remoteMeta['time'])) {
            $entry['remote_time'] = (string) $remoteMeta['time'];
        }
        if (isset($remoteMeta['size'])) {
            $entry['remote_size'] = (int) $remoteMeta['size'];
        }
    }

    $index = ptsb_manifest_cache_index();
    $index[$key] = $entry;
    ptsb_manifest_cache_index_save($index);
}

function ptsb_manifest_remote_meta(string $tarFile): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) {
        return [];
    }

    $jsonPath = ptsb_tar_to_json($tarFile);
    $cacheKey = 'ptsb_manifest_meta_' . md5($jsonPath);
    $cached   = get_transient($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $cmd = 'lsf ' . escapeshellarg($cfg['remote'])
         . ' --files-only --format "tsp" --separator ";" --time-format RFC3339 '
         . ' --include ' . escapeshellarg($jsonPath) . ' --fast-list';

    $out = ptsb_rclone_exec($cmd);
    $meta = [];

    foreach (array_filter(array_map('trim', explode("\n", (string) $out))) as $line) {
        $parts = explode(';', $line, 3);
        if (count($parts) === 3) {
            $meta = [
                'time' => $parts[0],
                'size' => (int) $parts[1],
            ];
            break;
        }
    }

    set_transient($cacheKey, $meta, 10 * MINUTE_IN_SECONDS);
    return $meta;
}

/** Lê o JSON sidecar do arquivo .tar.gz no remoto e devolve array (cache incremental + fallback 5 min) */

function ptsb_manifest_read(string $tarFile): array {
    $cfg = ptsb_cfg();
    if (!ptsb_can_shell()) return [];

    $key       = 'ptsb_m_' . md5($tarFile);
    $skipCache = defined('PTSB_SKIP_MANIFEST_CACHE') && PTSB_SKIP_MANIFEST_CACHE;

    if (!$skipCache) {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $meta = [];
    $entry = (!$skipCache) ? ptsb_manifest_cache_entry($tarFile) : null;
    if (!$skipCache && $entry) {
        if (ptsb_manifest_cache_entry_is_fresh($entry)) {
            $data = ptsb_manifest_cache_entry_read($tarFile, $entry);
            if (is_array($data)) {
                set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
                return $data;
            }
        } else {
            $meta = ptsb_manifest_remote_meta($tarFile);
            if ($meta && ptsb_manifest_cache_entry_matches_meta($entry, $meta)) {
                ptsb_manifest_cache_refresh_expiry($tarFile, $entry);
                $data = ptsb_manifest_cache_entry_read($tarFile, $entry);
                if (is_array($data)) {
                    set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
                    return $data;
                }
            }
        }
    }

    $jsonPath = ptsb_tar_to_json($tarFile);
    $out      = ptsb_rclone_exec('cat ' . escapeshellarg($cfg['remote'] . $jsonPath) . ' 2>/dev/null');

    $data = json_decode((string) $out, true);
    if (!is_array($data)) {
        $data = [];
    }

    if (!$skipCache) {
        if (!$meta) {
            $meta = ptsb_manifest_remote_meta($tarFile);
        }
        set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
        ptsb_manifest_cache_store($tarFile, $data, $meta);
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
    delete_transient('ptsb_manifest_meta_' . md5($jsonPath));
    ptsb_manifest_cache_forget($tarFile);

    return true;
}

/* -------------------------------------------------------
 * Drive: quota e e-mail (best effort)
 * -----------------------------------------------------*/

function ptsb_drive_info() {
    $cfg  = ptsb_cfg();
    $info = ['email' => '', 'used' => null, 'total' => null];
    if (!ptsb_can_shell()) return $info;

    $remote   = $cfg['remote'];
    $rem_name = rtrim($remote, ':');

    $aboutJson = ptsb_rclone_exec('about ' . escapeshellarg($remote) . ' --json 2>/dev/null');
    $j = json_decode((string)$aboutJson, true);
    if (is_array($j)) {
        if (isset($j['used']))  $info['used']  = (int)$j['used'];
        if (isset($j['total'])) $info['total'] = (int)$j['total'];
    } else {
        $txt = (string)ptsb_rclone_exec('about ' . escapeshellarg($remote) . ' 2>/dev/null');
        if (preg_match('/Used:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m))  $info['used']  = ptsb_size_to_bytes($m[1], $m[2]);
        if (preg_match('/Total:\s*([\d.,]+)\s*([KMGT]i?B)/i', $txt, $m)) $info['total'] = ptsb_size_to_bytes($m[1], $m[2]);
    }

    $u = (string)ptsb_rclone_exec('backend userinfo ' . escapeshellarg($remote) . ' 2>/dev/null');
    if (trim($u) === '') {
        $u = (string)ptsb_rclone_exec('config userinfo ' . escapeshellarg($rem_name) . ' 2>/dev/null');
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
         . ' --include ' . escapeshellarg('*.tar.gz') . ' --fast-list';
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
         . ' --include ' . escapeshellarg('*.tar.gz.keep') . ' --fast-list';
    $out = ptsb_rclone_exec($cmd);
    $map = [];
    foreach (array_filter(array_map('trim', explode("\n", (string)$out))) as $p) {
        $base = preg_replace('/\.keep$/', '', $p);
        if ($base) $map[$base] = true;
    }
    set_transient($key, $map, 5 * MINUTE_IN_SECONDS);
    return $map;
}

