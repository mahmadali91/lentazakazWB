<div class="topbar"><h1>Настройки сервиса</h1></div>
<?= App\Core\View::partial('admin/_nav', ['tab' => 'settings']) ?>

<form method="post" action="/admin/settings" class="stack page-narrow">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-title"><?= icon('mail', 18) ?> Контакты для клиентов</div>
    <p class="muted small">Показываются в подвале сайта, в профиле клиента («Написать в поддержку»), на странице тарифов и в документах.</p>
    <div class="row" style="align-items:flex-start">
      <div class="field grow"><label for="sp">Телефон поддержки</label><input id="sp" type="tel" name="support_phone" value="<?= e(phone_ru($phone)) ?>" placeholder="+7 900 123-45-67" inputmode="tel"></div>
      <div class="field grow"><label for="se">Email поддержки</label><input id="se" type="email" name="support_email" value="<?= e($email) ?>" placeholder="admin@linkoz.ru"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title"><?= icon('send', 18) ?> Telegram-бот для уведомлений</div>
    <?php if ($bot): ?>
      <?php if ($bot['ok']): ?>
        <div class="alert alert-success">Бот работает: <b><?= e($bot['name']) ?></b> — <a href="https://t.me/<?= e($bot['username']) ?>" target="_blank" rel="noopener">@<?= e($bot['username']) ?></a>. Подключили уведомления: <?= (int) $bot['linked'] ?>.
          <?php if ($bot['webhook']): ?><br>Внимание: у бота установлен вебхук — привязка аккаунтов не будет работать. Сохраните токен ещё раз, чтобы снять вебхук.<?php endif; ?></div>
      <?php else: ?>
        <div class="alert alert-error">Telegram не отвечает на этот токен (getMe). Проверьте токен в @BotFather и сохраните заново.</div>
      <?php endif; ?>
    <?php else: ?>
      <div class="alert alert-warn">Бот не подключён — клиенты не смогут включить уведомления о заказах.</div>
    <?php endif; ?>
    <div class="field"><label for="bu">Имя бота (username)</label><input id="bu" type="text" name="bot_username" value="<?= e($botUser) ?>" placeholder="WBLinkoz_bot"></div>
    <div class="field"><label for="bt">Токен бота</label><input id="bt" type="password" name="bot_token" value="" autocomplete="off" placeholder="<?= $hasToken ? 'сохранён — оставьте пустым, чтобы не менять' : '123456789:AA…' ?>">
      <div class="hint">Из @BotFather («Use this token to access the HTTP API»). Хранится в базе в зашифрованном виде и больше не показывается. При сохранении проверяем его в Telegram.</div></div>
    <?php if ($hasToken): ?><label class="check"><input type="checkbox" name="bot_remove" value="1"> отключить бота (удалить токен)</label><?php endif; ?>
    <details class="inline" style="margin-top:10px">
      <summary>Как создать бота</summary>
      <ol class="small">
        <li>Откройте <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> → <span class="kbd">/newbot</span>, задайте название (например «WBLinkoz бот») и имя, оканчивающееся на <code>bot</code>.</li>
        <li>Скопируйте токен и вставьте выше. Имя бота подставится автоматически.</li>
        <li>Клиенты подключают уведомления в «Настройки» → «Уведомления в Telegram»: кнопка откроет бота, после «Запустить» аккаунт привяжется в течение минуты (бот опрашивается вместе с cron).</li>
      </ol>
    </details>
  </div>
  <button class="btn">Сохранить настройки</button>
</form>
