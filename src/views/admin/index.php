<?php $cronOk = $cronLast && strtotime($cronLast) > time() - 300; ?>
<div class="topbar"><h1>Дашборд</h1><span class="muted small">Сводка по сервису · <?= date_ru(now()) ?></span></div>
<?= App\Core\View::partial('admin/_nav', ['tab' => 'index']) ?>

<div class="kpis">
  <div class="card kpi"><div class="k-label">Клиентов</div><div class="k-value"><?= $stats['users'] ?></div><div class="k-sub">+<?= $stats['users_today'] ?> сегодня</div></div>
  <div class="card kpi"><div class="k-label">Платящих</div><div class="k-value"><a href="/admin/users?f=active" style="color:inherit"><?= $stats['paying'] ?></a></div><div class="k-sub">активный оплаченный тариф</div></div>
  <div class="card kpi"><div class="k-label">На пробном</div><div class="k-value"><a href="/admin/users?f=trial" style="color:inherit"><?= $stats['trial'] ?></a></div><div class="k-sub">ещё не платили</div></div>
  <div class="card kpi"><div class="k-label">Магазинов</div><div class="k-value"><?= $stats['stores'] ?></div><div class="k-sub">&nbsp;</div></div>
  <div class="card kpi"><div class="k-label">Заказов сегодня</div><div class="k-value"><?= number_format($stats['orders_today'], 0, ',', ' ') ?></div><div class="k-sub">по всем магазинам</div></div>
  <div class="card kpi k-accent"><div class="k-label">Выручка 30 дней</div><div class="k-value"><?= rub($stats['revenue_30']) ?></div><div class="k-sub">оплаты и ручные выдачи</div></div>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));margin-bottom:16px">
  <?php foreach ([['Новые клиенты', 'users', 'шт'], ['Выручка', 'revenue', '₽'], ['Заказы во всех магазинах', 'orders', 'шт']] as [$label, $key, $unit]):
      $pts = $series[$key]; $max = max(1, ...array_column($pts, 'v')); $sum = array_sum(array_column($pts, 'v')); $n = count($pts); ?>
    <div class="card">
      <div class="card-title" style="display:flex;justify-content:space-between"><span><?= e($label) ?></span><span class="muted small" style="font-weight:500">30 дней: <?= $unit === '₽' ? rub($sum) : number_format($sum, 0, ',', ' ') ?></span></div>
      <svg class="chart" viewBox="0 0 300 120" preserveAspectRatio="none" role="img" aria-label="<?= e($label) ?>">
        <?php foreach ($pts as $i => $p): $h = round(100 * $p['v'] / $max); $bw = 300 / $n; ?>
          <rect class="bar" x="<?= round($i * $bw + $bw * 0.15, 1) ?>" y="<?= 104 - $h ?>" width="<?= round($bw * 0.7, 1) ?>" height="<?= max($h, $p['v'] ? 2 : 0) ?>" rx="2"><title><?= e($p['d']) ?>: <?= $unit === '₽' ? rub($p['v']) : (int) $p['v'] ?></title></rect>
          <?php if ($i % 7 === 0 && $i <= 21): ?><text x="<?= round($i * $bw, 1) ?>" y="118"><?= e($p['d']) ?></text><?php elseif ($i === $n - 1): ?><text x="300" y="118" text-anchor="end"><?= e($p['d']) ?></text><?php endif; ?>
        <?php endforeach; ?>
      </svg>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" style="margin-bottom:16px">
  <div class="card-title">Истекает в ближайшие 7 дней <a class="small" style="font-weight:500;margin-left:8px" href="/admin/users?f=expiring">все →</a></div>
  <?php if (!$expiring): ?>
    <p class="muted small">Нет клиентов с истекающим сроком.</p>
  <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Клиент</th><th>Тариф</th><th>До</th><th>Магазинов</th><th></th></tr></thead>
      <tbody><?php foreach ($expiring as $u): ?>
        <tr>
          <td><a href="/admin/users/<?= (int) $u['id'] ?>"><?= e($u['email']) ?></a><div class="small muted"><?= e($u['name']) ?></div></td>
          <td><?= e(App\Services\Plans::get($u['plan_code'])['name']) ?></td>
          <td class="nowrap"><?= date_ru($u['plan_expires_at'], false) ?> <span class="small muted">(<?= max(0, (int) ceil((strtotime($u['plan_expires_at']) - time()) / 86400)) ?> дн.)</span></td>
          <td><?= (int) $u['stores_count'] ?></td>
          <td><form method="post" action="/admin/users/<?= (int) $u['id'] ?>/extend"><?= csrf_field() ?><input type="hidden" name="period" value="1m"><input type="hidden" name="back" value="/admin"><button class="btn btn-ghost btn-sm">+1 мес</button></form></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
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
        <tr><td><?= e($er['name']) ?></td><td><a href="/admin/users/<?= (int) $er['user_id'] ?>"><?= e($er['email']) ?></a></td><td class="small"><?= e($er['last_error']) ?></td><td class="nowrap small"><?= date_ru($er['last_error_at']) ?></td></tr>
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
