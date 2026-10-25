<div class="card auth-card">
  <?= App\Core\View::partial('partials/logo') ?>
  <h1>Вход</h1>
  <?= App\Core\View::partial('partials/flash') ?>
  <form method="post" action="/login">
    <?= csrf_field() ?>
    <div class="field"><label for="email">Email или логин</label><input id="email" type="text" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username" autocapitalize="none"></div>
    <div class="field"><label for="password">Пароль</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-block btn-lg">Войти</button>
  </form>
  <div class="auth-links"><a href="/forgot">Забыли пароль?</a> · Нет аккаунта? <a href="/register">Регистрация</a></div>
</div>
