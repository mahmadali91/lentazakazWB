/* Лента заказов WB — клиентская часть (без зависимостей). */
(function () {
  'use strict';

  var store = {
    get: function (k, d) { try { var v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };

  // ---------- Тема ----------
  var themeBtn = document.getElementById('themeBtn');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var cur = document.documentElement.getAttribute('data-theme');
      if (!cur) cur = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      var next = cur === 'dark' ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      store.set('lz-theme', next);
    });
  }

  // ---------- Тост ----------
  var toastEl = document.getElementById('toast');
  var toastTimer;
  function toast(text) {
    if (!toastEl) return;
    toastEl.textContent = text;
    toastEl.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 3500);
  }
  window.lzToast = toast;

  // Подтверждение опасных действий
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) e.preventDefault();
  });

  // Копирование в буфер
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-copy]');
    if (!el) return;
    e.preventDefault();
    var text = el.getAttribute('data-copy');
    if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { toast('Скопировано'); });
  });

  var cfg = window.LZ_FEED;
  if (!cfg || !cfg.enabled) return;

  // ---------- Лента ----------
  var f = cfg.filters;
  var since = null;
  var orders = {};          // id -> order
  var lastOrder = null;
  var lastSync = null;
  var pollTimer = null;
  var loading = false;
  var soundOn = store.get('lz-sound', '1') === '1';

  var $ = function (id) { return document.getElementById(id); };
  var listEl = $('orderList');
  var multiStore = !!$('storeSel');

  function esc(s) {
    return String(s === null || s === undefined ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function money(v) {
    if (v === null || v === undefined) return '—';
    return Math.round(v).toLocaleString('ru-RU') + ' ₽';
  }
  // Даты с сервера приходят в ISO 8601 с часовым поясом (created_iso, status_iso, sync.last)
  function parseDate(s) { return s ? new Date(s.replace(' ', 'T')) : null; }
  function fmtFull(s) {
    var d = parseDate(s);
    return d ? d.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  }
  function ago(s) {
    var d = parseDate(s);
    if (!d) return '';
    var sec = Math.max(0, (Date.now() - d.getTime()) / 1000);
    if (sec < 60) return 'только что';
    if (sec < 3600) return Math.floor(sec / 60) + ' мин назад';
    var today = new Date(); today.setHours(0, 0, 0, 0);
    var time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    if (d >= today) return 'сегодня ' + time;
    var y = new Date(today.getTime() - 86400000);
    if (d >= y) return 'вчера ' + time;
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' }) + ' ' + time;
  }
  function query(extra) {
    var p = new URLSearchParams();
    p.set('period', f.period);
    if (f.period === 'custom') { p.set('from', $('dateFrom').value); p.set('to', $('dateTo').value); }
    if (f.status) p.set('status', f.status);
    if (f.scheme) p.set('scheme', f.scheme);
    if (f.store) p.set('store', f.store);
    if (f.q) p.set('q', f.q);
    if (extra) Object.keys(extra).forEach(function (k) { p.set(k, extra[k]); });
    return p.toString();
  }
  function api(path, extra) {
    return fetch(path + '?' + query(extra), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        if (r.status === 401) { location.href = '/login'; throw new Error('auth'); }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
  }

  var CANCEL = { app: 'до получения', receipt: 'при получении', expire: 'не забран', other: 'иное' };

  function renderOrder(o) {
    var photo = o.photo
      ? '<img src="' + esc(o.photo) + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
      : '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
    var meta = [];
    if (o.article) meta.push('арт. ' + esc(o.article));
    if (o.nm_id) meta.push('<a href="https://www.wildberries.ru/catalog/' + o.nm_id + '/detail.aspx" target="_blank" rel="noopener">WB ' + o.nm_id + '</a>');
    if (o.size && o.size !== '0') meta.push('размер ' + esc(o.size));
    if (multiStore && !f.store && o.store) meta.push(esc(o.store));
    var route = [];
    if (o.warehouse) route.push(esc(o.warehouse));
    if (o.city) route.push(esc(o.city));
    var badges = '<span class="badge badge-' + esc(o.status) + '">' + esc(o.status_label) +
      (o.status === 'cancel' && o.cancel_type ? ' · ' + esc(CANCEL[o.cancel_type] || o.cancel_type) : '') + '</span>';
    if (o.scheme) badges += '<span class="badge badge-scheme">' + esc(o.scheme_label) + '</span>';
    if (o.is_b2b) badges += '<span class="badge badge-accent">B2B</span>';
    if (o.mp_order_id) badges += '<span class="badge" title="Сборочное задание">№ ' + o.mp_order_id + '</span>';
    var priceTitle = o.finished_price ? 'Покупатель заплатил ≈ ' + money(o.finished_price) + (o.spp ? ' (СПП ' + o.spp + '%)' : '') : 'Цена продавца со скидкой';
    var el = document.createElement('div');
    el.className = 'order';
    el.setAttribute('data-id', o.id);
    el.innerHTML =
      '<div class="thumb">' + photo + '</div>' +
      '<div style="min-width:0">' +
        '<div class="o-title" title="' + esc(o.title) + '">' + esc(o.title) + '</div>' +
        '<div class="o-meta">' + meta.map(function (m) { return '<span>' + m + '</span>'; }).join('') +
          (route.length ? '<span>' + route.join(' → ') + '</span>' : '') + '</div>' +
        '<div class="o-badges">' + badges + '</div>' +
      '</div>' +
      '<div class="o-right"><div class="o-price" title="' + esc(priceTitle) + '">' + money(o.price) + '</div>' +
        (o.profit !== null && o.profit !== undefined
          ? '<div class="o-profit ' + (o.profit < 0 ? 'neg' : '') + '" title="Прибыль (оценка), себестоимость ' + money(o.cost) + '">' +
            (o.profit < 0 ? '−' : '+') + money(Math.abs(o.profit)) + '</div>'
          : '') +
        '<div class="o-time" data-time="' + esc(o.created_iso) + '" title="Заказ: ' + esc(fmtFull(o.created_iso)) +
        (o.status_iso && o.status_at !== o.created_at ? '\n' + esc(o.status_label) + ': ' + esc(fmtFull(o.status_iso)) : '') + '">' + ago(o.created_iso) + '</div></div>';
    return el;
  }

  function emptyState() {
    var hint = f.q || f.status || f.scheme ? 'Попробуйте изменить фильтры.' : 'Новые заказы появятся здесь автоматически.';
    listEl.innerHTML = '<div class="empty"><div class="ico"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/></svg></div><h3>Заказов пока нет</h3><p>' + hint + '</p></div>';
  }

  function renderKpi(k) {
    if (!k) return;
    var set = function (key, val) { var el = document.querySelector('[data-k="' + key + '"]'); if (el) el.textContent = val; };
    set('orders', k.orders.toLocaleString('ru-RU'));
    set('orders_sum', money(k.orders_sum));
    set('buyout', k.buyout.toLocaleString('ru-RU'));
    set('buyout_sum', money(k.buyout_sum));
    set('cancel', k.cancel.toLocaleString('ru-RU'));
    set('cancel_pct', k.orders ? Math.round(k.cancel * 100 / k.orders) + '% от заказов' : ' ');
    set('returns', k.returns.toLocaleString('ru-RU'));
    set('avg', money(k.avg));
    if (k.profit) {
      var p = k.profit, el = document.querySelector('[data-k="profit"]');
      if (el) {
        el.textContent = (p.orders < 0 ? '−' : '') + money(Math.abs(p.orders));
        el.style.color = p.orders < 0 ? 'var(--bad)' : '';
      }
      set('profit_sub', p.no_cost_items
        ? 'нет себестоимости у ' + p.no_cost_items + ' тов. →'
        : 'по выкупам ' + money(p.buyout));
    }
  }

  function renderChart(c) {
    var svg = $('chart');
    if (!svg || !c) return;
    $('chartTitle').textContent = c.hourly ? 'Заказы по часам' : 'Заказы по дням';
    var pts = c.points || [];
    var W = 300, H = 150, pad = 18;
    var max = Math.max.apply(null, pts.map(function (p) { return p.count; }).concat([1]));
    var bw = pts.length ? (W - 4) / pts.length : W;
    var html = '';
    pts.forEach(function (p, i) {
      var h = Math.round((H - pad - 6) * p.count / max);
      var x = 2 + i * bw;
      html += '<rect class="bar" x="' + (x + bw * 0.15).toFixed(1) + '" y="' + (H - pad - h) + '" width="' + Math.max(1, bw * 0.7).toFixed(1) +
        '" height="' + Math.max(h, p.count ? 2 : 0) + '" rx="2"><title>' + esc(p.label) + ': ' + p.count + ' шт · ' + money(p.sum) + '</title></rect>';
      var every = Math.ceil(pts.length / 6);
      if (i % every === 0) html += '<text x="' + (i === 0 ? x : x + bw / 2).toFixed(1) + '" y="' + (H - 4) + '" text-anchor="' + (i === 0 ? 'start' : 'middle') + '">' + esc(p.label) + '</text>';
    });
    svg.innerHTML = html;
  }

  function renderTop(top) {
    var el = $('top');
    if (!el) return;
    if (!top || !top.length) { el.innerHTML = '<div class="muted small">Нет данных</div>'; return; }
    el.innerHTML = top.map(function (t) {
      var img = t.photo ? '<img src="' + esc(t.photo) + '" alt="" style="width:34px;height:44px;object-fit:cover;border-radius:6px" loading="lazy" referrerpolicy="no-referrer">'
        : '<div style="width:34px;height:44px;border-radius:6px;background:var(--surface-2)"></div>';
      return '<div class="top-item">' + img + '<div style="min-width:0;flex:1"><div class="t-title">' + esc(t.title || ('WB ' + t.nm_id)) +
        '</div><div class="muted small">' + esc(t.article || t.nm_id) + '</div></div><div style="text-align:right"><b>' + t.cnt +
        ' шт</b><div class="muted small">' + money(t.total) + '</div></div></div>';
    }).join('');
  }

  function renderSync(s) {
    if (!s) return;
    lastSync = s.last;
    var box = $('syncAlerts');
    var html = '';
    if (s.loading) html += '<div class="alert alert-info">Идёт первичная загрузка заказов из WB. Из-за лимитов WB это может занять несколько минут — лента обновится сама.</div>';
    (s.errors || []).forEach(function (e) { html += '<div class="alert alert-error">' + esc(e) + ' <a href="/app/stores">Магазины →</a></div>'; });
    box.innerHTML = html;
    updateLive();
  }

  function updateLive() {
    var el = $('liveText'), live = $('live');
    if (!lastSync) { el.textContent = 'Ожидаем первую синхронизацию'; live.classList.add('off'); return; }
    var d = parseDate(lastSync);
    var min = Math.floor((Date.now() - d.getTime()) / 60000);
    el.textContent = 'WB: обновлено ' + (min < 1 ? 'только что' : min + ' мин назад');
    live.classList.toggle('off', min > Math.max(5, cfg.syncMin * 3));
  }

  function load() {
    loading = true;
    api('/api/feed').then(function (data) {
      orders = {};
      listEl.innerHTML = '';
      data.orders.forEach(function (o) { orders[o.id] = o; listEl.appendChild(renderOrder(o)); });
      if (!data.orders.length) emptyState();
      lastOrder = data.orders[data.orders.length - 1] || null;
      $('moreWrap').classList.toggle('hidden', !data.has_more);
      renderKpi(data.kpi); renderChart(data.chart); renderTop(data.top); renderSync(data.sync);
      since = data.server_time;
      syncUrl();
    }).catch(function () { toast('Не удалось загрузить заказы'); })
      .then(function () { loading = false; schedule(); });
  }

  function loadMore() {
    if (!lastOrder) return;
    var btn = $('moreBtn');
    btn.disabled = true;
    api('/api/feed', { before_id: lastOrder.id, before_date: lastOrder.created_at }).then(function (data) {
      data.orders.forEach(function (o) {
        if (orders[o.id]) return;
        orders[o.id] = o; listEl.appendChild(renderOrder(o));
      });
      lastOrder = data.orders[data.orders.length - 1] || lastOrder;
      $('moreWrap').classList.toggle('hidden', !data.has_more);
    }).catch(function () { toast('Ошибка загрузки'); }).then(function () { btn.disabled = false; });
  }

  function poll() {
    if (loading || !since || document.hidden) { schedule(); return; }
    api('/api/feed/updates', { since: since }).then(function (data) {
      since = data.server_time;
      var fresh = 0;
      data.orders.slice().reverse().forEach(function (o) {
        var existing = listEl.querySelector('[data-id="' + o.id + '"]');
        var el = renderOrder(o);
        if (existing) {
          if (orders[o.id] && orders[o.id].status !== o.status) el.classList.add('fresh');
          existing.replaceWith(el);
        } else {
          var empty = listEl.querySelector('.empty');
          if (empty) listEl.innerHTML = '';
          // вставляем по дате заказа
          var placed = false;
          var nodes = listEl.querySelectorAll('.order');
          for (var i = 0; i < nodes.length; i++) {
            var other = orders[nodes[i].getAttribute('data-id')];
            if (other && other.created_at <= o.created_at) { listEl.insertBefore(el, nodes[i]); placed = true; break; }
          }
          if (!placed) {
            if (lastOrder && o.created_at < lastOrder.created_at) return; // старый заказ — появится при «Показать ещё»
            listEl.appendChild(el);
          }
          el.classList.add('fresh');
          if (o.status === 'created') fresh++;
        }
        orders[o.id] = o;
        setTimeout(function () { el.classList.remove('fresh'); }, 6000);
      });
      if (data.kpi) renderKpi(data.kpi);
      renderSync(data.sync);
      if (fresh) notifyNew(fresh, data.orders[0]);
    }).catch(function () {}).then(schedule);
  }

  function schedule() {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, 20000);
  }

  // Звук и браузерные уведомления
  var audioCtx;
  function beep() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      [880, 1320].forEach(function (freq, i) {
        var o = audioCtx.createOscillator(), g = audioCtx.createGain();
        o.type = 'sine'; o.frequency.value = freq;
        g.gain.setValueAtTime(0.0001, audioCtx.currentTime + i * 0.16);
        g.gain.exponentialRampToValueAtTime(0.25, audioCtx.currentTime + i * 0.16 + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + i * 0.16 + 0.15);
        o.connect(g); g.connect(audioCtx.destination);
        o.start(audioCtx.currentTime + i * 0.16); o.stop(audioCtx.currentTime + i * 0.16 + 0.16);
      });
    } catch (e) {}
  }
  function notifyNew(n, o) {
    var text = n === 1 ? 'Новый заказ: ' + (o ? o.title + ' · ' + money(o.price) : '') : 'Новых заказов: ' + n;
    toast(text);
    if (soundOn) beep();
    if (soundOn && 'Notification' in window && Notification.permission === 'granted' && document.hidden) {
      try { new Notification('Лента заказов WB', { body: text, icon: '/assets/img/favicon.svg' }); } catch (e) {}
    }
    document.title = '(' + n + ') ' + document.title.replace(/^\(\d+\)\s*/, '');
  }
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { document.title = document.title.replace(/^\(\d+\)\s*/, ''); poll(); }
  });

  var soundBtn = $('soundBtn');
  var BELL = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>';
  var BELL_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13.7 21a2 2 0 0 1-3.4 0"/><path d="M18.6 13A17.9 17.9 0 0 1 18 8"/><path d="M6.3 6.3A6 6 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.3-5"/><path d="M1 1l22 22"/></svg>';
  function paintSound() { soundBtn.innerHTML = soundOn ? BELL : BELL_OFF; soundBtn.title = soundOn ? 'Звук включён' : 'Звук выключен'; }
  paintSound();
  soundBtn.addEventListener('click', function () {
    soundOn = !soundOn;
    store.set('lz-sound', soundOn ? '1' : '0');
    paintSound();
    if (soundOn) {
      beep();
      if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
    }
    toast(soundOn ? 'Звук и уведомления включены' : 'Звук выключен');
  });

  // Фильтры
  function syncUrl() {
    var qs = query();
    history.replaceState(null, '', '/app?' + qs);
    var exp = $('exportBtn');
    if (exp) exp.href = '/app/export?' + qs;
  }
  document.querySelectorAll('.seg[data-filter]').forEach(function (seg) {
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) return;
      seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      f[seg.getAttribute('data-filter')] = b.getAttribute('data-value');
      $('customDates').classList.toggle('on', f.period === 'custom');
      load();
    });
  });
  ['dateFrom', 'dateTo'].forEach(function (id) { var el = $(id); if (el) el.addEventListener('change', load); });
  var storeSel = $('storeSel');
  if (storeSel) storeSel.addEventListener('change', function () { f.store = parseInt(storeSel.value, 10) || 0; load(); });
  $('schemeSel').addEventListener('change', function () { f.scheme = this.value; load(); });
  var qTimer;
  $('q').addEventListener('input', function () {
    var v = this.value.trim();
    clearTimeout(qTimer);
    qTimer = setTimeout(function () { f.q = v; load(); }, 400);
  });
  $('moreBtn').addEventListener('click', loadMore);

  // Обновляем «N мин назад» раз в 30 секунд
  setInterval(function () {
    listEl.querySelectorAll('[data-time]').forEach(function (el) { el.textContent = ago(el.getAttribute('data-time')); });
    updateLive();
  }, 30000);

  load();
})();
