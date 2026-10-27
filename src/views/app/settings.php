<?php
use App\Controllers\SettingsController;
use App\Services\Telegram;

$events = array_filter(explode(',', (string) $user['tg_events']));
$tgStores = Telegram::recipientStores($user);
// Выбор магазинов: «все» или отмеченные (для формы сотрудника и уведомлений)
$accessFields = static function (?array $access, array $stores, string $uid): string {
    $all = $access === null;
    $h = '<div class="access-pick" data-access>'
        . '<label class="check"><input type="radio" name="access" value="all" ' . ($all ? 'checked' : '') . '> все магазины кабинета</label>'
        . '<label class="check"><input type="radio" name="access" value="some" ' . ($all ? '' : 'checked') . '> только выбранные:</label>'
        . '<div class="access-list' . ($all ? ' hidden' : '') . '">';
    foreach ($stores as $s) {
        $h .= '<label class="check"><input type="checkbox" name="stores[]" value="' . (int) $s['id'] . '" ' . (!$all && in_array((int) $s['id'], $access, true) ? 'checked' : '') . '> ' . e($s['name']) . '</label>';
    }
    return $h . '</div></div>';
};
?>
<div class="topbar"><h1>Настройки</h1></div>

<div class="stack page-narrow">
  <div class="card" id="notify">
    <div class="card-title"><?= icon('send', 18) ?> Уведомления о заказах — MAX и Telegram</div>
    <?php if (!$tgEnabled && !$maxEnabled): ?>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <p class="muted">Боты ещё не подключены. Вставьте токены ботов MAX и Telegram в <a href="/admin/settings">Админ-панель → Настройки сервиса</a> — после этого здесь появятся кнопки подключения.</p>
      <?php else: ?>
        <p class="muted">Уведомления скоро заработают — администратор подключает ботов.</p>
      <?php endif; ?>
    <?php elseif (empty($plan['telegram'])): ?>
      <p class="muted">Уведомления о заказах в MAX и Telegram доступны на тарифах <?= e(App\Services\Plans::namesWith('telegram')) ?>.</p>
      <a class="btn" href="/app/billing">Выбрать тариф</a>
    <?php else: ?>
      <p class="muted small">Бот присылает сообщение о каждом новом заказе: товар, цена, склад, город. Можно включить выкупы, отмены и возвраты. Подключите один или оба мессенджера.</p>

      <?php if ($maxEnabled): ?>
        <div class="notify-ch">
          <div class="notify-ch-head"><b>MAX</b> <span class="badge badge-buyout">рекомендуем</span></div>
          <?php if ($user['max_user_id']): ?>
            <div class="alert alert-success">MAX подключён — уведомления приходят в чат с ботом <?= e(config('max.bot_username')) ?>.</div>
            <div class="row">
              <form method="post" action="/app/settings/max/test"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Отправить тест</button></form>
              <form method="post" action="/app/settings/max/unlink"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Отключить</button></form>
            </div>
          <?php else: ?>
            <ol class="tg-steps">
              <li>Нажмите <b>«Подключить MAX»</b> — откроется бот в приложении или веб-версии MAX (<a href="https://web.max.ru" target="_blank" rel="noopener">web.max.ru</a>).</li>
              <li>В чате с ботом нажмите <span class="kbd">Начать</span>.</li>
              <li>Подождите до минуты и нажмите «Проверить подключение».</li>
            </ol>
            <div class="row">
              <a class="btn" href="<?= e(App\Services\Max::linkUrl((string) $user['max_link_code'])) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> Подключить MAX</a>
              <a class="btn btn-ghost" href="/app/settings#notify">Проверить подключение</a>
            </div>
            <p class="small muted" style="margin-top:10px">Не открылось? Найдите в MAX бота <b><?= e(config('max.bot_username')) ?></b> и отправьте ему <code>/start <?= e($user['max_link_code']) ?></code> — код привязывает бота к вашему кабинету, не передавайте его другим.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($tgEnabled): ?>
        <div class="notify-ch">
          <div class="notify-ch-head"><b>Telegram</b></div>
          <div class="alert alert-warn small">Из-за ограничений и блокировок Telegram в России уведомления могут приходить с задержкой или не приходить. Для надёжности подключите MAX.</div>
          <?php if ($user['tg_chat_id']): ?>
            <div class="alert alert-success">Telegram подключён — уведомления приходят в чат с ботом @<?= e(config('telegram.bot_username')) ?>.</div>
            <div class="row">
              <form method="post" action="/app/settings/telegram/test"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Отправить тест</button></form>
              <form method="post" action="/app/settings/telegram/unlink"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Отключить</button></form>
            </div>
          <?php else: ?>
            <ol class="tg-steps">
              <li>Нажмите <b>«Подключить Telegram»</b> — откроется бот <a href="https://t.me/<?= e(config('telegram.bot_username')) ?>" target="_blank" rel="noopener">t.me/<?= e(config('telegram.bot_username')) ?></a> (на компьютере — в Telegram Desktop или веб-версии).</li>
              <li>В чате с ботом нажмите <span class="kbd">Запустить</span> (Start).</li>
              <li>Подождите до минуты и нажмите «Проверить подключение».</li>
            </ol>
            <div class="row">
              <a class="btn btn-ghost" href="<?= e(Telegram::linkUrl((string) $user['tg_link_code'])) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> Подключить Telegram</a>
              <a class="btn btn-ghost" href="/app/settings#notify">Проверить подключение</a>
            </div>
            <p class="small muted" style="margin-top:10px">Не открылось? Найдите в Telegram <b>@<?= e(config('telegram.bot_username')) ?></b> и отправьте ему <code>/start <?= e($user['tg_link_code']) ?></code>.</p>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($user['tg_chat_id'] || $user['max_user_id']): ?>
        <form method="post" action="/app/settings/telegram" class="notify-ch">
          <?= csrf_field() ?>
          <label>О чём уведомлять</label>
          <div class="row" style="margin-bottom:14px">
            <?php foreach (SettingsController::TG_EVENTS as $k => $label): ?>
              <label class="check"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, $events, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
            <?php endforeach; ?>
          </div>
          <?php if (count($myStores) > 1): ?>
            <input type="hidden" name="tg_stores_on" value="1">
            <label>По каким магазинам</label>
            <div class="row" style="margin-bottom:14px">
              <?php foreach ($myStores as $s): ?>
                <label class="check"><input type="checkbox" name="tg_stores[]" value="<?= (int) $s['id'] ?>" <?= $tgStores === null || in_array((int) $s['id'], $tgStores, true) ? 'checked' : '' ?>> <?= e($s['name']) ?></label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <button class="btn btn-sm">Сохранить</button>
          <span class="small muted">Настройки общие для MAX и Telegram.</span>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if (empty($user['owner_id'])): $teamLimit = (int) ($plan['team'] ?? 0); ?>
  <div class="card" id="team">
    <div class="card-title"><?= icon('users', 18) ?> Сотрудники и клиенты — доступ к кабинету по логину и паролю</div>
    <p class="muted small">Сотрудник видит ленту заказов, товары и финансы, но не может менять магазины, токены, тариф и себестоимость.
      Клиенту можно открыть только его магазины — он не увидит остальные кабинеты, а уведомления в Telegram будет получать только по своим магазинам.
      <?php if ($teamLimit >= 100): ?>На вашем тарифе — без ограничений.<?php elseif ($teamLimit > 0): ?>Доступно на тарифе: <b><?= $teamLimit ?></b> <?= plural($teamLimit, 'сотрудник', 'сотрудника', 'сотрудников') ?>.<?php endif; ?></p>
    <?php if ($members): ?>
      <div class="table-wrap" style="margin-bottom:16px"><table class="table">
        <thead><tr><th>Логин</th><th>Имя</th><th>Магазины</th><th>Последний вход</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($members as $m): $acc = App\Services\StoreService::memberAccess($m + ['owner_id' => 1]);
          $accNames = $acc === null ? 'все' : implode(', ', array_column(array_filter($allStores, static fn($s) => in_array((int) $s['id'], $acc, true)), 'name')); ?>
          <tr>
            <td><b><?= e($m['email']) ?></b><div class="small muted">ID <?= e(client_code((int) $m['id'])) ?></div></td>
            <td><?= e($m['name']) ?></td>
            <td class="small">
              <details class="access-edit">
                <summary><?= e($accNames !== '' ? $accNames : 'нет доступа') ?></summary>
                <form method="post" action="/app/team/<?= (int) $m['id'] ?>/access">
                  <?= csrf_field() ?>
                  <?= $accessFields($acc, $allStores, (string) $m['id']) ?>
                  <button class="btn btn-sm" style="margin-top:8px">Сохранить доступ</button>
                </form>
              </details>
            </td>
            <td class="small muted nowrap"><?= date_ru($m['last_login_at']) ?></td>
            <td>
              <div class="row" style="gap:6px;justify-content:flex-end">
                <form method="post" action="/app/team/<?= (int) $m['id'] ?>/password" class="row" style="gap:6px">
                  <?= csrf_field() ?>
                  <input type="text" name="password" placeholder="Новый пароль" minlength="8" required style="width:150px;padding:6px 10px" autocomplete="new-password">
                  <button class="btn btn-ghost btn-sm">Сменить</button>
                </form>
                <form method="post" action="/app/team/<?= (int) $m['id'] ?>/delete" data-confirm="Удалить доступ сотрудника <?= e($m['email']) ?>?"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Удалить</button></form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <?php if (count($members) < $teamLimit): ?>
      <form method="post" action="/app/team">
        <?= csrf_field() ?>
        <div class="row" style="align-items:flex-start">
          <div class="field grow" style="min-width:180px"><label for="tlogin">Логин</label><input id="tlogin" type="text" name="login" required placeholder="manager1 или email" autocapitalize="none"></div>
          <div class="field grow" style="min-width:160px"><label for="tname">Имя</label><input id="tname" type="text" name="name" placeholder="Анна, менеджер"></div>
          <div class="field grow" style="min-width:160px"><label for="tpass">Пароль</label><input id="tpass" type="text" name="password" minlength="8" required autocomplete="new-password"></div>
        </div>
        <?php if (count($allStores) > 1): ?>
          <div class="field"><label>Доступ к магазинам</label><?= $accessFields(null, $allStores, 'new') ?></div>
        <?php endif; ?>
        <button class="btn btn-sm"><?= icon('plus', 14) ?> Добавить сотрудника или клиента</button>
        <div class="hint small muted" style="margin-top:8px">Вход: <?= e(url('/login')) ?> — логин и пароль, которые вы задали.</div>
      </form>
    <?php elseif ($teamLimit === 0): ?>
      <a class="btn btn-sm" href="/app/billing">Доступно на тарифе <?= e(App\Services\Plans::namesWith('team')) ?></a>
    <?php else: ?>
      <p class="small muted">Лимит сотрудников по тарифу исчерпан. <a href="/app/billing">Увеличить</a></p>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="alert alert-info">Вы вошли как сотрудник кабинета <b><?= e($account['name'] ?: $account['email']) ?></b>.</div>
  <?php endif; ?>

  <?php if (($user['role'] ?? '') === 'admin'): ?>
  <a class="card settings-link" href="/app/settings/api">
    <span class="logo-mark" style="width:40px;height:40px"><?= icon('zap', 20) ?></span>
    <span class="grow"><b>API и подключение к Claude</b><span class="muted small" style="display:block">Ключ доступа: Claude сам смотрит заказы, прибыль и состояние синхронизации. Только чтение.</span></span>
    <?= icon('chevron', 18) ?>
  </a>
  <?php endif; ?>

  <div class="card" id="profile">
    <div class="card-title">Профиль</div>
    <?php if (App\Core\Auth::needsPhone($user)): ?>
      <div class="alert alert-warn">Укажите номер телефона — он нужен для связи по тарифу и поддержке.</div>
    <?php endif; ?>
    <form method="post" action="/app/settings/profile">
      <?= csrf_field() ?>
      <div class="field"><label>Логин</label><input type="text" value="<?= e($user['email']) ?>" disabled></div>
      <div class="field"><label for="name">Имя</label><input id="name" type="text" name="name" value="<?= e($user['name']) ?>"></div>
      <div class="field"><label for="phone">Телефон</label><input id="phone" type="tel" name="phone" value="<?= e(phone_ru($user['phone'] ?? '')) ?>" inputmode="tel" autocomplete="tel" placeholder="+7 900 123-45-67" <?= empty($user['owner_id']) && ($user['role'] ?? '') !== 'admin' ? 'required' : '' ?>></div>
      <button class="btn btn-sm">Сохранить</button>
    </form>
  </div>

  <div class="card">
    <div class="card-title">Смена пароля</div>
    <form method="post" action="/app/settings/password">
      <?= csrf_field() ?>
      <div class="field"><label for="cur">Текущий пароль</label><input id="cur" type="password" name="current" required autocomplete="current-password"></div>
      <div class="field"><label for="np">Новый пароль</label><input id="np" type="password" name="password" minlength="8" required autocomplete="new-password"></div>
      <button class="btn btn-sm">Изменить пароль</button>
    </form>
  </div>

  <?php if (empty($user['owner_id'])): ?>
  <div class="card" id="currency">
    <div class="card-title">Валюта сумм</div>
    <form method="post" action="/app/settings/currency" class="stack">
      <?= csrf_field() ?><input type="hidden" name="back" value="/app/settings#currency">
      <label class="check"><input type="radio" name="show_rub" value="0" <?= empty($user['show_rub']) ? 'checked' : '' ?>> В валюте магазина — как в кабинете WB (₽, сомы, тенге…)</label>
      <label class="check"><input type="radio" name="show_rub" value="1" <?= !empty($user['show_rub']) ? 'checked' : '' ?>> В рублях по курсу ЦБ РФ — лента по курсу на сегодня, финансы — по курсу на конец каждой недели или месяца</label>
      <div><button class="btn btn-sm">Сохранить</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title">Оформление</div>
    <button class="btn btn-ghost" id="themeBtn" type="button"><?= icon('moon', 16) ?> Переключить светлую / тёмную тему</button>
  </div>
</div>
<script>
  // «Только выбранные магазины» — показываем список магазинов
  document.querySelectorAll('[data-access]').forEach(function (box) {
    box.addEventListener('change', function () {
      var some = box.querySelector('input[name=access][value=some]').checked;
      box.querySelector('.access-list').classList.toggle('hidden', !some);
    });
  });
</script>
