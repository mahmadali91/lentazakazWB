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

  // ---------- Общее мини-окно (используется в разделе «Товары») ----------
  function lzEsc(v) {
    return String(v === null || v === undefined ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function lzMoney(v) { return v === null || v === undefined ? '—' : Math.round(v).toLocaleString('ru-RU') + ' ₽'; }
  function lzModal(html) {
    var box = document.getElementById('lzModal');
    if (!box) {
      box = document.createElement('div');
      box.id = 'lzModal';
      box.className = 'modal';
      box.innerHTML = '<div class="modal-card" role="dialog" aria-modal="true"><button class="modal-x" type="button" aria-label="Закрыть">×</button><div class="modal-body"></div></div>';
      document.body.appendChild(box);
      box.addEventListener('click', function (e) { if (e.target === box || e.target.closest('.modal-x')) lzModalClose(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lzModalClose(); });
    }
    box.querySelector('.modal-body').innerHTML = html;
    box.querySelector('.modal-card').scrollTop = 0;
    box.classList.add('open');
    document.body.classList.add('modal-open');
  }
  function lzModalClose() {
    var box = document.getElementById('lzModal');
    if (box) box.classList.remove('open');
    document.body.classList.remove('modal-open');
  }

  // ---------- Товары: карточка → окно с расчётом и полем себестоимости ----------
  var pcfg = window.LZ_PRODUCTS;
  if (pcfg) {
    var kv = function (label, value, hint) {
      return '<div class="kv"><span>' + label + (hint ? '<small>' + hint + '</small>' : '') + '</span><b>' + value + '</b></div>';
    };
    var openProduct = function (p) {
      var c = p.calc;
      var html = '<div class="m-head"><div class="m-photo">' + (p.photo ? '<img src="' + lzEsc(p.photo) + '" alt="" referrerpolicy="no-referrer">' : '') + '</div>' +
        '<div style="min-width:0"><div class="m-title">' + lzEsc(p.title) + '</div>' +
        '<div class="small muted" style="margin-top:4px">' + (p.article ? 'арт. ' + lzEsc(p.article) + ' · ' : '') + 'WB ' + p.nm + '</div>' +
        '<div class="small" style="margin-top:6px"><a href="https://www.wildberries.ru/catalog/' + p.nm + '/detail.aspx" target="_blank" rel="noopener">Открыть на WB ↗</a></div></div></div>';
      html += '<div class="m-calc"><h4>Расчёт прибыли на 1 шт</h4>';
      if (!c) {
        html += '<p class="small muted">За 30 дней не было заказов — средняя цена неизвестна.</p>';
      } else {
        html += kv('Средняя цена продавца', lzMoney(c.price), 'за 30 дней') +
          kv('Комиссия WB ' + (Math.round(c.commission_pct * 100) / 100).toLocaleString('ru-RU') + '%', '−' + lzMoney(c.commission), lzEsc(c.commission_src)) +
          kv('Логистика', '−' + lzMoney(c.logistics), lzEsc(c.logistics_src)) +
          kv('Налог ' + c.tax_pct + '%', '−' + lzMoney(c.tax), 'из настроек') +
          (c.other ? kv('Прочие расходы', '−' + lzMoney(c.other), 'из настроек') : '') +
          kv('Себестоимость', c.cost !== null ? '−' + lzMoney(c.cost) : 'не указана', c.cost !== null ? 'ваше значение' : 'укажите ниже');
        if (c.profit !== null) {
          html += '<div class="kv total"><span>Прибыль ≈</span><b class="' + (c.profit < 0 ? 'neg' : 'pos') + '">' + (c.profit < 0 ? '−' : '+') + lzMoney(Math.abs(c.profit)) + '</b></div>';
        }
      }
      if (pcfg.canEdit) {
        var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
        html += '<form method="post" action="/app/products" class="p-edit">' +
          '<input type="hidden" name="_csrf" value="' + lzEsc(csrf) + '"><input type="hidden" name="q" value="' + lzEsc(pcfg.q) + '"><input type="hidden" name="empty" value="' + lzEsc(pcfg.empty) + '">' +
          '<div><label>Себестоимость, ₽</label><input type="text" inputmode="decimal" name="cost[' + p.key + ']" value="' + (p.cost !== null ? p.cost : '') + '" placeholder="—"></div>' +
          '<div><label>Комиссия, %</label><input type="text" inputmode="decimal" name="comm[' + p.key + ']" value="' + (p.comm_manual !== null ? p.comm_manual : '') + '" placeholder="' + p.comm_auto + ' (WB)"></div>' +
          '<button class="btn" style="grid-column:1/-1">Сохранить</button></form>';
      }
      html += '</div>';
      html += '<div class="m-grid"><div><h4>Продажи и остатки</h4>' +
        kv('Заказов за 30 дней', p.orders) +
        kv('Остаток на складах WB', p.stock !== null ? p.stock + ' шт' : '—', p.stock !== null ? 'в пути к покупателям ' + p.stock_to + ', возвраты ' + p.stock_from + (p.stock_at ? ' · ' + lzEsc(p.stock_at) : '') : 'ещё не загружен') +
        (p.scheme ? kv('Основная схема', lzEsc(p.scheme)) : '') +
        '</div><div><h4>Товар</h4>' +
        (p.subject ? kv('Предмет', lzEsc(p.subject), lzEsc(p.brand || '')) : '') +
        kv('Объём', p.volume !== null ? p.volume.toLocaleString('ru-RU') + ' л' : '—', p.volume_src === 'remains' ? 'отчёт WB об остатках' : (p.volume_src === 'card' ? 'габариты карточки ' + lzEsc(p.dims || '') + ' см' : 'неизвестен')) +
        (p.store ? kv('Магазин', lzEsc(p.store)) : '') + '</div></div>';
      lzModal(html);
    };
    document.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      var card = e.target.closest('.p-card[data-p]');
      if (!card) return;
      try { openProduct(JSON.parse(card.getAttribute('data-p'))); } catch (err) { toast('Не удалось открыть товар: ' + err.message); }
    });
  }

  // ---------- Финансы: выбор периода и калькулятор акций ----------
  document.querySelectorAll('form[data-auto] select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var custom = sel.form.querySelector('.custom-dates');
      if (sel.hasAttribute('data-custom-toggle') && sel.value === 'custom') {
        if (custom) custom.classList.add('on');
        return;
      }
      if (custom && sel.hasAttribute('data-custom-toggle')) {
        custom.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
      }
      sel.form.submit();
    });
  });

  var promo = document.getElementById('promoTable');
  if (promo) {
    var tax = parseFloat(promo.getAttribute('data-tax')) || 0;
    var vat = parseFloat(promo.getAttribute('data-vat')) || 0;
    var num = function (v) { v = parseFloat(String(v).replace(/\s/g, '').replace(',', '.')); return isNaN(v) ? 0 : v; };
    var fmt = function (v) { return Math.round(v).toLocaleString('ru-RU') + ' ₽'; };
    // Прибыль на 1 шт: цена − комиссия − логистика − налог и НДС (от цены покупателя после СПП) − реклама − себестоимость
    var profit = function (price, k) {
      var buyer = price * (1 - k.spp / 100);
      var vatSum = vat > 0 ? buyer / (1 + vat / 100) * (vat / 100) : 0;
      var taxSum = (buyer - vatSum) * tax / 100;
      return price - price * k.comm / 100 - k.log - taxSum - vatSum - price * k.drr / 100 - k.cost;
    };
    var calc = function (tr) {
      var k = {};
      tr.querySelectorAll('[data-k]').forEach(function (i) { k[i.getAttribute('data-k')] = num(i.value); });
      var pNow = profit(k.now, k), pPromo = profit(k.promo, k);
      var set = function (name, text, neg) {
        var el = tr.querySelector('[data-out="' + name + '"]');
        el.textContent = text;
        el.classList.toggle('neg', !!neg);
        el.classList.toggle('pos', !neg && name !== 'margin');
      };
      set('now', fmt(pNow), pNow < 0);
      set('promo', fmt(pPromo), pPromo < 0);
      set('margin', k.promo > 0 ? (pPromo / k.promo * 100).toFixed(1).replace('.', ',') + '%' : '—', pPromo < 0);
    };
    promo.querySelectorAll('tr[data-row]').forEach(function (tr) {
      calc(tr);
      tr.addEventListener('input', function () { calc(tr); });
    });
  }

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

  var PHOTO_PH = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
  function signed(v) { return (v < 0 ? '−' : '+') + money(Math.abs(v)); }

  function renderOrder(o) {
    // Крупное фото: для экранов с высокой плотностью берём версию 516×688
    var big = o.photo && o.photo.indexOf('c246x328') !== -1 ? o.photo.replace('c246x328', 'c516x688') : '';
    var photo = o.photo ? '<img src="' + esc(o.photo) + '"' + (big ? ' srcset="' + esc(o.photo) + ' 1x, ' + esc(big) + ' 2x"' : '') + ' alt="" loading="lazy" referrerpolicy="no-referrer">' : PHOTO_PH;
    var meta = [];
    if (o.article) meta.push('<span class="o-k">Арт.</span> ' + esc(o.article));
    if (o.nm_id) meta.push('<span class="o-k">WB</span> <a href="https://www.wildberries.ru/catalog/' + o.nm_id + '/detail.aspx" target="_blank" rel="noopener">' + o.nm_id + '</a>');
    if (o.size && o.size !== '0') meta.push('<span class="o-k">Размер</span> ' + esc(o.size));
    if (multiStore && !f.store && o.store) meta.push('<span class="o-k">Магазин</span> ' + esc(o.store));
    var route = [];
    if (o.warehouse) route.push(esc(o.warehouse));
    if (o.city) route.push(esc(o.city));
    var badges = '<span class="badge badge-' + esc(o.status) + '">' + esc(o.status_label) +
      (o.status === 'cancel' && o.cancel_type ? ' · ' + esc(CANCEL[o.cancel_type] || o.cancel_type) : '') + '</span>';
    if (o.scheme) badges += '<span class="badge badge-scheme">' + esc(o.scheme_label) + '</span>';
    if (o.is_b2b) badges += '<span class="badge badge-accent">B2B</span>';
    if (o.mp_order_id) badges += '<span class="badge" title="Сборочное задание">№ ' + o.mp_order_id + '</span>';

    // Показатели с короткими подписями
    var stats = '<div class="o-stat"><span class="o-cap">Цена продавца</span><b>' + money(o.price) + '</b></div>';
    if (o.calc) {
      if (o.profit !== null && o.profit !== undefined) {
        stats += '<div class="o-stat"><span class="o-cap">Прибыль ≈</span><b class="' + (o.profit < 0 ? 'neg' : 'pos') + '">' + signed(o.profit) + '</b></div>';
      } else {
        stats += '<div class="o-stat"><span class="o-cap">Прибыль</span><b class="muted small">нет себест.</b></div>';
      }
    }
    if (o.stock !== null && o.stock !== undefined) {
      stats += '<div class="o-stat"><span class="o-cap">Остаток WB</span><b>' + o.stock + ' шт</b></div>';
    }
    var el = document.createElement('div');
    el.className = 'order';
    el.setAttribute('data-id', o.id);
    el.setAttribute('tabindex', '0');
    el.setAttribute('role', 'button');
    el.innerHTML =
      '<div class="thumb">' + photo + '</div>' +
      '<div class="o-info">' +
        '<div class="o-top"><div class="o-badges">' + badges + '</div>' +
          '<div class="o-time" data-time="' + esc(o.created_iso) + '" title="Заказ: ' + esc(fmtFull(o.created_iso)) + '">' + ago(o.created_iso) + '</div></div>' +
        '<div class="o-title" title="' + esc(o.title) + '">' + esc(o.title) + '</div>' +
        '<div class="o-meta">' + meta.map(function (m) { return '<span>' + m + '</span>'; }).join('') + '</div>' +
        (route.length ? '<div class="o-meta o-route"><span>' + route.join(' → ') + '</span></div>' : '') +
        '<div class="o-stats">' + stats + '</div>' +
      '</div>';
    return el;
  }

  // ---------- Мини-окно заказа с расчётом прибыли ----------
  function row(label, value, hint) {
    return '<div class="kv"><span>' + label + (hint ? '<small>' + hint + '</small>' : '') + '</span><b>' + value + '</b></div>';
  }
  function fmtNum(v, d) { return (Math.round(v * Math.pow(10, d || 0)) / Math.pow(10, d || 0)).toLocaleString('ru-RU'); }

  function openOrder(o) {
    var c = o.calc;
    var head = '<div class="m-head"><div class="m-photo">' + (o.photo ? '<img src="' + esc(o.photo) + '" alt="" referrerpolicy="no-referrer">' : PHOTO_PH) + '</div>' +
      '<div style="min-width:0"><div class="m-title">' + esc(o.title) + '</div>' +
      '<div class="o-badges">' + '<span class="badge badge-' + esc(o.status) + '">' + esc(o.status_label) + (o.status === 'cancel' && o.cancel_type ? ' · ' + esc(CANCEL[o.cancel_type] || o.cancel_type) : '') + '</span>' +
      (o.scheme ? '<span class="badge badge-scheme">' + esc(o.scheme_label) + '</span>' : '') + (o.is_b2b ? '<span class="badge badge-accent">B2B</span>' : '') + '</div>' +
      '<div class="small muted" style="margin-top:6px">' + (o.nm_id ? '<a href="https://www.wildberries.ru/catalog/' + o.nm_id + '/detail.aspx" target="_blank" rel="noopener">Открыть на WB ↗</a>' : '') + '</div></div></div>';

    var order = '<h4>Заказ</h4>' +
      row('Оформлен', esc(fmtFull(o.created_iso))) +
      (o.status !== 'created' && o.status_iso ? row(esc(o.status_label), esc(fmtFull(o.status_iso))) : '') +
      row('Схема', esc(o.scheme_label || '—')) +
      row('Склад → город', esc([o.warehouse, o.city].filter(Boolean).join(' → ') || '—'), o.district ? esc(o.district) : '') +
      (o.mp_order_id ? row('Сборочное задание', '№ ' + o.mp_order_id) : '') +
      row('ID заказа', '<span class="mono">' + esc(o.srid) + '</span>') +
      (multiStore ? row('Магазин', esc(o.store)) : '');

    var goods = '<h4>Товар</h4>' +
      row('Артикул продавца', esc(o.article || '—')) +
      row('Артикул WB', o.nm_id || '—') +
      (o.barcode ? row('Баркод', esc(o.barcode)) : '') +
      (o.size && o.size !== '0' ? row('Размер', esc(o.size)) : '') +
      (o.subject ? row('Предмет', esc(o.subject), o.brand ? esc(o.brand) : '') : '') +
      row('Объём', o.volume ? fmtNum(o.volume, 3) + ' л' : '—', o.volume_src === 'remains' ? 'отчёт WB об остатках' : (o.volume_src === 'card' ? 'габариты карточки ' + esc(o.dims || '') + ' см' : 'ещё не загружен')) +
      row('Остаток на складах WB', o.stock !== null && o.stock !== undefined ? o.stock + ' шт' : '—',
        o.stock !== null && o.stock !== undefined ? 'в пути к покупателям ' + (o.stock_to || 0) + ', возвраты ' + (o.stock_from || 0) : 'ещё не загружен');

    var prices = '<h4>Цены</h4>' + row('Цена продавца', money(o.price), 'со скидкой продавца') +
      (o.finished_price ? row('Покупатель заплатил', money(o.finished_price), o.spp ? 'скидка WB (СПП) ' + o.spp + '%' : '') : '');

    var calc = '<h4>Расчёт прибыли</h4>';
    if (!c) {
      calc += '<p class="small muted">Расчёт прибыли доступен на платных тарифах.</p>';
    } else if (c.kind === 'none') {
      calc += '<p class="small">Отмена до получения: продажи нет, WB ничего не удерживает.</p>';
    } else {
      if (c.kind === 'loss') {
        calc += '<p class="small muted">Отказ при получении или возврат: продажи нет, но оплачивается логистика туда и обратно.</p>' +
          row('Логистика до покупателя', '−' + money(c.logistics), esc(c.logistics_src)) +
          row('Обратная доставка', '−' + money(c.reverse), 'базовый тариф WB без коэффициента склада');
      } else {
        calc += row('Цена продавца', money(c.price)) +
          row('Комиссия WB ' + fmtNum(c.commission_pct, 2) + '%', '−' + money(c.commission), esc(c.commission_src)) +
          row('Логистика', '−' + money(c.logistics), esc(c.logistics_src)) +
          row('Налог ' + fmtNum(c.tax_pct, 2) + '%', '−' + money(c.tax), 'из ваших настроек') +
          (c.other ? row('Прочие расходы', '−' + money(c.other), 'из ваших настроек') : '') +
          row('Себестоимость', c.cost !== null ? '−' + money(c.cost) : '<a href="/app/products?q=' + (o.nm_id || '') + '">указать →</a>', c.cost !== null ? 'из раздела «Товары»' : 'не указана — прибыль не посчитать');
      }
      if (c.profit !== null) {
        calc += '<div class="kv total"><span>Прибыль ≈</span><b class="' + (c.profit < 0 ? 'neg' : 'pos') + '">' + signed(c.profit) + '</b></div>';
      }
      calc += '<p class="small muted" style="margin-top:8px">Оценка по тарифам WB. Точные удержания — в еженедельном отчёте реализации.</p>';
    }

    var box = document.getElementById('orderModal');
    // Сначала расчёт прибыли, затем информация о заказе и товаре
    box.querySelector('.modal-body').innerHTML = head + '<div class="m-calc">' + calc + '</div>' +
      '<div class="m-grid"><div>' + order + prices + '</div><div>' + goods + '</div></div>';
    box.querySelector('.modal-card').scrollTop = 0;
    box.classList.add('open');
    document.body.classList.add('modal-open');
  }
  function closeOrder() {
    var box = document.getElementById('orderModal');
    if (box) box.classList.remove('open');
    document.body.classList.remove('modal-open');
  }
  (function setupModal() {
    var box = document.createElement('div');
    box.id = 'orderModal';
    box.className = 'modal';
    box.innerHTML = '<div class="modal-card" role="dialog" aria-modal="true"><button class="modal-x" type="button" aria-label="Закрыть">×</button><div class="modal-body"></div></div>';
    document.body.appendChild(box);
    box.addEventListener('click', function (e) { if (e.target === box || e.target.closest('.modal-x')) closeOrder(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeOrder(); });
    listEl.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      var el = e.target.closest('.order[data-id]');
      if (!el || !orders[el.getAttribute('data-id')]) return;
      try {
        openOrder(orders[el.getAttribute('data-id')]);
      } catch (err) {
        toast('Не удалось открыть заказ: ' + err.message);
      }
    });
    listEl.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      var el = e.target.closest('.order[data-id]');
      if (el && orders[el.getAttribute('data-id')]) openOrder(orders[el.getAttribute('data-id')]);
    });
  })();

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
