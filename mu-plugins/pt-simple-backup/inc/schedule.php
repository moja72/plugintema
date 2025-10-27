<?php
if (!defined('ABSPATH')) { exit; }

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

/* -------------------------------------------------------
 * Fila manual (disparo via painel -> cron)
 * -----------------------------------------------------*/

function ptsb_manual_job_default(): array {
    return [
        'id'            => '',
        'status'        => 'idle',
        'message'       => '',
        'created_at'    => 0,
        'scheduled_at'  => 0,
        'started_at'    => 0,
        'finished_at'   => 0,
        'attempts'      => 0,
        'payload'       => [],
    ];
}

function ptsb_manual_job_get(): array {
    $job = get_option('ptsb_manual_job', []);
    if (!is_array($job)) { $job = []; }
    $job = wp_parse_args($job, ptsb_manual_job_default());
    if (!is_array($job['payload'])) { $job['payload'] = []; }
    $job['status']  = (string)($job['status'] ?? 'idle');
    $job['message'] = (string)($job['message'] ?? '');
    return $job;
}

function ptsb_manual_job_save(array $job): void {
    if (!is_array($job['payload'] ?? null)) {
        $job['payload'] = [];
    }
    update_option('ptsb_manual_job', $job, false);
}

function ptsb_manual_job_is_active(array $job): bool {
    $status = (string)($job['status'] ?? 'idle');
    return in_array($status, ['pending','waiting_lock','running'], true);
}

function ptsb_manual_job_message(array $job): string {
    $msg = (string)($job['message'] ?? '');
    if ($msg !== '') {
        return $msg;
    }
    $status = (string)($job['status'] ?? 'idle');
    if ($status === 'idle') {
        return 'Nenhum backup manual agendado.';
    }
    if ($status === 'completed') {
        return 'Backup manual concluído.';
    }
    if ($status === 'failed') {
        return 'Falha ao iniciar o backup manual.';
    }
    return '';
}

function ptsb_manual_job_response_payload(): array {
    $job = ptsb_manual_job_get();
    return [
        'id'          => (string)$job['id'],
        'status'      => (string)$job['status'],
        'message'     => ptsb_manual_job_message($job),
        'scheduled_at'=> (int)$job['scheduled_at'],
        'started_at'  => (int)$job['started_at'],
        'finished_at' => (int)$job['finished_at'],
        'attempts'    => (int)$job['attempts'],
    ];
}

function ptsb_manual_job_mark_completed(array $payload): void {
    $origin = isset($payload['origin']) ? (string)$payload['origin'] : '';
    if ($origin !== 'manual') {
        return;
    }

    $job = ptsb_manual_job_get();
    if ($job['id'] === '') {
        return;
    }

    $intent = get_option('ptsb_last_run_intent', []);
    $intent_id = '';
    if (is_array($intent)) {
        $intent_id = (string)($intent['job_id'] ?? '');
    }
    $payload_id = isset($payload['job_id']) ? (string)$payload['job_id'] : '';
    if ($payload_id !== '' && $payload_id !== (string)$job['id']) {
        return;
    }
    if ($intent_id && $intent_id !== (string)$job['id']) {
        return;
    }

    $status = (string)$job['status'];
    if (!in_array($status, ['running','waiting_lock','pending'], true)) {
        return;
    }

    $finished_at = time();
    $finished_iso = isset($payload['finished_at_iso']) ? (string)$payload['finished_at_iso'] : '';
    if ($finished_iso !== '') {
        try {
            $dt = new DateTimeImmutable($finished_iso);
            $finished_at = $dt->getTimestamp();
        } catch (Throwable $e) {
            // fallback silencioso
        }
    }

    $msg_local = isset($payload['finished_at_local']) ? (string)$payload['finished_at_local'] : '';
    $msg = $msg_local !== ''
        ? sprintf('Backup manual concluído em %s.', $msg_local)
        : 'Backup manual concluído.';

    $job['status']      = 'completed';
    $job['message']     = $msg;
    $job['finished_at'] = (int)$finished_at;
    ptsb_manual_job_save($job);
}

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


