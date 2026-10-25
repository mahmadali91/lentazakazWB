<div class="card auth-card">
  <?= App\Core\View::partial('partials/logo') ?>
  <h1>Восстановление пароля</h1>
  <p class="muted">Укажите email — пришлём ссылку для создания нового пароля.</p>
  <?= App\Core\View::partial('partials/flash') ?>
  <form method="post" action="/forgot">
    <?= csrf_field() ?>
    <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" required autofocus></div>
    <button class="btn btn-block btn-lg">Отправить ссылку</button>
  </form>
  <div class="auth-links"><a href="/login">Вернуться ко входу</a></div>
</div>
