<?php
$cls = static fn($v) => $v < 0 ? 'neg' : '';
$sorts = ['revenue' => 'выручке', 'profit' => 'прибыли', 'sales_qty' => 'продажам', 'margin' => 'марже'];
$thumb = static function ($nm) use ($photos) {
    $src = $photos[$nm]['photo'] ?? null;
    return '<span class="p-thumb">' . ($src ? '<img src="' . e($src) . '" alt="" loading="lazy">' : '') . '</span>';
};
?>
<?php if (!$skus): ?>
  <div class="card empty"><h3>Нет продаж за этот период</h3><p>Выберите другой период.</p></div>
<?php return; endif; ?>

<div class="row" style="margin-bottom:12px;gap:10px">
  <div class="seg">
    <?php foreach ($sorts as $k => $label): ?><a href="/app/finance/sku<?= $qs(['sort' => $k]) ?>" class="<?= $sort === $k ? 'on' : '' ?>">по <?= $label ?></a><?php endforeach; ?>
  </div>
  <a class="btn btn-ghost btn-sm" href="/app/finance/sku<?= $qs(['export' => 1, 'sort' => $sort]) ?>"><?= icon('download', 16) ?> CSV для Excel</a>
  <span class="muted small"><?= count($skus) ?> <?= plural(count($skus), 'товар', 'товара', 'товаров') ?></span>
</div>

