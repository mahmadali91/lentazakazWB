<?php
use App\Services\Finance;

$t = $res['total'];
$rev = $t['revenue'];
$pct = static fn(float $v) => $rev > 0 ? num_ru($v / $rev * 100, 1) . '%' : '—';
$cls = static fn(float $v) => $v < 0 ? 'neg' : 'pos';
?>
<?php if (!$res['info']['rows']): ?>
  <div class="card empty">
    <div class="ico"><?= icon('chart', 30) ?></div>
    <h3>Нет отчётов за этот период</h3>
    <p>Выберите другой период или дождитесь загрузки отчётов. Отчёт реализации WB формируется по понедельникам за прошлую неделю.</p>
  </div>
<?php return; endif; ?>

<?php if ($res['info']['no_cost']): ?>
  <div class="alert alert-warn">Не указана себестоимость у <?= count($res['info']['no_cost']) ?> <?= plural(count($res['info']['no_cost']), 'товара', 'товаров', 'товаров') ?> — прибыль завышена. <a href="/app/finance/costs<?= $qs() ?>">Указать себестоимость</a></div>
<?php endif; ?>

<div class="kpis">
  <div class="card kpi" title="Как графа «Продажа» в отчётах реализации WB: продажи минус возвраты по цене продажи"><div class="k-label">Выручка</div><div class="k-value"><?= money($rev) ?></div><div class="k-sub"><?= num_ru($t['sales_qty']) ?> продаж · <?= num_ru($t['returns_qty']) ?> возвр.</div></div>
  <div class="card kpi" title="Как «Итого к оплате» в отчётах реализации WB: к перечислению за товар − логистика − хранение, приёмка, штрафы и прочие удержания"><div class="k-label">Итого к оплате от WB</div><div class="k-value"><?= money($t['pay_calc']) ?></div><div class="k-sub">за товар <?= money($t['turnover']) ?> − удержания <?= money($t['turnover'] - $t['pay_calc']) ?></div></div>
  <div class="card kpi"><div class="k-label">Себестоимость</div><div class="k-value"><?= money($t['cost']) ?></div><div class="k-sub"><?= $pct($t['cost']) ?> выручки</div></div>
  <div class="card kpi"><div class="k-label">Налог<?= $t['vat'] ? ' и НДС' : '' ?></div><div class="k-value"><?= money($t['tax'] + $t['vat']) ?></div><div class="k-sub"><?php $rates = array_unique(array_map(static fn($st) => (float) $st['tax_rate'], array_filter($stores, static fn($st) => in_array((int) $st['id'], $storeIds, true)))); ?><?= count($rates) === 1 ? num_ru(reset($rates), 1) . '% · ставка магазина' : 'по ставкам магазинов' ?></div></div>
  <div class="card kpi" title="Все расходы на рекламные кампании WB. Удержания WB за продвижение в отчётах реализации второй раз не вычитаются"><div class="k-label">Реклама</div><div class="k-value"><?= money($t['ads']) ?></div><div class="k-sub">ДРР <?= $pct($t['ads']) ?></div></div>
  <div class="card kpi k-accent"><div class="k-label">Чистая прибыль</div><div class="k-value"><?= money($t['profit']) ?></div><div class="k-sub">маржа <?= $t['margin'] !== null ? num_ru($t['margin'], 1) . '%' : '—' ?><?= $t['roi'] !== null ? ' · ROI ' . num_ru($t['roi'], 0) . '%' : '' ?></div></div>
</div>

