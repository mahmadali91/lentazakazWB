<?php
use App\Services\Plans;

$statusNames = ['pending' => 'Ожидает оплаты', 'succeeded' => 'Оплачен', 'canceled' => 'Отменён', 'manual' => 'Выдан администратором'];
?>
<div class="topbar"><h1>Тариф и оплата</h1></div>

<div class="card" style="margin-bottom:20px">
  <div class="row">
    <div class="grow">
      <div class="muted small">Текущий тариф</div>
      <div style="font-size:22px;font-weight:800"><?= e($plan['name']) ?></div>
      <div class="muted">
        <?php if (!empty($plan['super'])): ?>
          Владелец сервиса: все возможности бесплатно, без срока и ограничений магазинов, сотрудников и клиентов.
        <?php elseif ($plan['expires_at']): ?>
          Действует до <?= date_ru($plan['expires_at']) ?> (осталось <?= max(0, (int) ceil((strtotime($plan['expires_at']) - time()) / 86400)) ?> дн.)
        <?php else: ?>
          Бесплатно, без срока
        <?php endif; ?>
      </div>
    </div>
    <div class="small muted" style="min-width:240px">
      Магазинов: <b><?= !empty($plan['super']) ? '∞' : (int) $plan['stores'] ?></b><?= $plan['extra_stores'] ? ' (в т. ч. доп.: ' . (int) $plan['extra_stores'] . ')' : '' ?> · История: <b><?= (int) $plan['history_days'] ?> дн.</b><br>
      Обновление: <b>каждые <?= (int) $plan['sync_minutes'] ?> мин</b> · Telegram: <b><?= $plan['telegram'] ? 'да' : 'нет' ?></b> · Excel: <b><?= $plan['export'] ? 'да' : 'нет' ?></b>
    </div>
  </div>
</div>

<?php if (!empty($plan['super'])) { return; } ?>
<?php if (!$payEnabled): ?>
  <div class="alert alert-info">Онлайн-оплата скоро появится. Чтобы подключить тариф сейчас, свяжитесь с нами: <?php if (support_telegram() !== ''): ?><a href="https://t.me/<?= e(support_telegram()) ?>" target="_blank" rel="noopener">Telegram @<?= e(support_telegram()) ?></a> · <?php endif; ?><a href="mailto:<?= e(config('app.support_email')) ?>"><?= e(config('app.support_email')) ?></a></div>
<?php endif; ?>

<?php
// Докупка магазинов к действующему тарифу: цена магазина × оставшиеся дни / 30
$canAddon = !empty($plan['store_price']) && $plan['expires_at'] && $plan['extra_stores'] < Plans::maxExtra($plan);
if ($canAddon): $left = Plans::maxExtra($plan) - $plan['extra_stores']; $days = Plans::daysLeft($plan); ?>
  <div class="card addon-card" id="add-store" style="margin-bottom:20px">
    <div class="card-title"><?= icon('plus', 18) ?> Добавить магазин к тарифу «<?= e($plan['name']) ?>»</div>
    <p class="muted small">Сейчас магазинов: <b><?= (int) $plan['stores'] ?></b><?= $left > 50 ? ', без ограничений' : ' из ' . (int) $plan['stores_max'] . ' возможных' ?>. Дополнительный магазин — <?= rub($plan['store_price']) ?> в месяц;
      оплата — только за оставшийся срок подписки (<?= $days ?> <?= plural($days, 'день', 'дня', 'дней') ?>, до <?= date_ru($plan['expires_at'], false) ?>). При продлении тарифа доп. магазины продлеваются вместе с ним.</p>
    <form method="post" action="/app/billing/stores" class="row" style="gap:10px;flex-wrap:wrap;align-items:center">
      <?= csrf_field() ?>
      <?php if ($left > 10): ?>
        <label class="small muted">Сколько магазинов добавить:
          <input type="number" name="qty" id="addonQty" min="1" max="<?= $left ?>" value="1" style="width:90px;margin-left:6px"
            data-unit="<?= e((string) Plans::addonPrice($user, 1)) ?>" data-each="<?= e((string) ((float) $plan['store_price'] * Plans::daysLeft($plan) / 30)) ?>"></label>
      <?php else: ?>
        <select name="qty" style="width:auto" id="addonQty">
          <?php for ($q = 1; $q <= $left; $q++): ?>
            <option value="<?= $q ?>" data-price="<?= e(number_format((float) Plans::addonPrice($user, $q), 0, ',', ' ')) ?>">+<?= $q ?> <?= plural($q, 'магазин', 'магазина', 'магазинов') ?></option>
          <?php endfor; ?>
        </select>
      <?php endif; ?>
      <span style="font-weight:700;font-size:18px"><span id="addonPrice"><?= number_format((float) Plans::addonPrice($user, 1), 0, ',', ' ') ?></span> ₽</span>
      <button class="btn" <?= $payEnabled ? '' : 'disabled' ?>>Оплатить</button>
    </form>
  </div>
