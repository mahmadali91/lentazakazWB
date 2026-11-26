<?php
use App\Controllers\AdminController;
use App\Core\View;

$back = '/admin/users?' . http_build_query(['f' => $filter, 'q' => $q]);
?>
<div class="topbar">
  <h1>Клиенты</h1>
  <button class="btn btn-sm" type="button" onclick="document.getElementById('newClient').classList.toggle('hidden')"><?= icon('plus', 16) ?> Создать клиента</button>
</div>
<?= View::partial('admin/_nav', ['tab' => 'users']) ?>
<?php $allBytes = array_sum(array_map(static fn($x) => (float) ($x['bytes'] ?? 0), $usage)); $allCpu = array_sum(array_map(static fn($x) => (float) ($x['cpu_7d'] ?? 0), $usage)); ?>
<p class="small muted" style="margin:-4px 0 14px">Ресурсы сервера: база данных — <b><?= e(App\Services\Usage::size(App\Services\Usage::dbTotal())) ?></b>, из них данные магазинов — <?= e(App\Services\Usage::size($allBytes)) ?>;
  фоновая синхронизация — <?= number_format($allCpu / 7 / 864, 1, ',', ' ') ?>% времени сервера за 7 дней (<?= number_format($allCpu / 60, 0, ',', ' ') ?> мин). Обновляется раз в 10 минут.</p>

<div class="card hidden" id="newClient" style="margin-bottom:16px">
  <div class="card-title">Новый клиент — доступ по логину и паролю</div>
  <form method="post" action="/admin/users/create">
    <?= csrf_field() ?>
    <div class="row" style="align-items:flex-start">
      <div class="field grow" style="min-width:200px"><label>Логин</label><input type="text" name="login" required placeholder="email или client01" autocapitalize="none"></div>
      <div class="field grow" style="min-width:160px"><label>Пароль</label><input type="text" name="password" minlength="8" required value="<?= e(substr(bin2hex(random_bytes(6)), 0, 10)) ?>"></div>
      <div class="field grow" style="min-width:160px"><label>Имя / компания</label><input type="text" name="name"></div>
      <div class="field grow" style="min-width:160px"><label>Телефон</label><input type="tel" name="phone" required inputmode="tel" placeholder="+7 900 123-45-67"></div>
    </div>
    <div class="row" style="align-items:flex-start">
      <div class="field"><label>Тариф</label><select name="plan_code"><?php foreach ($plans as $code => $p): ?><option value="<?= e($code) ?>" <?= $code === 'start' ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="width:120px"><label>Дней</label><input type="number" name="days" value="30" min="0" max="3650"></div>
      <div class="field" style="width:140px"><label>Оплачено, ₽</label><input type="text" name="amount" value="0" inputmode="decimal"></div>
      <div class="field grow" style="min-width:200px"><label>Заметка</label><input type="text" name="note" placeholder="Откуда пришёл, договорённости"></div>
    </div>
    <button class="btn">Создать</button>
  </form>
</div>