<div class="fin-grid">
  <div class="stack">
    <?php
    // Данные для интерактивного графика: по неделям отчётов (месяцы собираются в браузере)
    $series = [];
    foreach ($res['periods'] as $key => $p) {
        $series[] = ['k' => $key, 'revenue' => round($p['revenue'], 2), 'profit' => round($p['profit'], 2), 'pay' => round($p['pay_calc'], 2),
            'cost' => round($p['cost'], 2), 'logistics' => round($p['logistics'], 2), 'commission' => round($p['commission'] + $p['acquiring'], 2),
            'ads' => round($p['ads'], 2), 'qty' => (float) $p['sales_qty']];
    }
    $metrics = ['revenue' => 'Выручка', 'profit' => 'Чистая прибыль', 'pay' => 'Итого к оплате', 'cost' => 'Себестоимость', 'logistics' => 'Логистика',
        'commission' => 'Комиссия', 'ads' => 'Реклама', 'qty' => 'Продажи, шт', 'margin' => 'Маржа, %'];
    ?>
    <div class="card fin-dyn" id="finDyn">
      <div class="fin-dyn-head">
        <div class="card-title" style="margin:0">Динамика</div>
        <div class="seg seg-sm" data-dyn="group" role="group" aria-label="Группировка">
          <button type="button" data-value="week">Недели</button><button type="button" data-value="month">Месяцы</button>
        </div>
      </div>
      <div class="chips" data-dyn="metric" role="group" aria-label="Показатель">
        <?php foreach ($metrics as $k => $label): ?><button type="button" class="chip" data-value="<?= $k ?>"><?= e($label) ?></button><?php endforeach; ?>
      </div>
      <div class="fin-dyn-stats" id="finDynStats"></div>
      <div class="fin-dyn-plot" id="finDynPlot" role="img" aria-label="График показателя по периодам"></div>
      <div class="fin-dyn-tip" id="finDynTip" hidden></div>
      <script>window.LZ_FIN_DYN = <?= json_encode(['series' => $series, 'metrics' => $metrics, 'cur' => currency_sign()], JSON_UNESCAPED_UNICODE) ?>;</script>
    </div>

    <div class="card">
      <div class="card-title">Куда уходит выручка</div>
      <?php
      $parts = [
          '+ Компенсация скидки WB (СПП)' => -$t['spp_comp'],
          'Комиссия WB и эквайринг' => $t['commission'] + $t['acquiring'],
          'Логистика' => $t['logistics'],
          'Хранение, приёмка, штрафы, удержания' => $t['wb_other'],
          'Программа лояльности' => $t['loyalty'],
          'Себестоимость' => $t['cost'],
          'Налог' . ($t['vat'] ? ' и НДС' : '') => $t['tax'] + $t['vat'],
          'Реклама' => $t['ads'],
          'Прочие и фиксированные расходы' => $t['exp_sku'] + $t['exp_period'] + $t['exp_fixed'],
          'Чистая прибыль' => $t['profit'],
      ];
      ?>
      <div class="breakdown">
        <?php foreach ($parts as $label => $v): if (abs($v) < 0.5 && $label !== 'Чистая прибыль') continue; ?>
          <div class="bd-row <?= $label === 'Чистая прибыль' ? 'bd-total' : '' ?>">
            <span class="bd-label"><?= e($label) ?></span>
            <?php $inc = $label[0] === '+'; ?>
            <span class="bd-bar"><i class="<?= $inc ? 'inc' : ($v < 0 ? 'neg' : '') ?>" style="width:<?= $rev > 0 ? min(100, round(abs($v) / $rev * 100, 1)) : 0 ?>%"></i></span>
            <b class="<?= $label === 'Чистая прибыль' ? $cls($v) : ($inc ? 'pos' : '') ?>"><?= $inc ? '+' . money(-$v) : money($v) ?></b>
            <span class="muted small bd-pct"><?= $pct($inc ? -$v : $v) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Выручка по категориям</div>
      <div class="table-wrap">
        <table class="table fin-table">
          <thead><tr><th>Категория</th><th class="num">Продаж</th><th class="num">Выручка</th><th class="num">Прибыль</th><th class="num">Доля</th></tr></thead>
          <tbody>
          <?php foreach ($bySubject as $name => $s): ?>
            <tr><td><?= e($name) ?></td><td class="num"><?= num_ru($s['qty']) ?></td><td class="num"><?= money($s['revenue']) ?></td>
              <td class="num <?= $cls($s['profit']) ?>"><?= money($s['profit']) ?></td><td class="num"><?= $pct($s['revenue']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-title">Самые прибыльные товары</div>
      <?php foreach ($top as $nm => $s): ?>
        <a class="top-item" href="/app/finance/sku<?= $qs() ?>#nm<?= (int) $nm ?>">
          <span class="p-thumb sm"><?php if (!empty($photos[$nm]['photo'])): ?><img src="<?= e($photos[$nm]['photo']) ?>" alt="" loading="lazy"><?php endif; ?></span>
          <span class="grow" style="min-width:0"><span class="t-title"><?= e($s['vendor_code'] ?: (string) $nm) ?></span><span class="muted small"><?= num_ru($s['sales_qty']) ?> шт · <?= money($s['revenue']) ?></span></span>
          <b class="<?= $cls($s['profit']) ?>"><?= money($s['profit']) ?></b>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($worst): ?>
      <div class="card">
        <div class="card-title">Убыточные товары</div>
        <?php foreach ($worst as $nm => $s): ?>
          <a class="top-item" href="/app/finance/sku<?= $qs() ?>#nm<?= (int) $nm ?>">
            <span class="p-thumb sm"><?php if (!empty($photos[$nm]['photo'])): ?><img src="<?= e($photos[$nm]['photo']) ?>" alt="" loading="lazy"><?php endif; ?></span>
            <span class="grow" style="min-width:0"><span class="t-title"><?= e($s['vendor_code'] ?: (string) $nm) ?></span><span class="muted small"><?= num_ru($s['sales_qty']) ?> шт · <?= money($s['revenue']) ?></span></span>
            <b class="neg"><?= money($s['profit']) ?></b>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?= App\Core\View::partial('app/finance/_settings', get_defined_vars()) ?>
    <div class="card small muted">
      Сверка с WB: «Итого к оплате» по строкам отчётов — <b><?= money($t['pay_calc']) ?></b>. Совпадает с суммой к перечислению в кабинете WB (раздел «Финансовые отчёты»).
    </div>
  </div>
</div>
