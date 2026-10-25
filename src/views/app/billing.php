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
        <?php if ($plan['expires_at']): ?>
          Действует до <?= date_ru($plan['expires_at']) ?> (осталось <?= max(0, (int) ceil((strtotime($plan['expires_at']) - time()) / 86400)) ?> дн.)
        <?php else: ?>
          Бесплатно, без срока
        <?php endif; ?>
      </div>
    </div>
    <div class="small muted" style="min-width:240px">
      Магазинов: <b><?= (int) $plan['stores'] ?></b> · История: <b><?= (int) $plan['history_days'] ?> дн.</b><br>
      Обновление: <b>каждые <?= (int) $plan['sync_minutes'] ?> мин</b> · Telegram: <b><?= $plan['telegram'] ? 'да' : 'нет' ?></b> · Excel: <b><?= $plan['export'] ? 'да' : 'нет' ?></b>
    </div>
  </div>
</div>

<?php if (!$payEnabled): ?>
  <div class="alert alert-info">Онлайн-оплата скоро появится. Чтобы подключить тариф сейчас, напишите нам: <a href="mailto:<?= e(config('app.support_email')) ?>"><?= e(config('app.support_email')) ?></a></div>
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
  <?php foreach ($plans as $code => $p): $current = $plan['code'] === $code; ?>
    <div class="card plan <?= !empty($p['popular']) ? 'popular' : '' ?>">
      <?php if (!empty($p['popular'])): ?><span class="badge badge-accent tag">Популярный</span><?php endif; ?>
      <div class="plan-name"><?= e($p['name']) ?> <?= $current ? '<span class="badge badge-buyout">Ваш тариф</span>' : '' ?></div>
      <div class="plan-price">
        <?php foreach (Plans::PERIODS as $m => $d): ?>
          <span class="price-for <?= $m === 1 ? '' : 'hidden' ?>" data-months="<?= $m ?>"><?= number_format(Plans::price($code, $m), 0, ',', ' ') ?> ₽ <small><?= $m === 1 ? '/ мес' : "за $m мес" ?></small></span>
        <?php endforeach; ?>
      </div>
      <ul><?php foreach ($p['features'] as $feat): ?><li><?= e($feat) ?></li><?php endforeach; ?></ul>
      <form method="post" action="/app/billing/pay">
        <?= csrf_field() ?>
        <input type="hidden" name="plan" value="<?= e($code) ?>">
        <input type="hidden" name="months" value="1" class="months-input">
        <button class="btn btn-block <?= !empty($p['popular']) ? '' : 'btn-ghost' ?>" <?= $payEnabled ? '' : 'disabled' ?>><?= $current ? 'Продлить' : 'Оплатить' ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($payments): ?>
  <div class="card">
    <div class="card-title">История платежей</div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Дата</th><th>Тариф</th><th>Срок</th><th>Сумма</th><th>Статус</th></tr></thead>
        <tbody>
          <?php foreach ($payments as $pay): ?>
            <tr>
              <td><?= date_ru($pay['created_at']) ?></td>
              <td><?= e(Plans::get($pay['plan_code'])['name']) ?></td>
              <td><?= (int) $pay['months'] ? (int) $pay['months'] . ' мес' : '—' ?></td>
              <td><?= money($pay['amount']) ?></td>
              <td><span class="badge <?= $pay['status'] === 'succeeded' || $pay['status'] === 'manual' ? 'badge-buyout' : ($pay['status'] === 'canceled' ? 'badge-cancel' : 'badge-created') ?>"><?= e($statusNames[$pay['status']] ?? $pay['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
  document.getElementById('periodSeg').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    var m = b.getAttribute('data-months');
    this.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
    document.querySelectorAll('.price-for').forEach(function (el) { el.classList.toggle('hidden', el.getAttribute('data-months') !== m); });
    document.querySelectorAll('.months-input').forEach(function (el) { el.value = m; });
  });
</script>
