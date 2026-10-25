<?php
use App\Controllers\SettingsController;
use App\Services\Telegram;

$events = array_filter(explode(',', (string) $user['tg_events']));
?>
<div class="topbar"><h1>Настройки</h1></div>

<div class="stack page-narrow">
  <div class="card">
    <div class="card-title"><?= icon('send', 18) ?> Уведомления в Telegram</div>
    <?php if (!$tgEnabled): ?>
      <p class="muted">Администратор ещё не подключил Telegram-бота.</p>
    <?php elseif (empty($plan['telegram'])): ?>
      <p class="muted">Уведомления доступны на платных тарифах.</p>
      <a class="btn" href="/app/billing">Выбрать тариф</a>
    <?php elseif ($user['tg_chat_id']): ?>
      <div class="alert alert-success">Telegram подключён. Уведомления приходят в чат с ботом @<?= e(config('telegram.bot_username')) ?>.</div>
      <form method="post" action="/app/settings/telegram">
        <?= csrf_field() ?>
        <label>О чём уведомлять</label>
        <div class="row" style="margin-bottom:14px">
          <?php foreach (SettingsController::TG_EVENTS as $k => $label): ?>
            <label class="check"><input type="checkbox" name="events[]" value="<?= $k ?>" <?= in_array($k, $events, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach; ?>
        </div>
        <button class="btn btn-sm">Сохранить</button>
      </form>
      <div class="row" style="margin-top:14px">
        <form method="post" action="/app/settings/telegram/test"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Отправить тест</button></form>
        <form method="post" action="/app/settings/telegram/unlink"><?= csrf_field() ?><button class="btn btn-danger btn-sm">Отключить</button></form>
      </div>
    <?php else: ?>
      <p>Нажмите кнопку — откроется бот. Нажмите в нём <span class="kbd">Запустить</span> (Start), и аккаунт привяжется автоматически в течение минуты.</p>
      <div class="row">
        <a class="btn" href="<?= e(Telegram::linkUrl((string) $user['tg_link_code'])) ?>" target="_blank" rel="noopener"><?= icon('send', 16) ?> Подключить Telegram</a>
        <a class="btn btn-ghost" href="/app/settings">Я нажал «Запустить» — обновить</a>
      </div>
      <p class="small muted" style="margin-top:12px">Если бот не открылся: найдите @<?= e(config('telegram.bot_username')) ?> и отправьте ему <code>/start <?= e($user['tg_link_code']) ?></code></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="card-title">Профиль</div>
    <form method="post" action="/app/settings/profile">
      <?= csrf_field() ?>
      <div class="field"><label>Email</label><input type="email" value="<?= e($user['email']) ?>" disabled></div>
      <div class="field"><label for="name">Имя</label><input id="name" type="text" name="name" value="<?= e($user['name']) ?>"></div>
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

  <div class="card">
    <div class="card-title">Оформление</div>
    <button class="btn btn-ghost" id="themeBtn" type="button"><?= icon('moon', 16) ?> Переключить светлую / тёмную тему</button>
  </div>
</div>
