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

<div class="card hidden" id="newClient" style="margin-bottom:16px">
  <div class="card-title">Новый клиент — доступ по логину и паролю</div>
  <form method="post" action="/admin/users/create">
    <?= csrf_field() ?>
    <div class="row" style="align-items:flex-start">
      <div class="field grow" style="min-width:200px"><label>Логин</label><input type="text" name="login" required placeholder="email или client01" autocapitalize="none"></div>
      <div class="field grow" style="min-width:160px"><label>Пароль</label><input type="text" name="password" minlength="8" required value="<?= e(substr(bin2hex(random_bytes(6)), 0, 10)) ?>"></div>
      <div class="field grow" style="min-width:160px"><label>Имя / компания</label><input type="text" name="name"></div>
    </div>
    <div class="row" style="align-items:flex-start">
      <div class="field"><label>Тариф</label><select name="plan_code"><?php foreach ($plans as $code => $p): ?><option value="<?= e($code) ?>" <?= $code === 'start' ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="width:120px"><label>Дней</label><input type="number" name="days" value="30" min="0" max="3650"></div>
      <div class="field" style="width:140px"><label>Оплачено, ₽</label><input type="text" name="amount" value="0" inputmode="decimal"></div>
      <div class="field grow" style="min-width:200px"><label>Заметка</label><input type="text" name="note" placeholder="Телефон, откуда пришёл, договорённости"></div>
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
      <thead><tr><th>Клиент</th><th>Тариф</th><th>Доступ</th><th>Магазины</th><th>Заказов 7 дн</th><th>Оплачено</th><th>Продлить</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td style="min-width:220px">
            <a href="/admin/users/<?= (int) $u['id'] ?>"><b><?= e($u['email']) ?></b></a>
            <?= $u['role'] === 'admin' ? '<span class="badge badge-accent">админ</span>' : '' ?>
            <div class="small muted"><?= e($u['name']) ?><?= $u['name'] ? ' · ' : '' ?>с <?= date_ru($u['created_at'], false) ?> · вход <?= $u['last_login_at'] ? date_ru($u['last_login_at']) : '—' ?></div>
            <?php if ($u['note']): ?><div class="small" style="max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($u['note']) ?>">📝 <?= e($u['note']) ?></div><?php endif; ?>
          </td>
          <td class="nowrap"><?= e(App\Services\Plans::get($u['plan_code'])['name']) ?><div class="small muted"><?= $u['plan_expires_at'] ? 'до ' . date_ru($u['plan_expires_at'], false) : '' ?></div></td>
          <td class="nowrap"><?= View::partial('admin/_status', ['u' => $u, 'paid' => (bool) $u['has_paid']]) ?></td>
          <td><?= (int) $u['stores_count'] ?><?= (int) $u['members_count'] ? '<div class="small muted">+' . (int) $u['members_count'] . ' сотр.</div>' : '' ?></td>
          <td><?= number_format((int) $u['orders_7d'], 0, ',', ' ') ?></td>
          <td class="nowrap"><?= money($u['paid_total']) ?></td>
          <td>
            <div class="row" style="gap:4px;min-width:150px">
              <?php foreach (['7d' => '+7 дн', '1m' => '+1 мес', '3m' => '+3 мес'] as $per => $lbl): ?>
                <form method="post" action="/admin/users/<?= (int) $u['id'] ?>/extend">
                  <?= csrf_field() ?><input type="hidden" name="period" value="<?= $per ?>"><input type="hidden" name="back" value="<?= e($back) ?>">
                  <button class="btn btn-ghost btn-sm" title="Продлить текущий тариф (или «Старт», если бесплатный)"><?= $lbl ?></button>
                </form>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="7" class="muted" style="text-align:center;padding:30px">Клиентов не найдено</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
