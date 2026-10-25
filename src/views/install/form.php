<div class="card auth-card wide">
  <?= App\Core\View::partial('partials/logo') ?>
  <?php if ($done): ?>
    <h1>Установка завершена 🎉</h1>
    <div class="alert alert-success">Таблицы созданы, администратор добавлен, файл <code>config/config.php</code> записан.</div>
    <h3>Последний шаг — задание CRON</h3>
    <p>В панели Beget откройте <b>«Задания CRON»</b> → «Добавить задание», выберите «каждую минуту» и вставьте команду:</p>
    <p><code>/usr/local/bin/php<?= e($done['php']) ?> <?= e($done['cron']) ?></code></p>
    <p class="small muted">Если планировщика нет, можно вызывать раз в минуту URL (внешний сервис мониторинга):<br><code><?= e($done['cron_url']) ?></code></p>
    <h3>Дополнительно (в <code>config/config.php</code>)</h3>
    <ul class="small">
      <li><b>telegram</b> — токен и имя бота от @BotFather для уведомлений;</li>
      <li><b>yookassa</b> — shopId и секретный ключ для приёма оплаты, URL уведомлений: <code><?= e($url) ?>/webhook/yookassa</code>;</li>
      <li>цены и лимиты тарифов — в <code>config/plans.php</code>.</li>
    </ul>
    <a class="btn btn-lg btn-block" href="/login">Войти в админ-панель</a>
  <?php else: ?>
    <h1>Установка сервиса</h1>
    <p class="muted">Заполните данные базы MySQL (Beget → «MySQL» → создайте базу) и администратора.</p>
    <div class="card" style="box-shadow:none;margin-bottom:18px;padding:14px 18px">
      <?php foreach ($checks as $label => $ok): ?>
        <div class="scope <?= $ok ? 'on' : 'off' ?>" style="margin:4px 0"><span class="dot"><?= $ok ? '✓' : '✗' ?></span><span><?= e($label) ?><?= $ok ? '' : ' — <b style="color:var(--bad)">исправьте</b>' ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="/install">
      <?= csrf_field() ?>
      <div class="field"><label>Адрес сайта</label><input type="url" name="app_url" value="<?= e($url) ?>" required><div class="hint">С https://, без слеша на конце</div></div>
      <div class="field"><label>Название сервиса</label><input type="text" name="app_name" value="<?= e($_POST['app_name'] ?? 'Лента заказов WB') ?>"></div>
      <div class="row" style="align-items:flex-start">
        <div class="field grow"><label>Хост БД</label><input type="text" name="db_host" value="<?= e($_POST['db_host'] ?? 'localhost') ?>" required></div>
        <div class="field" style="width:110px"><label>Порт</label><input type="number" name="db_port" value="<?= e($_POST['db_port'] ?? '3306') ?>"></div>
      </div>
      <div class="field"><label>Имя базы данных</label><input type="text" name="db_name" value="<?= e($_POST['db_name'] ?? '') ?>" required><div class="hint">На Beget имя базы и пользователя совпадают, например <code>login_lenta</code></div></div>
      <div class="field"><label>Пользователь БД</label><input type="text" name="db_user" value="<?= e($_POST['db_user'] ?? '') ?>" required></div>
      <div class="field"><label>Пароль БД</label><input type="password" name="db_pass"></div>
      <hr style="border:0;border-top:1px solid var(--border);margin:22px 0">
      <div class="field"><label>Email администратора</label><input type="email" name="admin_email" value="<?= e($_POST['admin_email'] ?? '') ?>" required></div>
      <div class="field"><label>Пароль администратора</label><input type="password" name="admin_password" minlength="8" required></div>
      <button class="btn btn-lg btn-block">Установить</button>
    </form>
  <?php endif; ?>
</div>
