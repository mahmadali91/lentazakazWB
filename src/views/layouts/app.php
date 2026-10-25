<?php
$nav = $nav ?? '';
$isMember = !empty($user['owner_id']);
$items = [['feed', '/app', 'Лента заказов', 'Лента', 'feed'], ['products', '/app/products', 'Товары и прибыль', 'Товары', 'box']];
if (!$isMember) {
    $items[] = ['stores', '/app/stores', 'Магазины', 'Магазины', 'store'];
    $items[] = ['billing', '/app/billing', 'Тариф и оплата', 'Тариф', 'card'];
}
$items[] = ['settings', '/app/settings', 'Настройки', 'Настройки', 'settings'];
if (($user['role'] ?? '') === 'admin') {
    $items[] = ['admin', '/admin', 'Админ-панель', 'Админ', 'shield'];
}
?><!doctype html>
<html lang="ru">
<head>
<?= App\Core\View::partial('partials/head', ['title' => $title ?? '']) ?>
<meta name="robots" content="noindex">
<meta name="csrf" content="<?= e(App\Core\Csrf::token()) ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <?= App\Core\View::partial('partials/logo') ?>
    <nav class="side-nav">
      <?php foreach ($items as [$key, $href, $label, , $ico]): ?>
        <a href="<?= $href ?>" class="<?= $nav === $key ? 'active' : '' ?>"><?= icon($ico) ?> <?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="side-foot">
      <div class="plan-line">Тариф «<?= e($plan['name'] ?? '') ?>»</div>
      <div class="muted">
        <?php if ($isMember): ?>
          доступ сотрудника
        <?php elseif (!empty($plan['expires_at'])): ?>
          до <?= date_ru($plan['expires_at'], false) ?>
        <?php else: ?>
          <a href="/app/billing">Улучшить тариф</a>
        <?php endif; ?>
      </div>
      <div class="row" style="margin-top:10px;justify-content:space-between">
        <span class="muted small" title="<?= e($user['email'] ?? '') ?>" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px"><?= e($user['email'] ?? '') ?></span>
        <form method="post" action="/logout"><?= csrf_field() ?><button class="icon-btn" title="Выйти" style="width:32px;height:32px"><?= icon('logout', 16) ?></button></form>
      </div>
    </div>
  </aside>
  <main class="main">
    <?php if (App\Core\Auth::impersonator()): ?>
      <form method="post" action="/admin/impersonate/stop" class="alert alert-warn row" style="justify-content:space-between">
        <?= csrf_field() ?>
        <span>Вы в кабинете клиента <b><?= e($user['email'] ?? '') ?></b> (режим администратора).</span>
        <button class="btn btn-sm">Вернуться в админку</button>
      </form>
    <?php endif; ?>
    <?= App\Core\View::partial('partials/flash') ?>
    <?php if (!empty($plan['expired_code']) && !$isMember): ?>
      <div class="alert alert-warn">Срок тарифа «<?= e(App\Services\Plans::get($plan['expired_code'])['name']) ?>» закончился — действуют ограничения бесплатного тарифа. <a href="/app/billing">Продлить</a></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<nav class="mobile-nav">
  <?php foreach ($items as [$key, $href, , $short, $ico]): ?>
    <a href="<?= $href ?>" class="<?= $nav === $key ? 'active' : '' ?>"><?= icon($ico, 22) ?><?= e($short) ?></a>
  <?php endforeach; ?>
</nav>
<div class="toast" id="toast"></div>
<script src="<?= asset('js/app.js') ?>"></script>
</body>
</html>