<div class="card p-desktop" style="padding:0">
  <div class="table-wrap">
    <table class="table fin-table sku-table">
      <thead><tr>
        <th class="sticky">Товар</th><th class="num">Прибыль</th><th class="num">Маржа</th><th>ABC</th><th class="num">Продаж</th><th class="num">Возвр.</th><th class="num">Выручка</th><th class="num">Комиссия и эквайринг</th>
        <th class="num">Логистика</th><th class="num">Придёт от WB</th><th class="num">Себест.</th><th class="num">Хранение</th><th class="num">Налог</th>
        <th class="num">Реклама</th><th class="num">Прочее</th>
      </tr></thead>
      <tbody>
      <?php foreach ($skus as $nm => $s): $other = $s['acceptance'] + $s['penalty'] + $s['deduction'] + $s['additional'] + $s['exp_sku'] + $s['selfbuy_cost'] + $s['loyalty'] + $s['vat']; ?>
        <tr id="nm<?= (int) $nm ?>">
          <td class="sticky"><div class="sku-cell"><?= $thumb($nm) ?><div style="min-width:0"><b><?= e($s['vendor_code'] ?: '—') ?></b><div class="muted small"><?= (int) $nm ?> · <?= e($s['subject']) ?></div></div></div></td>
          <td class="num b <?= $cls($s['profit']) ?>"><?= num_ru($s['profit']) ?></td>
          <td class="num <?= $cls($s['margin'] ?? 0) ?>"><?= $s['margin'] !== null ? num_ru($s['margin'], 1) . '%' : '—' ?></td>
          <td class="nowrap"><span class="abc abc-<?= $s['abc_rev'] ?>"><?= $s['abc_rev'] ?></span><span class="abc abc-<?= $s['abc_profit'] ?>"><?= $s['abc_profit'] ?></span></td>
          <td class="num"><?= num_ru($s['sales_qty']) ?></td>
          <td class="num"><?= num_ru($s['returns_qty']) ?></td>
          <td class="num"><?= num_ru($s['revenue']) ?></td>
          <td class="num"><?= num_ru($s['commission'] + $s['acquiring']) ?></td>
          <td class="num"><?= num_ru($s['logistics']) ?></td>
          <td class="num"><?= num_ru($s['turnover']) ?></td>
          <td class="num <?= $s['cost'] == 0 && $s['revenue'] > 0 ? 'warn' : '' ?>" <?= $s['cost'] == 0 && $s['revenue'] > 0 ? 'title="Себестоимость не указана"' : '' ?>><?= num_ru($s['cost']) ?></td>
          <td class="num"><?= num_ru($s['storage']) ?></td>
          <td class="num"><?= num_ru($s['tax']) ?></td>
          <td class="num"><?= num_ru($s['ads']) ?></td>
          <td class="num"><?= num_ru($other) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (abs($res['unallocated']['profit']) >= 1): ?>
        <tr class="l-detail"><td class="sticky muted">Не распределено по товарам<div class="small">хранение по отчёту WB, приёмка, штрафы, реклама без товаров, прочие расходы периода</div></td>
          <td class="num b <?= $cls($res['unallocated']['profit']) ?>"><?= num_ru($res['unallocated']['profit']) ?></td><td colspan="13"></td></tr>
      <?php endif; ?>
      <tr class="l-profit"><td class="sticky">Итого</td>
        <td class="num b <?= $cls($res['total']['profit']) ?>"><?= num_ru($res['total']['profit']) ?></td>
        <td class="num"><?= $res['total']['margin'] !== null ? num_ru($res['total']['margin'], 1) . '%' : '—' ?></td><td></td>
        <td class="num"><?= num_ru($res['total']['sales_qty']) ?></td><td class="num"><?= num_ru($res['total']['returns_qty']) ?></td>
        <td class="num"><?= num_ru($res['total']['revenue']) ?></td><td class="num"><?= num_ru($res['total']['commission'] + $res['total']['acquiring']) ?></td>
        <td class="num"><?= num_ru($res['total']['logistics']) ?></td><td class="num"><?= num_ru($res['total']['turnover']) ?></td>
        <td class="num"><?= num_ru($res['total']['cost']) ?></td><td class="num"><?= num_ru($res['total']['storage']) ?></td>
        <td class="num"><?= num_ru($res['total']['tax']) ?></td><td class="num"><?= num_ru($res['total']['ads']) ?></td>
        <td class="num"><?= num_ru($res['total']['wb_other'] - $res['total']['storage'] + $res['total']['exp_sku'] + $res['total']['exp_period'] + $res['total']['selfbuy_cost'] + $res['total']['loyalty'] + $res['total']['vat']) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="p-cards fin-cards">
  <?php foreach ($skus as $nm => $s): ?>
    <details class="card fin-card" id="m-nm<?= (int) $nm ?>">
      <summary>
        <?= $thumb($nm) ?>
        <span class="grow" style="min-width:0">
          <b class="nowrap"><?= e($s['vendor_code'] ?: (string) $nm) ?></b>
          <span class="muted small" style="display:block"><?= e($s['subject']) ?> · <?= num_ru($s['sales_qty']) ?> шт</span>
          <span class="small" style="display:block">Выручка <b><?= money($s['revenue']) ?></b></span>
        </span>
        <span class="fin-card-profit"><b class="<?= $cls($s['profit']) ?>"><?= money($s['profit']) ?></b><span class="muted small"><?= $s['margin'] !== null ? num_ru($s['margin'], 0) . '%' : '' ?> <span class="abc abc-<?= $s['abc_rev'] ?>"><?= $s['abc_rev'] ?></span><span class="abc abc-<?= $s['abc_profit'] ?>"><?= $s['abc_profit'] ?></span></span></span>
      </summary>
      <div class="kv-list">
        <?php foreach (['revenue' => 'Выручка', 'commission' => '− Комиссия WB', 'acquiring' => '− Эквайринг', 'logistics' => '− Логистика', 'turnover' => '= Придёт от WB',
                       'cost' => '− Себестоимость', 'storage' => '− Хранение', 'tax' => '− Налог', 'vat' => '− НДС', 'ads' => '− Реклама', 'acceptance' => '− Приёмка',
                       'penalty' => '− Штрафы', 'exp_sku' => '− Доп. затраты', 'selfbuy_cost' => '− Самовыкупы', 'profit' => '= Чистая прибыль'] as $k => $label):
            if (abs($s[$k]) < 0.5 && !in_array($k, ['revenue', 'turnover', 'profit', 'cost'], true)) continue; ?>
          <div class="kv <?= in_array($k, ['turnover', 'profit'], true) ? 'total' : '' ?>"><span><?= e($label) ?></span><b class="<?= $k === 'profit' ? $cls($s[$k]) : '' ?>"><?= money($s[$k]) ?></b></div>
        <?php endforeach; ?>
        <div class="kv"><span>Продажи / возвраты, шт</span><b><?= num_ru($s['sales_qty']) ?> / <?= num_ru($s['returns_qty']) ?></b></div>
      </div>
    </details>
  <?php endforeach; ?>
  <div class="card"><div class="kv total"><span>Итого чистая прибыль</span><b class="<?= $cls($res['total']['profit']) ?>"><?= money($res['total']['profit']) ?></b></div>
    <?php if (abs($res['unallocated']['profit']) >= 1): ?><div class="kv"><span class="muted small">в т. ч. не распределено по товарам</span><b class="small"><?= money($res['unallocated']['profit']) ?></b></div><?php endif; ?>
  </div>
</div>
<p class="muted small">ABC: первая буква — по выручке, вторая — по прибыли. A — товары, дающие 80% суммы, B — следующие 15%, C — остальные 5% и убыточные.</p>
