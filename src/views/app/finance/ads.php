<?php
$total = array_sum(array_map(static fn($c) => (float) $c['amount'], $camps));

$advertOff = array_filter($status, static fn($s) => $s['advert'] === false);
?>
<?php if ($advertOff): ?>
  <div class="alert alert-warn">У токена нет категории «Продвижение» — расходы на рекламу не загружаются. Добавьте категорию в новый токен или внесите рекламу вручную на вкладке <a href="/app/finance/expenses<?= $qs() ?>">«Прочие расходы»</a> (статья «Внешняя реклама», с артикулом — попадёт на товар).</div>
<?php endif; ?>
<div class="kpis">
  <div class="card kpi"><div class="k-label">Реклама WB за период</div><div class="k-value"><?= money($total) ?></div><div class="k-sub"><?= count($camps) ?> <?= plural(count($camps), 'кампания', 'кампании', 'кампаний') ?></div></div>
  <div class="card kpi"><div class="k-label">Разнесено по товарам</div><div class="k-value"><?= money(array_sum($bySku)) ?></div><div class="k-sub"><?= count($bySku) ?> <?= plural(count($bySku), 'товар', 'товара', 'товаров') ?></div></div>
</div>
<?php if (!$camps): ?>
  <div class="card empty"><h3>Нет списаний за рекламу</h3><p>Списания загружаются из WB раз в 6 часов (метод «История затрат»).</p></div>
<?php return; endif; ?>
<div class="fin-grid">
  <div class="card" style="padding:0">
    <div class="card-title" style="padding:14px 16px 0">Кампании</div>
    <div class="table-wrap">
      <table class="table fin-table">
        <thead><tr><th>Кампания</th><th>Оплата</th><th class="num">Списаний</th><th class="num">Сумма, <?= currency_sign() ?></th><th>Товары</th></tr></thead>
        <tbody>
        <?php foreach ($camps as $c): $nms = array_filter(explode(',', (string) $c['nms'])); ?>
          <tr>
            <td><b><?= e($c['camp_name'] ?: 'Кампания') ?></b><div class="muted small">ID <?= (int) $c['advert_id'] ?> · <?= date('d.m', strtotime($c['first_day'])) ?>–<?= date('d.m.y', strtotime($c['last_day'])) ?></div></td>
            <td class="small"><?= e($c['payment_type'] ?: '') ?></td>
            <td class="num"><?= (int) $c['cnt'] ?></td>
            <td class="num b"><?= num_ru($c['amount'], 2) ?></td>
            <td class="small"><?= $nms ? e(implode(', ', $nms)) : '<span class="muted">не определены — расход общий</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
    <div class="card-title">Реклама на товар</div>
    <?php foreach ($bySku as $nm => $sum): ?>
      <div class="top-item">
        <span class="p-thumb sm"><?php if (!empty($photos[$nm]['photo'])): ?><img src="<?= e($photos[$nm]['photo']) ?>" alt="" loading="lazy"><?php endif; ?></span>
        <span class="grow small"><?= (int) $nm ?><span class="muted clip" style="display:block"><?= e($photos[$nm]['title'] ?? '') ?></span></span>
        <b><?= money($sum) ?></b>
      </div>
    <?php endforeach; ?>
    <p class="muted small">Если в кампании несколько товаров, сумма делится между ними поровну.</p>
  </div>
</div>
