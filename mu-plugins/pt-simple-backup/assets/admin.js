<?php
return [
    'script_1' => <<<'JS'
(function(){
  var f=document.getElementById('ptsb-per-form'); if(!f) return;
  var i=f.querySelector('input[name="per"]');
  i.addEventListener('change', function(){ f.submit(); });
})();
JS,
    'script_2' => <<<'JS'
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
JS,
    'script_3' => <<<'JS'
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
JS,
    'script_4' => <<<'JS'
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
        
JS,
    'script_5' => <<<'JS'
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
JS,
    'script_6' => <<<'JS'
(function(form){
  if(!form) return;
  var cb   = form.querySelector('input[name="keep_forever"]');
  var days = form.querySelector('input[name="keep_days"]');
  if(!cb || !days) return;
  function sync(){ days.disabled = cb.checked; days.style.opacity = cb.checked ? .5 : 1; }
  cb.addEventListener('change', sync); sync();
})(document.currentScript.closest('form'));
JS,
    'script_7' => <<<'JS'
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
JS,
    'script_8' => <<<'JS'
    (function(sel){ if(!sel) return; sel.dispatchEvent(new Event('change')); })
    (document.currentScript.previousElementSibling);
  
JS,
    'script_9' => <<<'JS'
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

  
JS,
    'script_10' => <<<'JS'
(function(wrap){
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
  })(document.getElementById('wk_new'));
JS,
    'script_11' => <<<'JS'
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

  
JS,
    'script_12' => <<<'JS'
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

  
JS,
    'script_13' => <<<'JS'
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
JS,
    'script_14' => <<<'JS'
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
JS,
    'script_15' => <<<'JS'
          (function(){
            var f1=document.getElementById('ptsb-next-date-form');
            if(f1){ var d=f1.querySelector('input[name="next_date"]'); d&&d.addEventListener('change', function(){ f1.submit(); }); }
            var f2=document.getElementById('ptsb-next-per-form');
            if(f2){ var i=f2.querySelector('input[name="per_next"]'); i&&i.addEventListener('change', function(){ f2.submit(); }); }
          })();
          
JS,
    'script_16' => <<<'JS'
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
          
JS,
    'script_17' => <<<'JS'
(function(){
  var f=document.getElementById('ptsb-last-filter-form');
  if(f){ f.addEventListener('change', function(){ f.submit(); }); }

  var g=document.getElementById('ptsb-last-per-form');
  if(g){
    var i=g.querySelector('input[name="per_last"]');
    if(i){ i.addEventListener('change', function(){ g.submit(); }); }
  }
})();
JS,
    'script_18' => <<<'JS'
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
      
JS,
    'script_19' => <<<'JS'
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
  
JS,
];
