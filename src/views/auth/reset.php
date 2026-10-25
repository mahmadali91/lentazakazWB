<div class="card auth-card">
  <?= App\Core\View::partial('partials/logo') ?>
  <h1>Новый пароль</h1>
  <?= App\Core\View::partial('partials/flash') ?>
  <form method="post" action="/reset">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div class="field"><label for="password">Новый пароль</label><input id="password" type="password" name="password" minlength="8" required autofocus autocomplete="new-password"></div>
    <button class="btn btn-block btn-lg">Сохранить пароль</button>
  </form>
</div>