<div class="toolbar">
  <div class="seg">
    <?php foreach (AdminController::FILTERS as $k => [$label]): ?>
      <a href="/admin/users?<?= e(http_build_query(['f' => $k, 'q' => $q])) ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= e($label) ?> <span class="muted small"><?= $counts[$k] ?></span></a>
    <?php endforeach; ?>
  </div>
  <form class="search" method="get" action="/admin/users">
    <input type="hidden" name="f" value="<?= e($filter) ?>">
    <?= icon('search', 16) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Логин, имя, магазин, ИНН, заметка">
  </form>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Клиент</th><th>Тариф</th><th>Доступ</th><th>Магазины</th><th>Заказов 7 дн</th><th title="Оценка: объём данных клиента в базе, время фоновой синхронизации за 7 дней и запросы к WB за сутки">Ресурсы сервера</th><th>Оплачено</th><th>Продлить</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td style="min-width:200px">
            <a href="/admin/users/<?= (int) $u['id'] ?>"><b><?= e($u['email']) ?></b></a> <span class="small muted">ID <?= e(client_code((int) $u['id'])) ?></span>
            <?= $u['role'] === 'admin' ? '<span class="badge badge-accent">админ</span>' : '' ?>
            <div class="small muted"><?= e($u['name']) ?><?= $u['name'] ? ' · ' : '' ?>с <?= date_ru($u['created_at'], false) ?> · вход <?= $u['last_login_at'] ? date_ru($u['last_login_at']) : '—' ?></div>
            <?php if (!empty($u['phone'])): ?><div class="small"><a href="tel:<?= e($u['phone']) ?>"><?= e(phone_ru($u['phone'])) ?></a></div><?php elseif (!$u['owner_id'] && $u['role'] !== 'admin'): ?><div class="small" style="color:var(--warn)">телефон не указан</div><?php endif; ?>
            <?php if ($u['note']): ?><div class="small" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($u['note']) ?>">📝 <?= e($u['note']) ?></div><?php endif; ?>
          </td>
          <td style="min-width:150px">
            <?= e(App\Services\Plans::get($u['plan_code'])['name']) ?><?= (int) ($u['extra_stores'] ?? 0) ? ' <span class="small muted">+' . (int) $u['extra_stores'] . ' маг.</span>' : '' ?>
            <div class="small muted"><?= $u['plan_expires_at'] ? 'до ' . date_ru($u['plan_expires_at'], false) : '' ?></div>
            <?php if (empty($u['is_super'])): ?>
              <button type="button" class="link-btn small" data-plan-edit
                data-id="<?= (int) $u['id'] ?>" data-email="<?= e($u['email']) ?>" data-plan="<?= e($u['plan_code']) ?>"
                data-until="<?= e($u['plan_expires_at'] ? substr($u['plan_expires_at'], 0, 10) : date('Y-m-d', strtotime('+30 days'))) ?>"
                data-extra="<?= (int) ($u['extra_stores'] ?? 0) ?>"><?= icon('settings', 13) ?> Изменить тариф</button>
            <?php endif; ?>
          </td>
          <td class="nowrap"><?= View::partial('admin/_status', ['u' => $u, 'paid' => (bool) $u['has_paid']]) ?></td>
          <td><?= (int) $u['stores_count'] ?><?= (int) $u['members_count'] ? '<div class="small muted">+' . (int) $u['members_count'] . ' сотр.</div>' : '' ?></td>
          <td><?= number_format((int) $u['orders_7d'], 0, ',', ' ') ?></td>
          <td class="small" style="min-width:150px;max-width:200px"><?php $us = $usage[(int) $u['id']] ?? null; ?>
            <?php if ($us): ?>
              <b><?= e(App\Services\Usage::size((float) ($us['bytes'] ?? 0))) ?></b> в базе
              <div class="muted">CPU <?= number_format((float) ($us['cpu_7d'] ?? 0), 0, ',', ' ') ?> с / 7 дн · <?= number_format((float) ($us['requests_24h'] ?? 0), 0, ',', ' ') ?> запр. WB / сут</div>
            <?php else: ?><span class="muted">—</span><?php endif; ?>
          </td>
          <td class="nowrap"><?= rub($u['paid_total']) ?></td>
          <td>
            <?php if (!empty($u['is_super'])): ?><span class="small muted">не нужно</span><?php else: ?>
            <div class="extend-btns">
              <?php foreach (['7d' => '+7 дн', '1m' => '+1 мес', '3m' => '+3 мес'] as $per => $lbl): ?>
                <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/extend">
                  <?= csrf_field() ?><input type="hidden" name="period" value="<?= $per ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                  <button class="btn btn-ghost btn-sm" title="Продлить текущий тариф (или «<?= e(App\Services\Plans::get('start')['name']) ?>», если бесплатный)"><?= $lbl ?></button>
                </form>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:30px">Клиентов не найдено</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<dialog id="planDialog" class="plan-dialog">
  <form method="post" id="planForm" class="stack">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="plan-dialog-head">
      <b>Тариф клиента <span id="pdEmail" class="muted"></span></b>
      <button type="button" class="icon-btn" data-close aria-label="Закрыть">✕</button>
    </div>
    <div class="field" style="margin:0"><label for="pdPlan">Тариф</label>
      <select name="plan_code" id="pdPlan">
        <?php foreach (App\Services\Plans::all() as $code => $p): ?>
          <option value="<?= e($code) ?>" data-max="<?= App\Services\Plans::maxExtra($p + ['code' => $code]) ?>" data-price="<?= (int) ($p['store_price'] ?? 0) ?>"><?= e($p['name']) ?><?= ($p['price'] ?? 0) ? ' — ' . number_format((float) $p['price'], 0, ',', ' ') . ' ₽/мес' : '' ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="row" style="gap:10px;align-items:flex-end">
      <div class="field grow" style="margin:0" id="pdUntilBox"><label for="pdUntil">Действует до</label><input type="date" name="plan_expires_at" id="pdUntil"></div>
      <div class="field" style="margin:0" id="pdExtraBox"><label for="pdExtra">Доп. магазины</label><input type="number" name="extra_stores" id="pdExtra" min="0" value="0" style="width:90px"></div>
    </div>
    <div class="row" style="gap:6px" id="pdQuick">
      <span class="small muted">Срок:</span>
      <?php foreach (['7' => '+7 дн', '30' => '+1 мес', '90' => '+3 мес', '365' => '+1 год'] as $d => $l): ?><button type="button" class="btn btn-ghost btn-sm" data-days="<?= $d ?>"><?= $l ?></button><?php endforeach; ?>
    </div>
    <div class="field" style="margin:0" id="pdAmountBox"><label for="pdAmount">Сумма оплаты, ₽ <span class="muted small">(если клиент заплатил вне сайта — попадёт в «Платежи»)</span></label><input type="text" name="amount" id="pdAmount" inputmode="decimal" placeholder="0"></div>
    <div class="row" style="gap:8px"><button class="btn">Сохранить тариф</button><button type="button" class="btn btn-ghost" data-close>Отмена</button></div>
  </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('planDialog'), form = document.getElementById('planForm');
  var plan = document.getElementById('pdPlan'), until = document.getElementById('pdUntil'), extra = document.getElementById('pdExtra');
  function sync() {
    var o = plan.selectedOptions[0], max = +o.dataset.max, free = plan.value === 'free';
    document.getElementById('pdExtraBox').style.display = max > 0 ? '' : 'none';
    extra.max = max; if (+extra.value > max) extra.value = max;
    ['pdUntilBox', 'pdQuick', 'pdAmountBox'].forEach(function (id) { document.getElementById(id).style.display = free ? 'none' : ''; });
    until.required = !free;
  }
  document.querySelectorAll('[data-plan-edit]').forEach(function (b) {
    b.addEventListener('click', function () {
      form.action = '/admin/users/' + b.dataset.id + '/plan';
      document.getElementById('pdEmail').textContent = b.dataset.email;
      plan.value = b.dataset.plan; until.value = b.dataset.until; extra.value = b.dataset.extra;
      document.getElementById('pdAmount').value = '';
      sync(); dlg.showModal();
    });
  });
  plan.addEventListener('change', sync);
  dlg.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
  document.querySelectorAll('#pdQuick [data-days]').forEach(function (b) {
    b.addEventListener('click', function () {
      // от текущей даты окончания, если она в будущем, иначе от сегодня
      var base = until.value ? new Date(until.value) : new Date(), now = new Date();
      if (isNaN(base) || base < now) base = now;
      base.setDate(base.getDate() + +b.dataset.days);
      until.value = base.toISOString().slice(0, 10);
    });
  });
})();
</script>
