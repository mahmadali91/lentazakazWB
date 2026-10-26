<?php
$cls = static fn($v) => $v < 0 ? 'neg' : '';
?>
<form class="toolbar" method="get">
  <?= $hidden ?>
  <div class="search"><?= icon('search', 16) ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Srid заказа, артикул продавца или WB"></div>
  <button class="btn btn-sm">Найти</button>
</form>

<?php if ($srid !== ''): ?>
  <div class="card" style="margin-bottom:16px">
    <div class="row" style="justify-content:space-between"><div class="card-title" style="margin:0">Строки отчёта по заказу <span class="mono"><?= e($srid) ?></span></div>
      <a class="btn btn-ghost btn-sm" href="/app/finance/orders<?= $qs(['q' => $q ?: null]) ?>">Закрыть</a></div>
    <?php if (!$rows): ?><p class="muted">Строк не найдено.</p><?php else: ?>
      <div class="table-wrap"><table class="table fin-table">
        <thead><tr><th>Отчёт</th><th>Тип документа</th><th>Обоснование</th><th>Вид логистики</th><th class="num">Цена со скидкой</th><th class="num">Реализовал WB</th><th class="num">К перечислению</th><th class="num">Логистика</th><th class="num">Удержания</th><th>Дата продажи</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
          <tr><td class="nowrap"><?= date('d.m', strtotime($r['date_from'])) ?>–<?= date('d.m.y', strtotime($r['date_to'])) ?></td><td><?= e($r['doc_type'] ?: '—') ?></td><td><?= e($r['oper']) ?></td><td class="small"><?= e($r['bonus_type'] ?: '') ?></td>
            <td class="num"><?= num_ru($r['retail_price_disc'], 2) ?></td><td class="num"><?= num_ru($r['retail_amount'], 2) ?></td><td class="num"><?= num_ru($r['for_pay'], 2) ?></td>
            <td class="num"><?= num_ru($r['delivery_service'], 2) ?></td><td class="num"><?= num_ru($r['paid_storage'] + $r['penalty'] + $r['deduction'] + $r['paid_acceptance'], 2) ?></td><td class="nowrap"><?= date_ru($r['sale_dt'], false) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$orders): ?>
  <div class="card empty"><h3>Заказов не найдено</h3><p>Измените период или запрос.</p></div>
<?php return; endif; ?>

<p class="muted small">Расчёт прибыли по каждому заказу (Srid) из строк отчётов — как лист «детализация по исходнику». Показаны <?= num_ru(($page - 1) * $perPage + 1) ?>–<?= num_ru(($page - 1) * $perPage + count($orders)) ?> из <?= num_ru($total) ?>, новые сверху. Нажмите на заказ, чтобы увидеть строки отчёта.</p>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table fin-table">
      <thead><tr><th>Дата</th><th>Заказ / товар</th><th class="num">Выручка</th><th class="num" title="Комиссия WB и эквайринг за вычетом компенсации скидки WB (СПП)">Комиссия</th><th class="num">Логистика</th><th class="num">Налог</th><th class="num">Себест.</th><th class="num">Прибыль</th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr class="row-link" onclick="location.href='/app/finance/orders<?= e($qs(['srid' => $o['srid'], 'q' => $q ?: null, 'page' => $page > 1 ? $page : null])) ?>'">
          <td class="nowrap"><?= date_ru($o['date'], false) ?></td>
          <td><a href="/app/finance/orders<?= $qs(['srid' => $o['srid'], 'q' => $q ?: null, 'page' => $page > 1 ? $page : null]) ?>"><b><?= e($o['vendor_code'] ?: '—') ?></b></a> <span class="muted small"><?= e($o['subject']) ?></span>
            <div class="muted small mono"><?= e($o['srid']) ?></div></td>
          <td class="num"><?= num_ru($o['revenue']) ?></td>
          <td class="num"><?= num_ru($o['commission'] + $o['acquiring'] - $o['spp_comp']) ?></td>
          <td class="num"><?= num_ru($o['logistics']) ?></td>
          <td class="num"><?= num_ru($o['tax'] + $o['vat']) ?></td>
          <td class="num"><?= num_ru($o['cost']) ?></td>
          <td class="num b <?= $cls($o['profit']) ?>"><?= num_ru($o['profit']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($total > $perPage): $pages = (int) ceil($total / $perPage); ?>
  <div class="pager">
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="/app/finance/orders<?= $qs(['q' => $q ?: null, 'page' => $page > 2 ? $page - 1 : null]) ?>">← Назад</a><?php endif; ?>
    <span class="muted small">Страница <?= $page ?> из <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="/app/finance/orders<?= $qs(['q' => $q ?: null, 'page' => $page + 1]) ?>">Ещё <?= $perPage ?> →</a><?php endif; ?>
  </div>
<?php endif; ?>
