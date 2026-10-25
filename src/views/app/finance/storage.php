<?php $total = array_sum(array_map(static fn($i) => (float) $i['amount'], $items)); ?>
<div class="kpis">
  <div class="card kpi"><div class="k-label">Хранение по товарам</div><div class="k-value"><?= money($total) ?></div><div class="k-sub">отчёт WB «Платное хранение»</div></div>
  <div class="card kpi"><div class="k-label">Хранение в отчётах реализации</div><div class="k-value"><?= money($reportStorage) ?></div><div class="k-sub">удержано WB за период</div></div>
  <div class="card kpi"><div class="k-label">Товаров на хранении</div><div class="k-value"><?= count($items) ?></div><div class="k-sub">&nbsp;</div></div>
</div>
<?php if (!$items): ?>
  <div class="card empty"><h3>Нет данных о хранении</h3><p>Отчёт о платном хранении загружается из WB по 8 дней за запрос (нужна категория токена «Аналитика»). Данные появятся в течение нескольких минут.</p></div>
<?php return; endif; ?>

<?php if ($days): $max = max(1.0, ...array_map(static fn($d) => (float) $d['amount'], $days)); $n = count($days); $slot = 600 / $n; ?>
  <div class="card">
    <div class="card-title">Стоимость хранения по дням</div>
    <svg class="chart" viewBox="0 0 600 130" role="img" aria-label="Хранение по дням">
      <?php foreach ($days as $i => $d): $h = (float) $d['amount'] / $max * 105; ?>
        <rect class="bar" x="<?= round($i * $slot + 1, 1) ?>" y="<?= round(110 - $h, 1) ?>" width="<?= round(max(1, $slot - 2), 1) ?>" height="<?= round($h, 1) ?>"><title><?= date_ru($d['day'], false) ?>: <?= money($d['amount']) ?></title></rect>
        <?php if ($i === 0 || $i === $n - 1 || ($n > 10 && $i % (int) ceil($n / 6) === 0)): ?><text x="<?= round($i * $slot + $slot / 2, 1) ?>" y="126" text-anchor="<?= $i === $n - 1 ? 'end' : ($i === 0 ? 'start' : 'middle') ?>"><?= date('d.m', strtotime($d['day'])) ?></text><?php endif; ?>
      <?php endforeach; ?>
    </svg>
  </div>
<?php endif; ?>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table fin-table">
      <thead><tr><th>Товар</th><th class="num">Объём, л</th><th class="num">Средний остаток, шт</th><th class="num">Дней</th><th class="num">Хранение, ₽</th><th class="num">₽ за шт в день</th></tr></thead>
      <tbody>
      <?php foreach ($items as $i): ?>
        <tr>
          <td><div class="sku-cell"><span class="p-thumb"><?php if ($i['photo']): ?><img src="<?= e($i['photo']) ?>" alt="" loading="lazy"><?php endif; ?></span>
            <div style="min-width:0"><b><?= e($i['vendor_code'] ?: '—') ?></b><div class="muted small"><?= (int) $i['nm_id'] ?> · <?= e($i['subject'] ?: '') ?></div></div></div></td>
          <td class="num"><?= num_ru($i['volume'], 2) ?></td>
          <td class="num"><?= num_ru($i['avg_qty']) ?></td>
          <td class="num"><?= (int) $i['days'] ?></td>
          <td class="num b"><?= num_ru($i['amount'], 2) ?></td>
          <td class="num"><?= $i['avg_qty'] > 0 && $i['days'] > 0 ? num_ru((float) $i['amount'] / (float) $i['avg_qty'] / (int) $i['days'], 3) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="muted small">В отчёте по товарам хранение берётся отсюда. Разница с суммой в отчётах реализации (сроки списания WB) попадает в «Не распределено по товарам».</p>