add_action('ptsb_run_manual_backup', function($job_id){
    $job = ptsb_manual_job_get();
    if ($job['id'] === '' || (string)$job_id !== (string)$job['id']) {
        return;
    }

    $status = (string)$job['status'];
    if (!in_array($status, ['pending','waiting_lock'], true)) {
        return;
    }

    $cfg = ptsb_cfg();

    if (!ptsb_can_shell()) {
        $job['status']      = 'failed';
        $job['message']     = 'Não foi possível iniciar o backup (shell_exec indisponível).';
        $job['finished_at'] = time();
        ptsb_manual_job_save($job);
        return;
    }

    $lockActive = ptsb_lock_is_active();
    $fileLock   = file_exists($cfg['lock']);

    if ($lockActive || $fileLock) {
        $job['status']   = 'waiting_lock';
        $job['message']  = 'Aguardando outro backup finalizar para iniciar.';
        $job['attempts'] = (int)$job['attempts'] + 1;
        ptsb_manual_job_save($job);
        wp_schedule_single_event(time()+30, 'ptsb_run_manual_backup', [(string)$job['id']]);
        return;
    }

    $payload        = is_array($job['payload']) ? $job['payload'] : [];
    $partsCsv       = $payload['parts_csv'] ?? null;
    $overridePrefix = $payload['prefix'] ?? null;
    $keepDays       = $payload['keep_days'] ?? null;
    $keepForever    = !empty($payload['keep_forever']);
    $effectivePref  = $payload['effective_prefix'] ?? ($overridePrefix ?: ptsb_cfg()['prefix']);

    if ($keepForever) {
        ptsb_plan_mark_keep_next($effectivePref);
    }

    $job['status']     = 'running';
    $job['message']    = 'Backup em execução. Acompanhe o progresso abaixo.';
    $job['started_at'] = time();
    $job['attempts']   = (int)$job['attempts'] + 1;
    ptsb_manual_job_save($job);

    $intent = [
        'prefix'       => $effectivePref,
        'keep_days'    => ($keepDays === null) ? (int)ptsb_settings()['keep_days'] : (int)$keepDays,
        'keep_forever' => $keepForever ? 1 : 0,
        'origin'       => 'manual',
        'started_at'   => time(),
        'job_id'       => (string)$job['id'],
    ];
    update_option('ptsb_last_run_intent', $intent, true);

    ptsb_start_backup($partsCsv, $overridePrefix, $keepDays);
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
    $running = ptsb_lock_is_active();
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
    $lock = ptsb_lock_try_acquire();
    if (!$lock) { return; }
    $token = (string)($lock['token'] ?? '');

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

    $transferOpts = ptsb_rclone_flags_string(['category' => 'transfer', 'delta' => true]);
    if ($transferOpts !== '') {
        $env .= ' RCLONE_BASE_OPTS=' . escapeshellarg($transferOpts);
    }

    $listOpts = ptsb_rclone_flags_string(['category' => 'list', 'fast_list' => true]);
    if ($listOpts !== '') {
        $env .= ' RCLONE_LIST_OPTS=' . escapeshellarg($listOpts);
    }

    $mutateOpts = ptsb_rclone_flags_string(['category' => 'mutate']);
    if ($mutateOpts !== '') {
        $env .= ' RCLONE_MUTATE_OPTS=' . escapeshellarg($mutateOpts);
    }

    // guarda as partes usadas neste disparo (fallback para a notificação)
    update_option('ptsb_last_run_parts', (string)$partsCsv, true);

    $cmd = 'nice -n 10 ionice -c2 -n7 /usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_backup'])
         . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';

    $result = shell_exec($cmd);
    $pid    = 0;
    if (is_string($result)) {
        $trim = trim($result);
        if ($trim !== '' && ctype_digit($trim)) {
            $pid = (int) $trim;
        } elseif (preg_match('/(\d+)/', $trim, $m)) {
            $pid = (int) $m[1];
        }
    }

    if ($pid > 0 && $token !== '') {
        ptsb_lock_touch($token, ['pid' => $pid]);
    } else {
        ptsb_lock_release($token !== '' ? $token : null);
    }
}

/** Inicia backup com PARTS customizadas (bypass do ptsb_start_backup padrão) */

function ptsb_start_backup_with_parts(string $partsCsv): void {
    $cfg = ptsb_cfg();
    $set = ptsb_settings();
    if (!ptsb_can_shell()) return;
    $lock = ptsb_lock_try_acquire();
    if (!$lock) return;
    $token = (string)($lock['token'] ?? '');

    $env = 'PATH=/usr/local/bin:/usr/bin:/bin LC_ALL=C.UTF-8 LANG=C.UTF-8 '
         . 'REMOTE='     . escapeshellarg($cfg['remote'])     . ' '
         . 'WP_PATH='    . escapeshellarg(ABSPATH)            . ' '
         . 'PREFIX='     . escapeshellarg($cfg['prefix'])     . ' '
         . 'KEEP_DAYS='  . escapeshellarg($set['keep_days'])  . ' '
         . 'KEEP='       . escapeshellarg($set['keep_days']) . ' '
         . 'PARTS='      . escapeshellarg($partsCsv);

    $transferOpts = ptsb_rclone_flags_string(['category' => 'transfer', 'delta' => true]);
    if ($transferOpts !== '') {
        $env .= ' RCLONE_BASE_OPTS=' . escapeshellarg($transferOpts);
    }

    $listOpts = ptsb_rclone_flags_string(['category' => 'list', 'fast_list' => true]);
    if ($listOpts !== '') {
        $env .= ' RCLONE_LIST_OPTS=' . escapeshellarg($listOpts);
    }

    $mutateOpts = ptsb_rclone_flags_string(['category' => 'mutate']);
    if ($mutateOpts !== '') {
        $env .= ' RCLONE_MUTATE_OPTS=' . escapeshellarg($mutateOpts);
    }

    $cmd = 'nice -n 10 ionice -c2 -n7 /usr/bin/nohup /usr/bin/env ' . $env . ' ' . escapeshellarg($cfg['script_backup'])
         . ' >> ' . escapeshellarg($cfg['log']) . ' 2>&1 & echo $!';
    $result = shell_exec($cmd);
    $pid    = 0;
    if (is_string($result)) {
        $trim = trim($result);
        if ($trim !== '' && ctype_digit($trim)) {
            $pid = (int) $trim;
        } elseif (preg_match('/(\d+)/', $trim, $m)) {
            $pid = (int) $m[1];
        }
    }

    if ($pid > 0 && $token !== '') {
        ptsb_lock_touch($token, ['pid' => $pid]);
    } else {
        ptsb_lock_release($token !== '' ? $token : null);
    }
}

// checar notificação no admin e também no cron do plugin
add_action('admin_init', 'ptsb_maybe_notify_backup_done');
add_action('ptsb_cron_tick', 'ptsb_maybe_notify_backup_done');

