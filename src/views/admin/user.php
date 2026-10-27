<?php
use App\Core\View;
use App\Services\Plans;

$names = ['pending' => 'Ожидает', 'succeeded' => 'Оплачен (ЮKassa)', 'canceled' => 'Отменён', 'manual' => 'Выдан вручную'];
?>
<div class="topbar">
  <h1 style="overflow:hidden;text-overflow:ellipsis"><?= e($client['email']) ?> <span class="muted" style="font-size:15px;font-weight:600">ID <?= e(client_code((int) $client['id'])) ?></span></h1>
  <a class="btn btn-ghost btn-sm" href="/admin/users">← Все клиенты</a>
  <?php if ((int) $client['id'] !== (int) $user['id']): ?>
    <form method="post" action="/admin/users/<?= (int) $client['id'] ?>/login"><?= csrf_field() ?><button class="btn btn-sm" title="Открыть кабинет клиента его глазами">Войти как клиент</button></form>
  <?php endif; ?>
</div>
<?= View::partial('admin/_nav', ['tab' => 'users']) ?>

<?php if ($owner): ?>
  <div class="alert alert-info">Это сотрудник кабинета <a href="/admin/users/<?= (int) $owner['id'] ?>"><?= e($owner['email']) ?></a>. Тариф и сроки берутся у владельца.</div>
<?php endif; ?>

