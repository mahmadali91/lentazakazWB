<?php
$hasStores = count($activeIds) > 0;
$periods = ['today' => 'Сегодня', 'yesterday' => 'Вчера', '7d' => '7 дней', '30d' => '30 дней', 'custom' => 'Период'];
$statuses = ['' => 'Все', 'created' => 'Оформлены', 'buyout' => 'Выкупы', 'cancel' => 'Отмены', 'return' => 'Возвраты'];
$schemes = ['' => 'Все схемы', 'FBO' => 'FBO · склад WB', 'SELLER' => 'Склад продавца (все)', 'FBS' => 'FBS', 'DBS' => 'DBS', 'DBW' => 'DBW', 'CC' => 'Самовывоз'];
$cronStale = !$cronLast || strtotime($cronLast) < time() - 600;
?>
<div class="topbar">
  <h1>Лента заказов</h1>
  <span class="live" id="live"><span class="live-dot"></span><span id="liveText">Подключение…</span></span>
  <button class="icon-btn" id="soundBtn" title="Звук при новом заказе"><?= icon('bell', 18) ?></button>
  <button class="icon-btn" id="themeBtn" title="Тема"><?= icon('moon', 18) ?></button>
  <?php if (!empty($plan['export'])): ?>
    <a class="btn btn-ghost btn-sm" id="exportBtn" href="/app/export"><?= icon('download', 16) ?> Excel</a>
  <?php endif; ?>
</div>

<?php if (!$hasStores): ?>
  <div class="card empty">
    <div class="ico"><?= icon('store', 30) ?></div>
    <h3>Подключите первый магазин</h3>
    <p>Вставьте токен API из кабинета продавца WB — заказы появятся здесь через минуту.</p>
    <a class="btn btn-lg" href="/app/stores"><?= icon('plus', 18) ?> Подключить магазин</a>
  </div>
<?php else: ?>
  <?php if ($cronStale && ($user['role'] ?? '') === 'admin'): ?>
    <div class="alert alert-warn">Фоновая синхронизация не запускалась больше 10 минут<?= $cronLast ? ' (последний запуск ' . date_ru($cronLast) . ')' : '' ?>. Проверьте задание CRON на хостинге — см. <a href="/admin">админ-панель</a>.</div>
  <?php endif; ?>
  <div id="syncAlerts"></div>

  <div class="toolbar" id="filters">
    <div class="seg" data-filter="period">
      <?php foreach ($periods as $k => $v): ?><button type="button" data-value="<?= $k ?>" class="<?= $filters['period'] === $k ? 'on' : '' ?>"><?= $v ?></button><?php endforeach; ?>
    </div>
    <div class="custom-dates <?= $filters['period'] === 'custom' ? 'on' : '' ?>" id="customDates">
      <input type="date" id="dateFrom" value="<?= e(substr($filters['from'], 0, 10)) ?>" min="<?= date('Y-m-d', strtotime('today') - ((int) $plan['history_days'] - 1) * 86400) ?>" max="<?= date('Y-m-d') ?>">
      <span class="muted">—</span>
      <input type="date" id="dateTo" value="<?= e(substr($filters['to'], 0, 10)) ?>" max="<?= date('Y-m-d') ?>">
    </div>
    <?php if (count($activeIds) > 1): ?>
      <select id="storeSel" aria-label="Магазин">
        <option value="0">Все магазины</option>
        <?php foreach ($stores as $s): if (!in_array((int) $s['id'], $activeIds, true)) continue; ?>
          <option value="<?= (int) $s['id'] ?>" <?= (int) $filters['store'] === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select id="schemeSel" aria-label="Схема">
      <?php foreach ($schemes as $k => $v): ?><option value="<?= $k ?>" <?= $filters['scheme'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
    </select>
    <div class="search"><?= icon('search', 16) ?><input type="search" id="q" placeholder="Артикул, WB ID, бренд, город…" value="<?= e($filters['q']) ?>"></div>
  </div>

  <div class="kpis" id="kpis">
    <div class="card kpi k-accent"><div class="k-label">Заказы</div><div class="k-value" data-k="orders">—</div><div class="k-sub" data-k="orders_sum">&nbsp;</div></div>
    <div class="card kpi"><div class="k-label">Выкупы</div><div class="k-value" data-k="buyout">—</div><div class="k-sub" data-k="buyout_sum">&nbsp;</div></div>
    <div class="card kpi"><div class="k-label">Отмены</div><div class="k-value" data-k="cancel">—</div><div class="k-sub" data-k="cancel_pct">&nbsp;</div></div>
    <div class="card kpi"><div class="k-label">Возвраты</div><div class="k-value" data-k="returns">—</div><div class="k-sub">&nbsp;</div></div>
    <div class="card kpi"><div class="k-label">Средний чек</div><div class="k-value" data-k="avg">—</div><div class="k-sub">цена продавца</div></div>
  </div>

  <div class="feed-grid">
    <div class="card orders">
      <div class="orders-head">
        <div class="seg" data-filter="status">
          <?php foreach ($statuses as $k => $v): ?><button type="button" data-value="<?= $k ?>" class="<?= $filters['status'] === $k ? 'on' : '' ?>"><?= $v ?></button><?php endforeach; ?>
        </div>
      </div>
      <div id="orderList">
        <?php for ($i = 0; $i < 5; $i++): ?>
          <div class="order"><div class="thumb"></div><div><div class="skeleton" style="width:60%"></div><div class="skeleton" style="width:40%;margin-top:8px"></div></div><div class="skeleton" style="width:70px"></div></div>
        <?php endfor; ?>
      </div>
      <div class="more hidden" id="moreWrap"><button class="btn btn-ghost" id="moreBtn">Показать ещё</button></div>
    </div>
    <div class="feed-side stack">
      <div class="card">
        <div class="card-title" id="chartTitle">Заказы по часам</div>
        <svg class="chart" id="chart" viewBox="0 0 300 150" preserveAspectRatio="none" role="img" aria-label="График заказов"></svg>
      </div>
      <div class="card">
        <div class="card-title">Топ товаров</div>
        <div id="top"><div class="muted small">Нет данных</div></div>
      </div>
      <?php if (empty($plan['telegram'])): ?>
        <div class="card" style="background:var(--accent-soft);border-color:transparent">
          <div class="card-title"><?= icon('send', 18) ?> Уведомления в Telegram</div>
          <p class="small muted">Получайте сообщение о каждом новом заказе. Доступно на платных тарифах.</p>
          <a class="btn btn-sm" href="/app/billing">Выбрать тариф</a>
        </div>
      <?php elseif (!$user['tg_chat_id'] && App\Services\Telegram::enabled()): ?>
        <div class="card" style="background:var(--accent-soft);border-color:transparent">
          <div class="card-title"><?= icon('send', 18) ?> Подключите Telegram</div>
          <p class="small muted">Новые заказы будут приходить в Telegram, даже когда сайт закрыт.</p>
          <a class="btn btn-sm" href="/app/settings">Подключить</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<script>
  window.LZ_FEED = <?= json_encode([
      'enabled'  => $hasStores,
      'filters'  => $filters,
      'syncMin'  => (int) $plan['sync_minutes'],
  ], JSON_UNESCAPED_UNICODE) ?>;
</script>
