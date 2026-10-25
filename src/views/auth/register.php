<div class="card auth-card">
  <?= App\Core\View::partial('partials/logo') ?>
  <h1>Регистрация</h1>
  <p class="muted"><?= (int) config('app.trial_days', 7) ?> дней полного доступа бесплатно.</p>
  <?= App\Core\View::partial('partials/flash') ?>
  <form method="post" action="/register">
    <?= csrf_field() ?>
    <div class="field"><label for="name">Имя</label><input id="name" type="text" name="name" value="<?= e(old('name')) ?>" autocomplete="name"></div>
    <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></div>
    <div class="field"><label for="password">Пароль</label><input id="password" type="password" name="password" minlength="8" required autocomplete="new-password"><div class="hint">Не короче 8 символов</div></div>
    <div class="field"><label class="check"><input type="checkbox" name="agree" value="1" required> <span>Принимаю <a href="/oferta" target="_blank">оферту</a> и <a href="/privacy" target="_blank">политику конфиденциальности</a></span></label></div>
    <button class="btn btn-block btn-lg">Создать аккаунт</button>
  </form>
  <div class="auth-links">Уже есть аккаунт? <a href="/login">Войти</a></div>
</div>
