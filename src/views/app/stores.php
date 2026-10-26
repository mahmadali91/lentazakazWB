<?php
use App\Services\StoreService;

$canAdd = count($stores) < (int) $plan['stores'];
?>
<div class="topbar">
  <h1>Магазины</h1>
  <span class="muted small">Подключено <?= count($stores) ?> из <?= (int) $plan['stores'] ?></span>
</div>

<div class="stack page-narrow">
  <?php foreach ($stores as $s):
      $scopes = json_decode((string) $s['scopes'], true) ?: [];
      $paused = !in_array((int) $s['id'], $activeIds, true);
  ?>
    <div class="card store">
      <div class="store-head">
        <div class="logo-mark" style="width:40px;height:40px"><?= icon('store', 20) ?></div>
        <div class="grow" style="min-width:0">
          <div class="store-name"><?= e($s['name']) ?></div>
          <div class="muted small"><?= e($s['seller_name']) ?><?= $s['seller_tin'] ? ' · ИНН ' . e($s['seller_tin']) : '' ?> · токен <?= e($s['token_hint']) ?></div>
        </div>
        <?php if (!(int) $s['is_active']): ?>
          <span class="badge">Приостановлен</span>
        <?php elseif ($paused): ?>
          <span class="badge badge-return">Сверх лимита тарифа</span>
        <?php elseif ($s['last_error']): ?>
          <span class="badge badge-cancel">Ошибка</span>
        <?php elseif (!(int) $s['initial_done']): ?>
          <span class="badge badge-created">Загрузка…</span>
        <?php else: ?>
          <span class="badge badge-buyout">Работает</span>
        <?php endif; ?>
      </div>

      <?php if ($s['last_error']): ?>
        <div class="alert alert-error" style="margin:0"><?= e($s['last_error']) ?> <span class="small">(<?= date_ru($s['last_error_at']) ?>)</span></div>
      <?php endif; ?>
      <?php if ($s['token_expires_at'] && strtotime($s['token_expires_at']) < time() + 14 * 86400): ?>
        <div class="alert alert-warn" style="margin:0">Токен WB истекает <?= date_ru($s['token_expires_at'], false) ?>. Создайте новый и обновите его ниже.</div>
      <?php endif; ?>

      <div class="scopes">
        <?php foreach (StoreService::SCOPE_LABELS as $k => $label): $on = !empty($scopes[$k]); $unknown = !array_key_exists($k, $scopes); ?>
          <div class="scope <?= $on ? 'on' : 'off' ?>" <?= $unknown ? 'title="Проверим при первой загрузке финансов"' : '' ?>><span class="dot"><?= $on ? '✓' : ($unknown ? '?' : '–') ?></span><span><?= e($label) ?></span></div>
        <?php endforeach; ?>
      </div>

      <div class="row small muted">
        <span>Заказов в базе: <b><?= number_format($counts[$s['id']] ?? 0, 0, ',', ' ') ?></b></span>
        <?php if ($s['feed_ok_at']): ?><span>Лента: <?= date_ru($s['feed_ok_at']) ?></span><?php endif; ?>
        <?php if ($s['mp_ok_at']): ?><span>Сборочные задания: <?= date_ru($s['mp_ok_at']) ?></span><?php endif; ?>
        <?php if ($s['stats_ok_at']): ?><span>Статистика: <?= date_ru($s['stats_ok_at']) ?></span><?php endif; ?>
      </div>

      <div class="row small muted" style="margin-top:6px">Налог <?= e(num_ru((float) $s['tax_rate'], 1)) ?>%<?= (float) $s['vat_rate'] > 0 ? ' + НДС ' . e(num_ru((float) $s['vat_rate'], 0)) . '%' : '' ?><?= (float) $s['weekly_expense'] > 0 ? ' · фиксированные расходы ' . e(money((float) $s['weekly_expense'])) . ' в неделю' : ' · фиксированные расходы не указаны' ?></div>
      <details class="inline">
        <summary>Управление магазином</summary>
        <div class="stack">
          <form method="post" action="/app/stores/<?= (int) $s['id'] ?>/rename" class="row">
            <?= csrf_field() ?>
            <input type="text" name="name" value="<?= e($s['name']) ?>" style="max-width:320px" aria-label="Название">
            <button class="btn btn-ghost btn-sm">Переименовать</button>
          </form>
          <form method="post" action="/app/stores/<?= (int) $s['id'] ?>/finance">
            <?= csrf_field() ?>
            <label style="font-weight:600">Налог и фиксированные расходы</label>
            <div class="row store-fin">
            <div class="field"><label>Налог, %</label><input type="text" inputmode="decimal" name="tax_rate" value="<?= e(rtrim(rtrim(number_format((float) $s['tax_rate'], 2, '.', ''), '0'), '.')) ?>" placeholder="6"><div class="hint">УСН «доходы» — 6%, без налога — 0</div></div>
            <div class="field"><label>НДС, %</label><input type="text" inputmode="decimal" name="vat_rate" value="<?= e(rtrim(rtrim(number_format((float) $s['vat_rate'], 2, '.', ''), '0'), '.')) ?>" placeholder="0"><div class="hint">0 — без НДС</div></div>
            <div class="field grow"><label>Фиксированные расходы в неделю, <?= e(currency_sign(($s ?? [])['currency'] ?? null ?: 'RUB')) ?></label><input type="text" inputmode="decimal" name="weekly_expense" value="<?= $s['weekly_expense'] !== null ? e(rtrim(rtrim(number_format((float) $s['weekly_expense'], 2, '.', ''), '0'), '.')) : '' ?>" placeholder="Например: 15 000"><div class="hint">Зарплата, аренда, фулфилмент, сервисы — вычитается из прибыли за каждую неделю в «Финансах»</div></div>
          </div>
            <button class="btn btn-sm">Сохранить</button>
          </form>
          <form method="post" action="/app/stores/<?= (int) $s['id'] ?>/token">
            <?= csrf_field() ?>
            <label>Новый токен WB</label>
            <textarea name="token" required placeholder="Вставьте новый токен, если старый истёк или вы добавили категории"></textarea>
            <button class="btn btn-sm" style="margin-top:8px">Проверить и сохранить токен</button>
          </form>
          <div class="row">
            <form method="post" action="/app/stores/<?= (int) $s['id'] ?>/toggle"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= (int) $s['is_active'] ? 'Приостановить' : 'Возобновить' ?></button></form>
            <form method="post" action="/app/stores/<?= (int) $s['id'] ?>/resync" data-confirm="Перезагрузить все заказы магазина из WB заново?"><?= csrf_field() ?><button class="btn btn-ghost btn-sm"><?= icon('refresh', 14) ?> Перезагрузить заказы</button></form>
            <form method="post" action="/app/stores/<?= (int) $s['id'] ?>/delete" data-confirm="Удалить магазин, его токен и все заказы? Это необратимо."><?= csrf_field() ?><button class="btn btn-danger btn-sm">Удалить</button></form>
          </div>
        </div>
      </details>
    </div>
  <?php endforeach; ?>

  <div class="card" id="add">
    <div class="card-title"><?= icon('plus', 18) ?> Подключить магазин WB</div>
    <?php if (!$canAdd): ?>
      <p class="muted">На тарифе «<?= e($plan['name']) ?>» можно подключить <?= (int) $plan['stores'] ?> <?= plural((int) $plan['stores'], 'магазин', 'магазина', 'магазинов') ?>.</p>
      <a class="btn" href="/app/billing">Увеличить лимит</a>
    <?php else: ?>
      <div class="guide" style="margin-bottom:18px">
        <ol>
          <li>Откройте <a href="https://seller.wildberries.ru/supplier-settings/access-to-api" target="_blank" rel="noopener">кабинет продавца → Настройки → Доступ к API</a> и нажмите <span class="kbd">Создать новый токен</span>.</li>
          <li>Тип токена — <b>«Персональный»</b> (у «Базового» WB разрешает запрос ленты только раз в 3 часа).</li>
          <li>Отметьте категории: <b>Аналитика</b>, <b>Статистика</b>, <b>Маркетплейс</b>, <b>Контент</b>, а для раздела «Финансы» — ещё <b>Финансы</b> и <b>Продвижение</b>. Обязательна хотя бы «Аналитика» или «Статистика».</li>
          <li>Включите <b>«Только на чтение»</b> — так сервис не сможет ничего изменить в вашем кабинете.</li>
          <li>Скопируйте токен и вставьте его ниже.</li>
        </ol>
      </div>
      <form method="post" action="/app/stores">
        <?= csrf_field() ?>
        <div class="field"><label for="sname">Название (необязательно)</label><input id="sname" type="text" name="name" value="<?= e(old('name')) ?>" placeholder="Например: Основной кабинет"></div>
        <div class="field"><label for="token">Токен API WB</label><textarea id="token" name="token" required placeholder="eyJhbGciOiJFUzI1NiIsImtpZCI6…"></textarea>
          <div class="hint"><?= icon('lock', 12) ?> Токен проверяется на всех сервисах WB и хранится в зашифрованном виде.</div></div>
        <div class="row store-fin">
            <div class="field"><label>Налог, %</label><input type="text" inputmode="decimal" name="tax_rate" value="<?= e(old('tax_rate') ?: '6') ?>" placeholder="6"><div class="hint">УСН «доходы» — 6%, без налога — 0</div></div>
            <div class="field"><label>НДС, %</label><input type="text" inputmode="decimal" name="vat_rate" value="<?= e(old('vat_rate') ?: '0') ?>" placeholder="0"><div class="hint">0 — без НДС</div></div>
            <div class="field grow"><label>Фиксированные расходы в неделю, ₽</label><input type="text" inputmode="decimal" name="weekly_expense" value="<?= e(old('weekly_expense')) ?>" placeholder="Например: 15 000"><div class="hint">Зарплата, аренда, фулфилмент, сервисы — вычитается из прибыли за каждую неделю в «Финансах»</div></div>
          </div>
        <button class="btn btn-lg">Проверить токен и подключить</button>
      </form>
    <?php endif; ?>
  </div>
</div>
