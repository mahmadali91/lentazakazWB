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
  <div class="card kpi"><div class="k-label">Выручка</div><div class="k-value"><?= money($rev) ?></div><div class="k-sub"><?= num_ru($t['sales_qty']) ?> продаж · <?= num_ru($t['returns_qty']) ?> возвр.</div></div>
  <div class="card kpi"><div class="k-label">Придёт от WB за товар</div><div class="k-value"><?= money($t['turnover']) ?></div><div class="k-sub">выручка − комиссия − логистика</div></div>
  <div class="card kpi"><div class="k-label">Себестоимость</div><div class="k-value"><?= money($t['cost']) ?></div><div class="k-sub"><?= $pct($t['cost']) ?> выручки</div></div>
  <div class="card kpi"><div class="k-label">Налог<?= $t['vat'] ? ' и НДС' : '' ?></div><div class="k-value"><?= money($t['tax'] + $t['vat']) ?></div><div class="k-sub"><?= num_ru($settings['tax'], 1) ?>%<?= $settings['vat'] ? ' + НДС ' . num_ru($settings['vat'], 0) . '%' : '' ?></div></div>
  <div class="card kpi"><div class="k-label">Реклама</div><div class="k-value"><?= money($t['ads']) ?></div><div class="k-sub">ДРР <?= $pct($t['ads']) ?></div></div>
  <div class="card kpi k-accent"><div class="k-label">Чистая прибыль</div><div class="k-value"><?= money($t['profit']) ?></div><div class="k-sub">маржа <?= $t['margin'] !== null ? num_ru($t['margin'], 1) . '%' : '—' ?><?= $t['roi'] !== null ? ' · ROI ' . num_ru($t['roi'], 0) . '%' : '' ?></div></div>
</div>

<div class="fin-grid">
  <div class="stack">
    <div class="card">
      <div class="card-title">Выручка и прибыль по неделям</div>
      <?php
      $per = $res['periods'];
      $max = max(1.0, ...array_values(array_map(static fn($p) => max($p['revenue'], abs($p['profit'])), $per)));
      $hasNeg = min(0.0, ...array_values(array_map(static fn($p) => $p['profit'], $per))) < 0;
      $n = count($per);
      $w = 600; $h = 180; $base = $hasNeg ? 140 : 160; $scale = ($hasNeg ? 120 : 145) / $max;
      $slot = $w / max(1, $n);
      ?>
      <svg class="chart fin-chart" viewBox="0 0 <?= $w ?> <?= $h + 16 ?>" role="img" aria-label="Выручка и прибыль по неделям">
        <line x1="0" x2="<?= $w ?>" y1="<?= $base ?>" y2="<?= $base ?>" class="axis"/>
        <?php $i = 0; foreach ($per as $key => $p): $x = $i * $slot; $bw = max(3, $slot * 0.36); ?>
          <rect class="bar" x="<?= round($x + $slot * 0.1, 1) ?>" y="<?= round($base - $p['revenue'] * $scale, 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round(max(0, $p['revenue']) * $scale, 1) ?>"><title><?= e(Finance::periodLabel($key, 'week')) ?>: выручка <?= money($p['revenue']) ?></title></rect>
          <?php $ph = abs($p['profit']) * $scale; ?>
          <rect class="bar2 <?= $p['profit'] < 0 ? 'neg' : '' ?>" x="<?= round($x + $slot * 0.1 + $bw + 2, 1) ?>" y="<?= round($p['profit'] >= 0 ? $base - $ph : $base, 1) ?>" width="<?= round($bw, 1) ?>" height="<?= round($ph, 1) ?>"><title><?= e(Finance::periodLabel($key, 'week')) ?>: прибыль <?= money($p['profit']) ?></title></rect>
          <?php if ($n <= 8 || $i % (int) ceil($n / 8) === 0): ?>
            <text x="<?= round($x + $slot / 2, 1) ?>" y="<?= $h + 12 ?>" text-anchor="middle"><?= e(date('d.m', strtotime($key))) ?></text>
          <?php endif; ?>
        <?php $i++; endforeach; ?>
      </svg>
      <div class="legend"><span><i class="lg-rev"></i>Выручка</span><span><i class="lg-profit"></i>Чистая прибыль</span></div>
    </div>

    <div class="card">
      <div class="card-title">Куда уходит выручка</div>
      <?php
      $parts = [
          'Комиссия WB и эквайринг' => $t['commission'] + $t['acquiring'],
          'Логистика' => $t['logistics'],
          'Хранение, приёмка, штрафы, удержания' => $t['wb_other'],
          'Программа лояльности' => $t['loyalty'],
          'Себестоимость' => $t['cost'],
          'Налог' . ($t['vat'] ? ' и НДС' : '') => $t['tax'] + $t['vat'],
          'Реклама' => $t['ads'],
          'Прочие расходы' => $t['exp_sku'] + $t['exp_period'],
          'Чистая прибыль' => $t['profit'],
      ];
      ?>
      <div class="breakdown">
        <?php foreach ($parts as $label => $v): if (abs($v) < 0.5 && $label !== 'Чистая прибыль') continue; ?>
          <div class="bd-row <?= $label === 'Чистая прибыль' ? 'bd-total' : '' ?>">
            <span class="bd-label"><?= e($label) ?></span>
            <span class="bd-bar"><i class="<?= $v < 0 ? 'neg' : '' ?>" style="width:<?= $rev > 0 ? min(100, round(abs($v) / $rev * 100, 1)) : 0 ?>%"></i></span>
            <b class="<?= $label === 'Чистая прибыль' ? $cls($v) : '' ?>"><?= money($v) ?></b>
            <span class="muted small bd-pct"><?= $pct($v) ?></span>
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