<div class="feed-grid">
  <div class="stack">
    <div class="card">
      <div class="row" style="margin-bottom:12px">
        <div class="grow">
          <div class="muted small">Тариф</div>
          <div style="font-size:22px;font-weight:800"><?= e(Plans::get($client['plan_code'])['name']) ?><?= (int) ($client['extra_stores'] ?? 0) ? ' <span class="small muted">+ ' . (int) $client['extra_stores'] . ' доп. маг.</span>' : '' ?></div>
        </div>
        <div><?= View::partial('admin/_status', ['u' => $client, 'paid' => $hasPaid]) ?></div>
      </div>
      <div class="small muted">Регистрация: <?= date_ru($client['created_at']) ?> · последний вход: <?= date_ru($client['last_login_at']) ?> · Telegram: <?= $client['tg_chat_id'] ? 'подключён' : 'нет' ?></div>

      <h3 style="margin-top:20px;font-size:16px">Быстрое продление</h3>
      <form method="post" action="/admin/users/<?= (int) $client['id'] ?>/extend" class="row" style="gap:8px">
        <?= csrf_field() ?>
        <select name="plan_code" style="width:auto">
          <?php foreach ($plans as $code => $p): if ($code === 'free') continue; ?>
            <option value="<?= e($code) ?>" <?= $client['plan_code'] === $code ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="period" style="width:auto">
          <option value="7d">+7 дней</option><option value="14d">+14 дней</option><option value="1m" selected>+1 месяц</option>
          <option value="3m">+3 месяца</option><option value="6m">+6 месяцев</option><option value="12m">+12 месяцев</option>
        </select>
        <input type="text" name="amount" placeholder="Оплачено, ₽" inputmode="decimal" style="width:130px">
        <button class="btn btn-sm">Продлить</button>
      </form>
      <p class="small muted" style="margin-top:6px">Если тариф тот же и ещё действует — срок прибавляется к текущей дате окончания, иначе считается от сегодня.</p>

      <h3 style="margin-top:20px;font-size:16px">Точные настройки</h3>
      <form method="post" action="/admin/users/<?= (int) $client['id'] ?>">
        <?= csrf_field() ?>
        <div class="row" style="align-items:flex-start">
          <div class="field"><label>Тариф</label><select name="plan_code"><?php foreach ($plans as $code => $p): ?><option value="<?= e($code) ?>" <?= $client['plan_code'] === $code ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label>Действует до</label><input type="date" name="plan_expires_at" value="<?= $client['plan_expires_at'] ? e(substr($client['plan_expires_at'], 0, 10)) : '' ?>"></div>
          <div class="field"><label>Доп. магазины</label><input type="number" name="extra_stores" min="0" max="999" value="<?= (int) ($client['extra_stores'] ?? 0) ?>" style="width:90px" title="Сверх включённых в тариф (только для тарифов с докупкой магазинов)"></div>
        </div>
        <div class="field"><label>Телефон</label><input type="tel" name="phone" value="<?= e(phone_ru($client['phone'] ?? '')) ?>" inputmode="tel" placeholder="+7 900 123-45-67"></div>
        <div class="field"><label>Заметка о клиенте</label><textarea name="note" style="font-family:inherit;font-size:14px;min-height:70px" placeholder="Договорённости, откуда пришёл"><?= e($client['note']) ?></textarea></div>
        <div class="row" style="margin-bottom:14px">
          <label class="check" style="margin:0"><input type="checkbox" name="is_admin" value="1" <?= $client['role'] === 'admin' ? 'checked' : '' ?>> администратор</label>
          <label class="check" style="margin:0"><input type="checkbox" name="is_blocked" value="1" <?= $client['is_blocked'] ? 'checked' : '' ?>> заблокировать доступ</label>
        </div>
        <button class="btn btn-sm">Сохранить</button>
      </form>
    </div>

    <div class="card">
      <div class="card-title">Магазины (<?= count($stores) ?>)</div>
      <?php $us = App\Services\Usage::byUser()[(int) $client['id']] ?? null; ?>
      <?php if ($us): ?><p class="small muted">Ресурсы сервера: <b><?= e(App\Services\Usage::size((float) ($us['bytes'] ?? 0))) ?></b> в базе (<?= number_format((float) ($us['rows'] ?? 0), 0, ',', ' ') ?> строк) · синхронизация <?= number_format((float) ($us['cpu_7d'] ?? 0), 0, ',', ' ') ?> с за 7 дней · <?= number_format((float) ($us['requests_24h'] ?? 0), 0, ',', ' ') ?> запросов к WB за сутки.</p><?php endif; ?>
      <?php if (!$stores): ?><p class="muted small">Клиент ещё не подключил магазин.</p><?php endif; ?>
      <?php foreach ($stores as $s): $scopes = json_decode((string) $s['scopes'], true) ?: []; ?>
        <div class="top-item" style="align-items:flex-start">
          <div class="grow">
            <b><?= e($s['name']) ?></b> <?= (int) $s['is_active'] ? '' : '<span class="badge">пауза</span>' ?> <?= $s['last_error'] ? '<span class="badge badge-cancel">ошибка</span>' : '' ?>
            <div class="small muted"><?= e($s['seller_name']) ?><?= $s['seller_tin'] ? ' · ИНН ' . e($s['seller_tin']) : '' ?> · токен до <?= date_ru($s['token_expires_at'], false) ?></div>
            <div class="small muted">Категории: <?= e(implode(', ', array_keys(array_filter(array_intersect_key($scopes, App\Services\StoreService::SCOPE_LABELS))))) ?: '—' ?></div>
            <?php if ($s['last_error']): ?><div class="small" style="color:var(--bad)"><?= e($s['last_error']) ?></div><?php endif; ?>
          </div>
          <div style="text-align:right" class="small"><b><?= number_format((int) $s['orders_7d'], 0, ',', ' ') ?></b> за 7 дн<div class="muted"><?= number_format((int) $s['orders_total'], 0, ',', ' ') ?> всего</div>
            <?php $su = $us['stores'][(int) $s['id']] ?? null; if ($su): ?><div class="muted"><?= e(App\Services\Usage::size((float) ($su['bytes'] ?? 0))) ?> · CPU <?= number_format((float) ($su['cpu_7d'] ?? 0), 0, ',', ' ') ?> с</div><?php endif; ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div class="card-title">Платежи и выдачи доступа</div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Дата</th><th>Тариф</th><th>Срок</th><th>Сумма</th><th>Статус</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
          <tr><td class="small nowrap"><?= date_ru($p['created_at']) ?></td><td><?= e(Plans::get($p['plan_code'])['name']) ?></td><td><?= (int) $p['months'] ? (int) $p['months'] . ' мес' : '—' ?></td><td><?= rub($p['amount']) ?></td>
            <td><span class="badge <?= in_array($p['status'], ['succeeded', 'manual'], true) ? 'badge-buyout' : ($p['status'] === 'canceled' ? 'badge-cancel' : 'badge-created') ?>"><?= e($names[$p['status']] ?? $p['status']) ?></span></td></tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="5" class="muted">Пока нет</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="feed-side stack">
    <div class="card">
      <div class="card-title"><?= icon('lock', 18) ?> Новый пароль</div>
      <form method="post" action="/admin/users/<?= (int) $client['id'] ?>/password">
        <?= csrf_field() ?>
        <input type="text" name="password" minlength="8" required value="<?= e(substr(bin2hex(random_bytes(6)), 0, 10)) ?>" style="margin-bottom:10px">
        <button class="btn btn-ghost btn-sm">Установить пароль</button>
      </form>
      <p class="small muted" style="margin-top:10px">Логин для входа: <b><?= e($client['email']) ?></b><br><?= e(url('/login')) ?></p>
    </div>

    <?php if ($members): ?>
      <div class="card">
        <div class="card-title"><?= icon('users', 18) ?> Сотрудники</div>
        <?php foreach ($members as $m): ?>
          <div class="top-item"><div class="grow"><a href="/admin/users/<?= (int) $m['id'] ?>"><?= e($m['email']) ?></a><div class="small muted"><?= e($m['name']) ?> · вход <?= date_ru($m['last_login_at']) ?></div></div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ((int) $client['id'] !== (int) $user['id']): ?>
      <div class="card">
        <div class="card-title" style="color:var(--bad)">Удаление</div>
        <p class="small muted">Удалит аккаунт, его магазины, заказы, сотрудников и историю платежей.</p>
        <form method="post" action="/admin/users/<?= (int) $client['id'] ?>/delete" data-confirm="Удалить аккаунт <?= e($client['email']) ?> безвозвратно?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Удалить аккаунт</button></form>
      </div>
    <?php endif; ?>
  </div>
</div>
