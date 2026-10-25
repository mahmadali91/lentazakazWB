<div class="topbar"><h1>Пользователи</h1></div>
<?= App\Core\View::partial('admin/_nav', ['tab' => 'users']) ?>

<form class="toolbar" method="get" action="/admin/users">
  <div class="search"><?= icon('search', 16) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Поиск по email или имени"></div>
  <button class="btn btn-ghost">Найти</button>
</form>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>ID</th><th>Пользователь</th><th>Регистрация</th><th>Магазинов</th><th>Тариф</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td class="muted"><?= (int) $u['id'] ?></td>
          <td>
            <div><b><?= e($u['email']) ?></b> <?= $u['role'] === 'admin' ? '<span class="badge badge-accent">админ</span>' : '' ?> <?= $u['is_blocked'] ? '<span class="badge badge-cancel">заблокирован</span>' : '' ?></div>
            <div class="small muted"><?= e($u['name']) ?> <?= $u['tg_chat_id'] ? '· Telegram ✓' : '' ?> · вход: <?= date_ru($u['last_login_at']) ?></div>
          </td>
          <td class="nowrap small"><?= date_ru($u['created_at'], false) ?></td>
          <td><?= (int) $u['stores_count'] ?></td>
          <td colspan="2">
            <form method="post" action="/admin/users/<?= (int) $u['id'] ?>" class="row" style="gap:6px">
              <?= csrf_field() ?>
              <input type="hidden" name="q" value="<?= e($q) ?>">
              <select name="plan_code" style="width:auto">
                <?php foreach ($plans as $code => $p): ?><option value="<?= e($code) ?>" <?= $u['plan_code'] === $code ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
              </select>
              <input type="date" name="plan_expires_at" value="<?= $u['plan_expires_at'] ? e(substr($u['plan_expires_at'], 0, 10)) : '' ?>" style="width:150px" title="Действует до">
              <label class="check small" style="margin:0"><input type="checkbox" name="is_admin" value="1" <?= $u['role'] === 'admin' ? 'checked' : '' ?>> админ</label>
              <label class="check small" style="margin:0"><input type="checkbox" name="is_blocked" value="1" <?= $u['is_blocked'] ? 'checked' : '' ?>> блок</label>
              <button class="btn btn-sm">Сохранить</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
