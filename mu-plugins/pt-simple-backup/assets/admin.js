(function (window, document) {
  'use strict';

  const data = window.ptsbAdminData || {};
  const ajaxUrl = data.ajaxUrl || window.ajaxurl || '';
  const nonce = data.nonce || '';
  const prefix = data.prefix || '';
  const defaults = data.defaults || {};
  const defaultLetters = Array.isArray(defaults.letters) ? defaults.letters : ['D', 'P', 'T', 'W', 'S', 'M', 'O'];
  const urls = data.urls || {};
  const perPage = data.perPage || {};
  const filters = data.filters || {};

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  function collectFiles() {
    return Array.from(document.querySelectorAll('table.widefat tbody tr[data-file]'))
      .map(tr => tr.getAttribute('data-file'));
  }

  function letterIcon(letter) {
    const map = {
      D: 'dashicons-database',
      P: 'dashicons-admin-plugins',
      T: 'dashicons-admin-appearance',
      W: 'dashicons-wordpress-alt',
      S: 'dashicons-editor-code',
      M: 'dashicons-admin-media',
      O: 'dashicons-image-filter'
    };
    const cls = map[letter] || 'dashicons-marker';
    return '<span class="ptsb-mini" title="' + letter + '"><span class="dashicons ' + cls + '"></span></span>';
  }

  function renderRetentionCell(tr, keepDays) {
    const kept = tr.getAttribute('data-kept') === '1';
    const td = tr.querySelector('.ptsb-col-ret');
    if (!td) return;

    if (kept || keepDays === 0) {
      td.innerHTML = '<span class="ptsb-ret sempre" title="Sempre manter">sempre</span>';
      tr.classList.remove('ptsb-expired');
      return;
    }

    if (keepDays === null || Number.isNaN(keepDays)) {
      td.textContent = '—';
      tr.classList.remove('ptsb-expired');
      return;
    }

    const iso = tr.getAttribute('data-time');
    const created = iso ? new Date(iso) : null;
    const now = new Date();
    let elapsedDays = 0;
    if (created instanceof Date && !Number.isNaN(created.getTime())) {
      elapsedDays = Math.max(0, Math.floor((now - created) / 86400000));
    }
    const x = Math.min(keepDays, elapsedDays + 1);
    const expired = x >= keepDays;

    td.innerHTML = '<span class="ptsb-ret" title="Dia ' + x + ' de ' + keepDays + '">' + x + '/' + keepDays + '</span>';

    tr.classList.toggle('ptsb-expired', expired);
    const nameCell = tr.querySelector('.ptsb-filename');
    if (nameCell) {
      const existingTag = nameCell.parentElement?.querySelector('.ptsb-tag.vencido');
      if (expired) {
        if (!existingTag) {
          const tag = document.createElement('span');
          tag.className = 'ptsb-tag vencido';
          tag.textContent = 'vencido';
          nameCell.insertAdjacentElement('afterend', tag);
        }
      } else if (existingTag) {
        existingTag.remove();
      }
    }
  }

  function initHydrateTable() {
    const files = collectFiles();
    if (!files.length || !ajaxUrl || !nonce) return;

    const params = new URLSearchParams();
    params.set('action', 'ptsb_details_batch');
    params.set('nonce', nonce);
    files.forEach(file => params.append('files[]', file));

    fetch(ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: params.toString()
    })
      .then(resp => resp.json())
      .then(res => {
        if (!res || !res.success || !res.data) return;
        const info = res.data;
        files.forEach(file => {
          const tr = document.querySelector('tr[data-file="' + CSS.escape(file) + '"]');
          if (!tr) return;
          const details = info[file] || {};

          const routine = tr.querySelector('.ptsb-col-rotina');
          if (routine) {
            routine.textContent = details.routine_label || '—';
          }

          const lettersCell = tr.querySelector('.ptsb-col-letters');
          if (lettersCell) {
            const letters = Array.isArray(details.parts_letters) && details.parts_letters.length
              ? details.parts_letters
              : defaultLetters;
            lettersCell.innerHTML = letters.map(letterIcon).join('');
          }

          const keepDays = details.keep_days === null || details.keep_days === undefined
            ? null
            : parseInt(details.keep_days, 10);
          renderRetentionCell(tr, Number.isNaN(keepDays) ? null : keepDays);
        });
      })
      .catch(() => {
        /* silent */
      });
  }

  function initBackupPager() {
    const input = document.getElementById('ptsb-pager-input');
    if (!input || !urls.backupPager) return;

    function go() {
      const min = parseInt(input.min, 10) || 1;
      const max = parseInt(input.max, 10) || Math.max(min, 1);
      const value = Math.max(min, Math.min(max, parseInt(input.value, 10) || min));
      window.location.href = urls.backupPager.replace('__PAGE__', value);
    }

    input.addEventListener('change', go);
    input.addEventListener('keyup', function (ev) {
      if (ev.key === 'Enter') {
        go();
      }
    });
  }

  function initManualBackupForm() {
    const form = document.getElementById('ptsb-now-form');
    const chipsBox = document.getElementById('ptsb-chips');
    if (!form || !chipsBox) return;

    const keepToggle = form.querySelector('#ptsb-man-keep-forever');
    const keepDays = form.querySelector('input[name="manual_keep_days"]');

    function syncKeepDays() {
      if (!keepToggle || !keepDays) return;
      keepDays.disabled = keepToggle.checked;
      keepDays.style.opacity = keepToggle.checked ? '0.5' : '1';
    }

    if (keepToggle && keepDays) {
      keepToggle.addEventListener('change', syncKeepDays);
      syncKeepDays();
    }

    form.addEventListener('submit', function () {
      const sentinel = document.getElementById('ptsb-parts-hidden-sentinel');
      if (sentinel) sentinel.remove();
      form.querySelectorAll('input[name="parts_sel[]"]').forEach(el => el.remove());
      const checked = Array.from(chipsBox.querySelectorAll('input[type="checkbox"][data-letter]:checked'))
        .map(cb => String(cb.dataset.letter || '').toUpperCase())
        .filter(Boolean);
      const letters = checked.length ? checked : defaultLetters;
      letters.forEach(letter => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'parts_sel[]';
        input.value = letter;
        form.appendChild(input);
      });
    });
  }

  function initProgressPoll() {
    const box = document.getElementById('ptsb-progress');
    const bar = document.getElementById('ptsb-progress-bar');
    const text = document.getElementById('ptsb-progress-text');
    if (!box || !bar || !text || !ajaxUrl || !nonce) return;

    let wasRunning = false;
    let didReload = false;

    function poll() {
      const params = new URLSearchParams({ action: 'ptsb_status', nonce });
      fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
        .then(resp => resp.json())
        .then(res => {
          if (!res || !res.success) return;
          const status = res.data || {};
          if (status.running) {
            wasRunning = true;
            box.style.display = 'block';
            const pct = Math.max(5, Math.min(100, status.percent | 0));
            bar.style.width = pct + '%';
            text.textContent = pct < 100 ? (pct + '% - ' + (status.stage || 'executando…')) : '100%';
          } else {
            if (wasRunning && (status.percent | 0) >= 100 && !didReload) {
              didReload = true;
              bar.style.width = '100%';
              text.textContent = '100% - concluído';
              window.setTimeout(() => window.location.reload(), 1200);
            } else {
              box.style.display = 'none';
            }
            wasRunning = false;
          }
        })
        .catch(() => {
          /* silent */
        });
    }

    poll();
    window.setInterval(poll, 2000);
  }

  function initRenameButtons() {
    document.addEventListener('click', function (event) {
      const btn = event.target.closest('.ptsb-rename-btn');
      if (!btn) return;
      const form = btn.closest('form.ptsb-rename-form');
      if (!form) return;
      const oldFull = btn.getAttribute('data-old') || '';
      const basePrefix = prefix || '';
      let currentNick = oldFull.replace(new RegExp('^' + basePrefix), '').replace(/\.tar\.gz$/i, '');
      let nick = window.prompt('Novo apelido (apenas a parte entre "' + basePrefix + '" e ".tar.gz"):', currentNick);
      if (nick === null) return;
      nick = String(nick || '')
        .trim()
        .replace(/\.tar\.gz$/i, '')
        .replace(new RegExp('^' + basePrefix), '')
        .replace(/[^A-Za-z0-9._-]+/g, '-')
        .trim();
      if (!nick) {
        window.alert('Apelido inválido.');
        return;
      }
      const newFull = basePrefix + nick + '.tar.gz';
      if (newFull === oldFull) {
        window.alert('O nome não foi alterado.');
        return;
      }
      if (!/^[A-Za-z0-9._-]+\.tar\.gz$/.test(newFull)) {
        window.alert('Use apenas letras, números, ponto, hífen e sublinhado. A extensão deve ser .tar.gz.');
        return;
      }
      const target = form.querySelector('input[name="new_file"]');
      if (!target) return;
      target.value = newFull;
      form.submit();
    });
  }

  function initCycleLettersForm() {
    const form = document.getElementById('ptsb-add-cycle-form');
    const wrap = form ? form.querySelector('#ptsb-add-letters') : null;
    if (!form || !wrap) return;

    form.addEventListener('submit', function () {
      form.querySelectorAll('input[name="letters[]"]').forEach(el => el.remove());
      wrap.querySelectorAll('input[type="checkbox"][data-letter]:checked').forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'letters[]';
        input.value = String(cb.dataset.letter || '').toUpperCase();
        form.appendChild(input);
      });
    });
  }

  function initCycleKeepForever() {
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
      const cb = form.querySelector('input[name="keep_forever"]');
      const days = form.querySelector('input[name="keep_days"]');
      if (!cb || !days) return;
      function sync() {
        days.disabled = cb.checked;
        days.style.opacity = cb.checked ? '0.5' : '1';
      }
      cb.addEventListener('change', sync);
      sync();
    });
  }

  function initModeSections() {
    const selects = document.querySelectorAll('form select[name="mode"]');
    selects.forEach(sel => {
      const form = sel.closest('form');
      if (!form) return;
      const sections = Array.from(form.querySelectorAll('[data-new]'));
      function toggle() {
        const value = sel.value;
        sections.forEach(section => {
          const active = section.getAttribute('data-new') === value;
          section.style.display = active ? '' : 'none';
          section.querySelectorAll('input, select, textarea').forEach(el => {
            if (el === sel) return;
            el.disabled = !active && !el.hasAttribute('data-always-enabled');
          });
        });
      }
      sel.addEventListener('change', toggle);
      toggle();
    });
  }

  function setupTimesBuilder(qtyId, boxId) {
    const qty = document.getElementById(qtyId);
    const box = document.getElementById(boxId);
    if (!qty || !box) return;

    function rebuild() {
      const limit = Math.max(1, Math.min(12, parseInt(qty.value, 10) || 1));
      const oldValues = Array.from(box.querySelectorAll('input[type="time"]')).map(i => i.value);
      box.innerHTML = '';
      for (let i = 0; i < limit; i += 1) {
        const input = document.createElement('input');
        input.type = 'time';
        input.name = 'times[]';
        input.step = 60;
        input.style.width = '100%';
        if (oldValues[i]) input.value = oldValues[i];
        box.appendChild(input);
      }
      const sel = qty.closest('form')?.querySelector('select[name="mode"]');
      if (sel) sel.dispatchEvent(new Event('change'));
    }

    qty.addEventListener('input', rebuild);
    rebuild();
  }

  function initTimesBuilder() {
    setupTimesBuilder('new-daily-qty', 'new-daily-times');
    setupTimesBuilder('new-weekly-qty', 'new-weekly-times');
    setupTimesBuilder('new-everyn-qty', 'new-everyn-times');
  }

  function initWeeklyChips() {
    const wrap = document.getElementById('wk_new');
    if (!wrap) return;
    const form = wrap.closest('form');
    if (!form) return;

    function sync() {
      form.querySelectorAll('input[name="wk_days[]"]').forEach(el => el.remove());
      wrap.querySelectorAll('.ptsb-chip.active').forEach(chip => {
        const val = chip.getAttribute('data-day');
        if (val === null) return;
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'wk_days[]';
        input.value = val;
        form.appendChild(input);
      });
    }

    wrap.addEventListener('click', function (event) {
      const chip = event.target.closest('.ptsb-chip');
      if (!chip) return;
      chip.classList.toggle('active');
      sync();
    });

    sync();
  }

  function initWindowToggle() {
    const toggles = document.querySelectorAll('input[name="win_disable"]');
    toggles.forEach(cb => {
      const form = cb.closest('form');
      if (!form) return;
      const start = form.querySelector('input[name="win_start"]');
      const end = form.querySelector('input[name="win_end"]');
      function sync() {
        const disabled = cb.checked;
        [start, end].forEach(input => {
          if (!input) return;
          input.disabled = disabled;
          input.style.opacity = disabled ? '0.5' : '1';
        });
      }
      cb.addEventListener('change', sync);
      sync();
    });
  }

  function initCycleFormValidation() {
    document.addEventListener('submit', function (event) {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!form.querySelector('input[name="action"][value="ptsb_cycles"]')) return;

      const modeSel = form.querySelector('select[name="mode"]');
      if (!modeSel) return;
      const mode = modeSel.value;
      const section = form.querySelector('[data-new="' + mode + '"]') || form;
      const times = Array.from(section.querySelectorAll('input[type="time"]:not([disabled])'));

      for (const input of times) {
        input.required = true;
        if (!input.value) {
          event.preventDefault();
          input.reportValidity();
          return;
        }
      }

      if (mode === 'weekly') {
        const guard = form.querySelector('input[name="wk_days_guard"]');
        const hasDay = !!section.querySelector('.ptsb-chip.active');
        if (guard) {
          if (!hasDay) {
            guard.value = '';
            guard.removeAttribute('disabled');
            guard.setCustomValidity('Selecione pelo menos 1 dia da semana.');
            event.preventDefault();
            guard.reportValidity();
            guard.setAttribute('disabled', 'disabled');
            return;
          }
          guard.value = 'ok';
          guard.removeAttribute('disabled');
          guard.setCustomValidity('');
          guard.setAttribute('disabled', 'disabled');
        }
      }
    }, true);
  }

  function initNextForms() {
    const dateForm = document.getElementById('ptsb-next-date-form');
    if (dateForm) {
      const dateInput = dateForm.querySelector('input[name="next_date"]');
      if (dateInput) {
        dateInput.addEventListener('change', () => dateForm.submit());
      }
    }

    const perForm = document.getElementById('ptsb-next-per-form');
    if (perForm) {
      const perInput = perForm.querySelector('input[name="per_next"]');
      if (perInput) {
        perInput.addEventListener('change', () => perForm.submit());
      }
    }
  }

  function initNextPager() {
    const input = document.getElementById('ptsb-next-pager-input');
    if (!input || !urls.nextPager) return;

    function go() {
      const value = Math.max(1, parseInt(input.value, 10) || 1);
      window.location.href = urls.nextPager.replace('__PAGE__', value);
    }

    input.addEventListener('change', go);
    input.addEventListener('keyup', function (event) {
      if (event.key === 'Enter') go();
    });
  }

  function initLastForms() {
    const filterForm = document.getElementById('ptsb-last-filter-form');
    if (filterForm) {
      filterForm.addEventListener('change', () => filterForm.submit());
    }

    const perForm = document.getElementById('ptsb-last-per-form');
    if (perForm) {
      const input = perForm.querySelector('input[name="per_last"]');
      if (input) {
        input.addEventListener('change', () => perForm.submit());
      }
    }
  }

  function initLastPager() {
    const input = document.getElementById('ptsb-last-pager-input');
    if (!input || !urls.lastPager) return;

    function go() {
      const min = parseInt(input.min, 10) || 1;
      const max = parseInt(input.max, 10) || Math.max(min, 1);
      const value = Math.max(min, Math.min(max, parseInt(input.value, 10) || min));
      window.location.href = urls.lastPager.replace('__PAGE__', value);
    }

    input.addEventListener('change', go);
    input.addEventListener('keyup', function (event) {
      if (event.key === 'Enter') go();
    });
  }

  function initLogPoller() {
    const logEl = document.getElementById('ptsb-log');
    if (!logEl || !ajaxUrl || !nonce) return;

    let lastLog = logEl.textContent || '';
    let autoStick = true;

    logEl.addEventListener('scroll', function () {
      const nearBottom = (logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight) < 24;
      autoStick = nearBottom;
    });

    function renderLog(text) {
      if (text === lastLog) return;
      const shouldStick = autoStick;
      logEl.textContent = text;
      if (shouldStick) {
        window.requestAnimationFrame(() => {
          logEl.scrollTop = logEl.scrollHeight;
        });
      }
      lastLog = text;
    }

    function poll() {
      const params = new URLSearchParams({ action: 'ptsb_status', nonce });
      fetch(ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
        .then(resp => resp.json())
        .then(res => {
          if (!res || !res.success) return;
          const status = res.data || {};
          const text = status.log && String(status.log).trim() ? status.log : '(sem linhas)';
          renderLog(text);
        })
        .catch(() => {
          /* silent */
        });
    }

    poll();
    window.setInterval(poll, 2000);
  }

  ready(function () {
    initHydrateTable();
    initBackupPager();
    initManualBackupForm();
    initProgressPoll();
    initRenameButtons();
    initCycleLettersForm();
    initCycleKeepForever();
    initModeSections();
    initTimesBuilder();
    initWeeklyChips();
    initWindowToggle();
    initCycleFormValidation();
    initNextForms();
    initNextPager();
    initLastForms();
    initLastPager();
    initLogPoller();
  });
})(window, document);
