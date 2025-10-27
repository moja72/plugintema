<?php
if (function_exists('ptsb_cfg')) { return; }

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/rclone.php';
require_once __DIR__ . '/inc/parts.php';
require_once __DIR__ . '/inc/log.php';
require_once __DIR__ . '/inc/schedule.php';
require_once __DIR__ . '/inc/actions.php';
require_once __DIR__ . '/inc/ajax.php';
require_once __DIR__ . '/inc/ui.php';

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