<?php endif; ?>

<div class="row" style="margin-bottom:14px">
  <span class="muted">Срок оплаты:</span>
  <div class="seg" id="periodSeg">
    <?php foreach (Plans::PERIODS as $m => $d): ?>
      <button type="button" data-months="<?= $m ?>" class="<?= $m === 1 ? 'on' : '' ?>"><?= $m ?> мес<?= $d ? " · −$d%" : '' ?></button>
    <?php endforeach; ?>
  </div>
</div>

<div class="plans" style="margin-bottom:28px">
  <?php foreach ($plans as $code => $p): $current = $plan['code'] === $code; $maxExtra = Plans::maxExtra($p + ['code' => $code]);
    // Для тарифа с докупкой по умолчанию — столько магазинов, сколько у клиента сейчас
    $selStores = $current ? (int) $plan['stores'] : (int) $p['stores']; ?>
    <div class="card plan <?= !empty($p['popular']) ? 'popular' : '' ?>" <?= $maxExtra ? 'data-base="' . (int) $p['price'] . '" data-store="' . (int) $p['store_price'] . '" data-incl="' . (int) $p['stores'] . '"' : '' ?>>
      <?php if (!empty($p['popular'])): ?><span class="badge badge-accent tag">Популярный</span><?php endif; ?>
      <div class="plan-name"><?= e($p['name']) ?></div>
      <?php if (!empty($p['tagline'])): ?><div class="plan-tagline"><?= e($p['tagline']) ?></div><?php endif; ?>
      <div class="plan-price">
        <?php foreach (Plans::PERIODS as $m => $d): ?>
          <span class="price-for <?= $m === 1 ? '' : 'hidden' ?>" data-months="<?= $m ?>" data-discount="<?= (int) $d ?>"><b class="pv"><?= number_format(Plans::price($code, $m, $selStores - (int) $p['stores']), 0, ',', ' ') ?></b> ₽ <small><?= $m === 1 ? '/ мес' : "за $m мес" ?></small></span>
        <?php endforeach; ?>
      </div>
      <ul><?php foreach ($p['features'] as $feat): ?><li><?= e($feat) ?></li><?php endforeach; ?></ul>
      <form method="post" action="/app/billing/pay">
        <?= csrf_field() ?>
        <input type="hidden" name="plan" value="<?= e($code) ?>">
        <input type="hidden" name="months" value="1" class="months-input">
        <?php if ($maxExtra > 10): ?>
          <label class="small muted" style="display:block;margin-bottom:10px">Магазинов:
            <input type="number" name="stores" class="plan-stores" min="<?= (int) $p['stores'] ?>" max="<?= (int) $p['stores'] + $maxExtra ?>" value="<?= $selStores ?>" style="width:90px;margin-left:6px">
          </label>
        <?php elseif ($maxExtra): ?>
          <label class="small muted" style="display:block;margin-bottom:10px">Магазинов:
            <select name="stores" class="plan-stores" style="width:auto;margin-left:6px">
              <?php for ($n = (int) $p['stores']; $n <= (int) $p['stores'] + $maxExtra; $n++): ?>
                <option value="<?= $n ?>" <?= $n === $selStores ? 'selected' : '' ?>><?= $n ?><?= $n > (int) $p['stores'] ? ' (+' . number_format(($n - (int) $p['stores']) * (float) $p['store_price'], 0, ',', ' ') . ' ₽/мес)' : '' ?></option>
              <?php endfor; ?>
            </select>
          </label>
        <?php endif; ?>
        <?php if ($current): ?><div class="plan-current"><span class="badge badge-buyout">Ваш тариф</span></div><?php endif; ?>
        <button class="btn btn-block <?= !empty($p['popular']) ? '' : 'btn-ghost' ?>" <?= $payEnabled ? '' : 'disabled' ?>><?= $current ? 'Продлить' : 'Оплатить' ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<div class="card" id="payments" style="margin-bottom:20px">
  <div class="card-title"><?= icon('card', 18) ?> История платежей</div>
  <?php if (!$payments): ?>
    <p class="muted small">Платежей пока нет. После оплаты здесь появятся дата, оплаченный период, сумма и карта.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Дата</th><th>Что оплачено</th><th>Период действия</th><th class="num">Сумма</th><th>Способ оплаты</th><th>Статус</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $pay): $kind = $pay['kind'] ?? 'plan'; ?>
            <tr>
              <td class="nowrap"><?= date_ru($pay['paid_at'] ?: $pay['created_at']) ?></td>
              <td>
                <?= $kind === 'transfer' ? 'Передача аккаунта' : e(Plans::get($pay['plan_code'])['name']) ?>
                <div class="small muted"><?php
                  if ($kind === 'stores') {
                      echo 'доп. магазин ×' . (int) $pay['extra_stores'] . ' до конца подписки';
                  } elseif ($kind === 'transfer') {
                      echo 'новый аккаунт на тарифе «' . e(Plans::get($pay['plan_code'])['name']) . '»';
                  } else {
                      echo (int) $pay['months'] ? (int) $pay['months'] . ' мес' : 'тариф';
                      echo (int) ($pay['extra_stores'] ?? 0) ? ' + ' . (int) $pay['extra_stores'] . ' доп. маг.' : '';
                  }
                ?></div>
              </td>
              <td class="nowrap"><?= $pay['period_from'] ? date_ru($pay['period_from'], false) . ' — ' . ($pay['period_to'] ? date_ru($pay['period_to'], false) : '…') : '<span class="muted">—</span>' ?></td>
              <td class="num nowrap"><?= rub($pay['amount']) ?></td>
              <td class="small"><?= $pay['card'] ? e($pay['card']) : '<span class="muted">—</span>' ?></td>
              <td><span class="badge <?= $pay['status'] === 'succeeded' || $pay['status'] === 'manual' ? 'badge-buyout' : ($pay['status'] === 'canceled' ? 'badge-cancel' : 'badge-created') ?>"><?= e($statusNames[$pay['status']] ?? $pay['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small muted" style="margin-top:8px">Номер карты ЮKassa передаёт только маской — полные данные карты сервис не хранит и не видит.</p>
  <?php endif; ?>
</div>

<div class="card" id="refund">
  <div class="card-title"><?= icon('shield', 18) ?> Условия возврата</div>
  <?= App\Core\View::partial('partials/refund_terms') ?>
</div>

<script>
  document.getElementById('periodSeg').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var m = b.getAttribute('data-months');
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
    document.querySelectorAll('.price-for').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-months') !== m); });
    document.querySelectorAll('.months-input').forEach(function (el) { el.value = m; });
  });
  // Цена тарифа с доп. магазинами: (цена + доп. магазины × цена магазина) × месяцы − скидка
  function planPrice(card) {
    var sel = card.querySelector('.plan-stores'); if (!sel) return;
    var extra = Math.max(0, Math.min(+sel.max || 1e9, +sel.value || 0) - +card.dataset.incl), month = +card.dataset.base + extra * +card.dataset.store;
    card.querySelectorAll('.price-for').forEach(function (el) {
      var m = +el.dataset.months, d = +el.dataset.discount;
      el.querySelector('.pv').textContent = Math.round(month * m * (100 - d) / 100).toLocaleString('ru-RU');
    });
  }
  document.querySelectorAll('.plan-stores').forEach(function (s) { s.addEventListener('input', function () { planPrice(s.closest('.plan')); }); });
  var aq = document.getElementById('addonQty');
  if (aq) aq.addEventListener('input', function () {
    // Как на сервере: цена магазина × дни / 30 × количество, с округлением вверх
    var price = aq.tagName === 'SELECT' ? aq.selectedOptions[0].dataset.price
      : Math.max(1, Math.ceil(+aq.dataset.each * Math.max(1, Math.min(+aq.max, +aq.value || 1)))).toLocaleString('ru-RU');
    document.getElementById('addonPrice').textContent = price;
  });
</script>
