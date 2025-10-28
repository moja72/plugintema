<?php
if (!defined('ABSPATH')) { exit; }

/* -------------------------------------------------------
 * AJAX status (progresso + tail)
 * -----------------------------------------------------*/



add_action('wp_ajax_ptsb_status', function () {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCDN')) {
        define('DONOTCDN', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }

    // no-cache também no AJAX
    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    if (!check_ajax_referer('ptsb_nonce', 'nonce', false)) {
        wp_send_json_error('bad nonce', 403);
    }

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
    $running = ptsb_lock_is_active() && $percent < 100;

    wp_send_json_success([
        'running' => (bool)$running,
        'percent' => (int)$percent,
        'stage'   => (string)$stage,
        'log'     => (string)$tail,
        'job'     => ptsb_manual_job_response_payload(),
    ]);
});

add_action('wp_ajax_ptsb_details_batch', function () {
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
    if (!defined('DONOTCDN')) {
        define('DONOTCDN', true);
    }
    if (!defined('DONOTCACHEDB')) {
        define('DONOTCACHEDB', true);
    }

    nocache_headers();
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    if (!check_ajax_referer('ptsb_nonce', 'nonce', false)) {
        wp_send_json_error('bad nonce', 403);
    }

    $files = isset($_POST['files']) ? (array)$_POST['files'] : [];
    $files = array_values(array_filter(array_map('sanitize_text_field', $files)));
    if (!$files) {
        wp_send_json_success([]);
    }

    $files = array_slice($files, 0, 20);
    $settings = ptsb_settings();
    $defaultKeep = isset($settings['keep_days']) ? (int)$settings['keep_days'] : null;

    $response = [];
    foreach ($files as $file) {
        $manifest = ptsb_manifest_read($file);
        if (!is_array($manifest)) {
            $manifest = [];
        }

        $letters = [];
        if (!empty($manifest['parts'])) {
            $letters = ptsb_parts_to_letters($manifest['parts']);
        }
        if (!$letters) {
            $letters = ['D', 'P', 'T', 'W', 'S', 'M', 'O'];
        }

        $keepDays = ptsb_manifest_keep_days($manifest, $defaultKeep);
        $response[$file] = [
            'parts_letters' => array_values($letters),
            'keep_days'     => $keepDays === null ? null : (int) $keepDays,
            'routine_label' => ptsb_run_kind_label($manifest, $file),
        ];
    }

    wp_send_json_success($response);
});
