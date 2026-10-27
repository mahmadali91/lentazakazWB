<?php
$nav = $nav ?? '';
$isMember = App\Core\Auth::isMember();
// Агентство: кабинеты клиентов, к которым выдан доступ (переключатель как в WB Partners)
$grants = App\Core\Auth::grants();
$actingId = App\Core\Auth::acting();
$actingAcc = $actingId ? App\Core\Auth::account() : null;
$items = [['feed', '/app', 'Лента заказов', 'Лента', 'feed'], ['products', '/app/products', 'Товары и прибыль', 'Товары', 'box'], ['finance', '/app/finance', 'Финансы', 'Финансы', 'chart']];
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
<meta name="currency" content="<?= e(currency_sign()) ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <?= App\Core\View::partial('partials/logo', ['href' => '/app']) ?>
    <?php if ($grants): ?>
      <form method="post" action="/app/switch" class="acc-switch">
        <?= csrf_field() ?>
        <label class="muted small" for="accSw">Кабинет</label>
        <select id="accSw" name="account" onchange="this.form.submit()">
          <option value="0">Мой кабинет</option>
          <?php foreach ($grants as $g): ?>
            <option value="<?= (int) $g['owner_id'] ?>" <?= $actingId === (int) $g['owner_id'] ? 'selected' : '' ?>><?= e(($g['name'] ?: $g['email']) . ' · ID ' . client_code((int) $g['owner_id'])) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php endif; ?>
    <nav class="side-nav">
      <?php foreach ($items as [$key, $href, $label, , $ico]): ?>
        <a href="<?= $href ?>" class="<?= $nav === $key ? 'active' : '' ?>"><?= icon($ico) ?> <?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <a class="side-help" href="/help" target="_blank" rel="noopener"><?= icon('help', 18) ?> Инструкция</a>
    <div class="side-foot">
      <div class="plan-line">Тариф «<?= e($plan['name'] ?? '') ?>»</div>
      <div class="muted">
        <?php if (!empty($plan['super'])): ?>
          всё бесплатно, без ограничений
        <?php elseif ($isMember): ?>
          доступ сотрудника
        <?php elseif (!empty($plan['expires_at'])): ?>
          до <?= date_ru($plan['expires_at'], false) ?>
        <?php else: ?>
          <a href="/app/billing">Улучшить тариф</a>
        <?php endif; ?>
      </div>
      <div class="app-version" title="Версия сервиса">v<?= e(app_version()) ?><?= !empty($user['id']) ? ' · ID ' . e(client_code((int) $user['id'])) : '' ?></div>
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
    <?php if ($actingAcc): ?>
      <form method="post" action="/app/switch" class="alert alert-info row" style="justify-content:space-between">
        <?= csrf_field() ?><input type="hidden" name="account" value="0">
        <span>Кабинет клиента <b><?= e($actingAcc['name'] ?: $actingAcc['email']) ?></b> (ID <?= e(client_code((int) $actingAcc['id'])) ?>) — доступ агентства, только просмотр.</span>
        <button class="btn btn-sm btn-ghost">В свой кабинет</button>
      </form>
    <?php endif; ?>
    <?= App\Core\View::partial('partials/flash') ?>
    <?php if (!empty($plan['expired_code']) && !$isMember): ?>
      <div class="alert alert-warn">Срок тарифа «<?= e(App\Services\Plans::get($plan['expired_code'])['name']) ?>» закончился — действуют ограничения бесплатного тарифа. <a href="/app/billing">Продлить</a></div>
    <?php endif; ?>
    <?= $content ?>
  </main>
</div>
<?php
// Нижнее меню на телефоне: основные разделы + «Профиль» (тариф, настройки, админка, выход)
// «Магазины» на телефоне — в профиле
$mobile = [['feed', '/app', 'Лента', 'feed'], ['products', '/app/products', 'Товары', 'box'], ['finance', '/app/finance', 'Финансы', 'chart'], ['profile', '/app/profile', 'Профиль', 'user']];
$profileActive = in_array($nav, ['profile', 'settings', 'billing', 'admin', 'stores'], true);
?>
<nav class="mobile-nav">
  <?php foreach ($mobile as [$key, $href, $short, $ico]): ?>
    <a href="<?= $href ?>" class="<?= $nav === $key || ($key === 'profile' && $profileActive) ? 'active' : '' ?>"><?= icon($ico, 22) ?><?= e($short) ?></a>
  <?php endforeach; ?>
</nav>
<div class="toast" id="toast"></div>
<script src="<?= asset('js/app.js') ?>"></script>
<script src="<?= asset('js/forms.js') ?>"></script>
</body>
</html>
