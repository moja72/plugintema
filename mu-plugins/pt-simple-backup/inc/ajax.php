<?php
if (!defined('ABSPATH')) { exit; }

/* -------------------------------------------------------
 * AJAX status (progresso + tail)
 * -----------------------------------------------------*/



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
    $running = ptsb_lock_is_active() && $percent < 100;

    wp_send_json_success([
        'running' => (bool)$running,
        'percent' => (int)$percent,
        'stage'   => (string)$stage,
        'log'     => (string)$tail,
        'job'     => ptsb_manual_job_response_payload(),
    ]);
});
