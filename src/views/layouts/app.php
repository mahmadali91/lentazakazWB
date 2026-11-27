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
    if (empty($user['is_super'])) {
        $items[] = ['billing', '/app/billing', 'Тариф и оплата', 'Тариф', 'card'];
    }
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
<script>try { if (localStorage.getItem('side') === 'min') document.documentElement.classList.add('side-min'); } catch (e) {}</script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="side-head">
      <?= App\Core\View::partial('partials/logo', ['href' => '/app']) ?>
      <button type="button" class="side-toggle" id="sideToggle" title="Свернуть меню" aria-label="Свернуть или развернуть меню"><?= icon('sidebar', 18) ?></button>
    </div>
    <?= App\Core\View::partial('partials/switcher', ['nav' => $nav]) ?>
    <nav class="side-nav">
      <?php foreach ($items as [$key, $href, $label, , $ico]): ?>
        <a href="<?= $href ?>" class="<?= $nav === $key ? 'active' : '' ?>" title="<?= e($label) ?>"><?= icon($ico) ?> <span class="sn-label"><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <a class="side-help" href="/help" target="_blank" rel="noopener" title="Инструкция"><?= icon('help') ?> <span class="sn-label">Инструкция</span></a>
    <?php
      $isAdmin = ($user['role'] ?? '') === 'admin' || !empty($user['is_super']);
      $exp = !empty($plan['expires_at']) ? (int) ceil((strtotime($plan['expires_at']) - time()) / 86400) : null;
      $who = (string) ($user['name'] ?? '') !== '' ? $user['name'] : ($user['email'] ?? '');
    ?>
    <div class="side-foot">
      <div class="sf-plan">
        <span class="sf-plan-name">«<?= e($plan['name'] ?? '') ?>»</span>
        <?php if (!empty($plan['super'])): ?>
          <span class="sf-note">всё бесплатно, без ограничений</span>
        <?php elseif ($isMember): ?>
          <span class="sf-note"><?= App\Core\Auth::actingKind() === 'client' ? 'доступ клиента' : (App\Core\Auth::acting() ? 'доступ агентства' : 'доступ сотрудника') ?></span>
        <?php elseif ($exp !== null): ?>
          <span class="sf-note <?= $exp <= 7 ? 'sf-warn' : '' ?>">до <?= date('d.m.Y', strtotime($plan['expires_at'])) ?> · <?= $exp ?> <?= plural($exp, 'день', 'дня', 'дней') ?></span>
        <?php else: ?>
          <a class="sf-note" href="/app/billing">Улучшить тариф</a>
        <?php endif; ?>
      </div>
      <div class="sf-user">
        <span class="sf-avatar" title="<?= e($who . (!empty($user['id']) ? ' · ID ' . client_code((int) $user['id']) : '')) ?>"><?= e(mb_strtoupper(mb_substr($who, 0, 1))) ?></span>
        <span class="sf-who">
          <span class="sf-email" title="<?= e($user['email'] ?? '') ?>"><?= e($who) ?></span>
          <?php if (!empty($user['id'])): ?><span class="sf-id">ID <?= e(client_code((int) $user['id'])) ?></span><?php endif; ?>
        </span>
        <form method="post" action="/logout"><?= csrf_field() ?><button class="icon-btn" title="Выйти" aria-label="Выйти"><?= icon('logout', 16) ?></button></form>
      </div>
      <div class="sf-ver" title="Версия сервиса"><?= $isAdmin ? 'v' . e(app_version()) : 'v' . e(APP_VERSION) ?></div>
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
        <?php if (App\Core\Auth::actingKind() === 'client'): ?>
          <span>Кабинет <b><?= e(App\Services\Contacts::of($actingAcc)['name'] ?? ($actingAcc['name'] ?: $actingAcc['email'])) ?></b> — доступ к вашим магазинам: себестоимость, налог и расходы ведёте вы.</span>
        <?php else: ?>
          <span>Кабинет клиента <b><?= e($actingAcc['name'] ?: $actingAcc['email']) ?></b> (ID <?= e(client_code((int) $actingAcc['id'])) ?>) — доступ агентства, только просмотр.</span>
        <?php endif; ?>
        <?php if (!App\Core\Auth::clientOnly()): ?><button class="btn btn-sm btn-ghost">В свой кабинет</button><?php endif; ?>
      </form>
    <?php endif; ?>
    <?= App\Core\View::partial('partials/switcher', ['nav' => $nav, 'mobile' => true]) ?>
    <?= App\Core\View::partial('partials/flash') ?>
    <?php if (!empty($user['id']) && empty($user['owner_id'])): foreach (App\Services\StoreClients::pendingFor((int) $user['id']) as $inv):
        $from = App\Services\Contacts::of(['contacts' => $inv['contacts']])['name'] ?? ($inv['owner_name'] ?: $inv['owner_email']); ?>
      <div class="alert alert-info row invite-alert" style="justify-content:space-between">
        <span><b><?= e($from) ?></b> открывает вам доступ к магазину <b>«<?= e($inv['store_name']) ?>»</b>: лента, товары и финансы.</span>
        <span class="row" style="gap:8px">
          <form method="post" action="/access/<?= (int) $inv['id'] ?>/accept"><?= csrf_field() ?><button class="btn btn-sm">Принять</button></form>
          <form method="post" action="/access/<?= (int) $inv['id'] ?>/decline"><?= csrf_field() ?><button class="btn btn-sm btn-ghost">Отклонить</button></form>
        </span>
      </div>
    <?php endforeach; endif; ?>
    <?php if ($isMember && (!$actingAcc || App\Core\Auth::actingKind() === 'client') && ($ownerAcc = App\Core\Auth::account())):
      // Клиенту агентства: срок доступа подходит к концу — продление через владельца кабинета, его контакты
      $ownerPlan = App\Services\Plans::forUser($ownerAcc);
      $leftDays = $ownerPlan['expires_at'] ? (int) ceil((strtotime($ownerPlan['expires_at']) - time()) / 86400) : null;
      if ($ownerPlan['expired_code'] || ($leftDays !== null && $leftDays <= 7)): $ownerContacts = App\Services\Contacts::html($ownerAcc, true); ?>
      <div class="alert alert-warn"><?= $ownerPlan['expired_code'] ? 'Срок доступа к кабинету закончился' : 'Доступ к кабинету действует до ' . date_ru($ownerPlan['expires_at'], false) ?>.
        Для продления напишите владельцу кабинета<?= $ownerContacts !== '' ? ': ' . $ownerContacts : '' ?>.</div>
    <?php endif; endif; ?>
    <?php if (!empty($plan['unpaid_code']) && !$isMember): ?>
      <div class="alert alert-warn">Тариф «<?= e(App\Services\Plans::get($plan['unpaid_code'])['name']) ?>» работает только после оплаты — пробного периода у него нет. <a href="/app/billing">Оплатить</a></div>
    <?php endif; ?>
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
<script>
  // Боковое меню: свернуть до иконок / развернуть (запоминаем выбор)
  (function () {
    var root = document.documentElement, btn = document.getElementById('sideToggle');
    function sync() { var min = root.classList.contains('side-min'); btn.title = min ? 'Развернуть меню' : 'Свернуть меню'; btn.setAttribute('aria-expanded', min ? 'false' : 'true'); }
    function set(min) { root.classList.toggle('side-min', min); try { localStorage.setItem('side', min ? 'min' : 'full'); } catch (e) {} sync(); }
    if (btn) { sync(); btn.addEventListener('click', function () { set(!root.classList.contains('side-min')); }); }
    document.querySelectorAll('[data-side-expand]').forEach(function (b) { b.addEventListener('click', function () { set(false); }); });
  })();
</script>
</body>
</html>
