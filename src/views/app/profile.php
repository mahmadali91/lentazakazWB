<?php
$isMember = App\Core\Auth::isMember();
$display = $user['name'] ?: $user['email'];
$initial = mb_strtoupper(mb_substr($display, 0, 1));
?>
<div class="topbar"><h1>Профиль</h1></div>

<div class="stack page-narrow">
  <?php $grants = App\Core\Auth::grants(); if ($grants): $actingId = App\Core\Auth::acting(); ?>
    <form method="post" action="/app/switch" class="card acc-switch" style="margin:0">
      <?= csrf_field() ?>
      <label class="muted small" for="accSwM">Кабинет (доступ агентства)</label>
      <select id="accSwM" name="account" onchange="this.form.submit()">
        <option value="0">Мой кабинет</option>
        <?php foreach ($grants as $g): ?>
          <option value="<?= (int) $g['owner_id'] ?>" <?= $actingId === (int) $g['owner_id'] ? 'selected' : '' ?>><?= e(($g['name'] ?: $g['email']) . ' · ID ' . client_code((int) $g['owner_id'])) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>
  <div class="card">
    <div class="profile-head">
      <div class="avatar"><?= e($initial) ?></div>
      <div style="min-width:0">
        <div style="font-weight:700;font-size:18px;overflow:hidden;text-overflow:ellipsis"><?= e($display) ?></div>
        <div class="muted small" style="overflow:hidden;text-overflow:ellipsis"><?= e($user['email']) ?> · ID клиента <b><?= e(client_code((int) $user['id'])) ?></b></div>
        <?php if ($isMember): ?>
          <div class="small">Сотрудник кабинета <b><?= e($account['name'] ?: $account['email']) ?></b></div>
        <?php endif; ?>
      </div>
    </div>
    <div class="row" style="margin-top:16px;justify-content:space-between;padding:12px 14px;border-radius:var(--radius-sm);background:var(--surface-2)">
      <div>
        <div class="muted small">Тариф</div>
        <b>«<?= e($plan['name']) ?>»</b>
        <?php if (!empty($plan['expires_at'])): ?><span class="muted small">до <?= date_ru($plan['expires_at'], false) ?></span><?php endif; ?>
      </div>
      <?php if (!$isMember && empty($plan['super'])): ?><a class="btn btn-sm" href="/app/billing"><?= !empty($plan['expires_at']) ? 'Продлить' : 'Улучшить' ?></a><?php endif; ?>
    </div>
  </div>

  <div class="card menu-list">
    <?php if (!$isMember): ?>
      <a href="/app/billing"><?= icon('card') ?> Тариф и оплата <span class="chev"><?= icon('chevron', 18) ?></span></a>
      <a href="/app/stores"><?= icon('store') ?> Магазины WB <span class="chev"><?= icon('chevron', 18) ?></span></a>
    <?php endif; ?>
    <a href="/app/settings"><?= icon('settings') ?> Настройки, Telegram<?= $isMember ? '' : ', сотрудники' ?> <span class="chev"><?= icon('chevron', 18) ?></span></a>
    <?php if (($user['role'] ?? '') === 'admin'): ?>
      <a href="/admin"><?= icon('shield') ?> Админ-панель <span class="chev"><?= icon('chevron', 18) ?></span></a>
    <?php endif; ?>
    <a href="/help"><?= icon('help') ?> Инструкция по сервису <span class="chev"><?= icon('chevron', 18) ?></span></a>
    <?php if (support_telegram() !== ''): ?>
      <a href="https://t.me/<?= e(support_telegram()) ?>" target="_blank" rel="noopener"><?= icon('send') ?> Telegram-канал: новости и поддержка <span class="chev"><?= icon('chevron', 18) ?></span></a>
    <?php endif; ?>
    <?php if (config('app.support_email')): ?>
      <a href="mailto:<?= e(config('app.support_email')) ?>"><?= icon('mail') ?> Написать в поддержку <span class="chev"><?= icon('chevron', 18) ?></span></a>
    <?php endif; ?>
    <button type="button" id="themeBtn"><?= icon('moon') ?> Светлая / тёмная тема</button>
    <form method="post" action="/logout"><?= csrf_field() ?><button class="danger"><?= icon('logout') ?> Выйти из аккаунта</button></form>
  </div>
  <div class="app-version" style="text-align:center">Лента заказов WB · версия <?= e(app_version()) ?></div>
</div>
