<?php
$hints = [
    'AA' => 'Лидеры: много выручки и прибыли. Держите в наличии, не допускайте out of stock.',
    'AB' => 'Зона роста: выручка высокая, прибыль можно поднять — проверьте цену, рекламу, логистику.',
    'AC' => 'Много продаж, но мало прибыли или убыток — пересмотрите цену и расходы.',
    'BA' => 'Зона роста: прибыльные — стоит вложиться в продвижение.',
    'BB' => 'Зона роста: середняки, есть потенциал.',
    'BC' => 'Незначительная прибыль — проверьте экономику.',
    'CA' => 'Мало продаж, но маржинальные — попробуйте нарастить продажи.',
    'CB' => 'Незначительная доля в выручке и прибыли.',
    'CC' => 'Аутсайдеры — кандидаты на вывод из ассортимента.',
];
?>
<?php if (!$skus): ?>
  <div class="card empty"><h3>Нет продаж за этот период</h3><p>Выберите другой период.</p></div>
<?php return; endif; ?>

<div class="abc-grid">
  <div class="abc-corner muted small">выручка ↓ / прибыль →</div>
  <?php foreach (['A', 'B', 'C'] as $p): ?><div class="abc-head"><span class="abc abc-<?= $p ?>"><?= $p ?></span></div><?php endforeach; ?>
  <?php foreach (['A', 'B', 'C'] as $r): ?>
    <div class="abc-head"><span class="abc abc-<?= $r ?>"><?= $r ?></span></div>
    <?php foreach (['A', 'B', 'C'] as $p): $c = $matrix[$r . $p] ?? null; ?>
      <div class="card abc-cell abc-z<?= in_array($r . $p, ['AA'], true) ? 'top' : (in_array($r . $p, ['AB', 'BA', 'BB'], true) ? 'grow' : (in_array($r . $p, ['CC', 'AC'], true) ? 'bad' : 'mid')) ?>">
        <b><?= $r . $p ?></b> · <?= (int) ($c['count'] ?? 0) ?> <?= plural((int) ($c['count'] ?? 0), 'товар', 'товара', 'товаров') ?>
        <div class="small">Выручка <?= money($c['revenue'] ?? 0) ?></div>
        <div class="small">Прибыль <b class="<?= ($c['profit'] ?? 0) < 0 ? 'neg' : '' ?>"><?= money($c['profit'] ?? 0) ?></b></div>
        <div class="muted small abc-hint"><?= e($hints[$r . $p]) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>

<div class="card" style="padding:0;margin-top:16px">
  <div class="table-wrap">
    <table class="table fin-table">
      <thead><tr><th>Группа</th><th>Товар</th><th class="num">Продаж</th><th class="num">Выручка</th><th class="num">Доля выручки</th><th class="num">Прибыль</th><th class="num">Маржа</th></tr></thead>
      <tbody>
      <?php $rev = max(1.0, $res['total']['revenue']); foreach ($skus as $nm => $s): ?>
        <tr>
          <td><span class="abc abc-<?= $s['abc_rev'] ?>"><?= $s['abc_rev'] ?></span><span class="abc abc-<?= $s['abc_profit'] ?>"><?= $s['abc_profit'] ?></span></td>
          <td><b><?= e($s['vendor_code'] ?: (string) $nm) ?></b> <span class="muted small"><?= e($s['subject']) ?></span></td>
          <td class="num"><?= num_ru($s['sales_qty']) ?></td>
          <td class="num"><?= num_ru($s['revenue']) ?></td>
          <td class="num"><?= num_ru($s['revenue'] / $rev * 100, 1) ?>%</td>
          <td class="num <?= $s['profit'] < 0 ? 'neg' : '' ?>"><?= num_ru($s['profit']) ?></td>
          <td class="num"><?= $s['margin'] !== null ? num_ru($s['margin'], 1) . '%' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="muted small">Как в листе «Отчёт реализации свод»: товары сортируются по убыванию, A — накопленная доля до 80%, B — до 95%, C — остальные.</p>
