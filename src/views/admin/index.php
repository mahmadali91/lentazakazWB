<?php $cronOk = $cronLast && strtotime($cronLast) > time() - 300; ?>
<div class="topbar"><h1>Админ-панель</h1></div>
<?= App\Core\View::partial('admin/_nav', ['tab' => 'index']) ?>

<div class="kpis">
  <div class="card kpi"><div class="k-label">Пользователей</div><div class="k-value"><?= $stats['users'] ?></div><div class="k-sub">+<?= $stats['users_today'] ?> сегодня</div></div>
  <div class="card kpi"><div class="k-label">Платящих</div><div class="k-value"><?= $stats['paying'] ?></div><div class="k-sub">активный платный тариф</div></div>
  <div class="card kpi"><div class="k-label">Магазинов</div><div class="k-value"><?= $stats['stores'] ?></div><div class="k-sub">&nbsp;</div></div>
  <div class="card kpi"><div class="k-label">Заказов сегодня</div><div class="k-value"><?= number_format($stats['orders_today'], 0, ',', ' ') ?></div><div class="k-sub">по всем магазинам</div></div>
  <div class="card kpi k-accent"><div class="k-label">Выручка 30 дней</div><div class="k-value"><?= money($stats['revenue_30']) ?></div><div class="k-sub">ЮKassa</div></div>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">Фоновая синхронизация (CRON)</div>
  <?php if ($cronOk): ?>
    <div class="alert alert-success">Работает. Последний запуск: <?= date_ru($cronLast) ?></div>
  <?php else: ?>
    <div class="alert alert-error">CRON не запускался <?= $cronLast ? 'с ' . date_ru($cronLast) : 'ни разу' ?>. Добавьте задание на хостинге (Beget → «Задания CRON»), каждую минуту:</div>
  <?php endif; ?>
  <p class="small">Команда: <code>/usr/local/bin/php<?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?> <?= e(BASE_PATH) ?>/cron/sync.php</code></p>
  <?php if (config('cron.secret')): ?>
    <p class="small muted">Запасной вариант — внешний планировщик по URL: <code><?= e(url('/cron-run?key=' . config('cron.secret'))) ?></code></p>
  <?php endif; ?>
  <form method="post" action="/admin/cron"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= icon('refresh', 14) ?> Запустить синхронизацию сейчас</button></form>
  <p class="small muted" style="margin-top:12px">
    Telegram-бот: <b><?= App\Services\Telegram::enabled() ? 'подключён @' . e(config('telegram.bot_username')) : 'не настроен' ?></b> ·
    ЮKassa: <b><?= App\Services\YooKassa::enabled() ? 'подключена' : 'не настроена' ?></b>
    — настраивается в <code>config/config.php</code>.
  </p>
</div>

<?php if ($errors): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="card-title">Магазины с ошибками</div>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Магазин</th><th>Пользователь</th><th>Ошибка</th><th>Когда</th></tr></thead>
      <tbody><?php foreach ($errors as $er): ?>
        <tr><td><?= e($er['name']) ?></td><td><?= e($er['email']) ?></td><td class="small"><?= e($er['last_error']) ?></td><td class="nowrap small"><?= date_ru($er['last_error_at']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-title">Журнал синхронизации</div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Время</th><th>Магазин</th><th>Источник</th><th>Результат</th><th>Строк</th><th>Сообщение</th></tr></thead>
    <tbody><?php foreach ($log as $l): ?>
      <tr>
        <td class="nowrap small"><?= date_ru($l['created_at']) ?></td>
        <td><?= e($l['name']) ?></td>
        <td><span class="badge"><?= e(['feed' => 'Лента', 'mp' => 'Сборочные', 'stats' => 'Статистика', 'cards' => 'Карточки'][$l['source']] ?? $l['source']) ?></span></td>
        <td><?= $l['ok'] ? '<span class="badge badge-buyout">OK</span>' : '<span class="badge badge-cancel">Ошибка</span>' ?></td>
        <td><?= (int) $l['rows_count'] ?></td>
        <td class="small muted"><?= e($l['message']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
