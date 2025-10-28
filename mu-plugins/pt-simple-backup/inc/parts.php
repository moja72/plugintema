<?php
if (!defined('ABSPATH')) { exit; }

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

/* -------------------------------------------------------
 * Seleção da UI (chips) ? PARTS= do script
 * -----------------------------------------------------*/
/** letras padrão marcadas na UI (DB é sempre incluído) */

function ptsb_ui_default_codes(){ 
    // Agora DB é um chip (default marcado)
    $def = ['d','p','t','w','s','m','o'];
    return apply_filters('ptsb_default_ui_codes', $def);
}

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

