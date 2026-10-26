<?php
// Налог и фиксированные расходы — у каждого магазина (раздел «Магазины»)
$fin = array_filter($stores, static fn($st) => in_array((int) $st['id'], $storeIds, true));
?>
<div class="card">
  <div class="card-title">Налог и фиксированные расходы</div>
  <?php foreach ($fin as $st): ?>
    <div class="kv"><span><?= e($st['name']) ?></span><b><?= e(num_ru((float) $st['tax_rate'], 1)) ?>%<?= (float) $st['vat_rate'] > 0 ? ' + НДС ' . e(num_ru((float) $st['vat_rate'], 0)) . '%' : '' ?><?= (float) $st['weekly_expense'] > 0 ? ' · ' . e(num_ru((float) $st['weekly_expense'])) . ' / нед.' : '' ?></b></div>
  <?php endforeach; ?>
  <div class="muted small" style="margin-top:8px">Налог считается от суммы «Вайлдберриз реализовал товар», как в отчёте. Фиксированные расходы вычитаются за каждую неделю с отчётом.
    <?php if ($canEdit): ?><a href="/app/stores">Изменить в разделе «Магазины»</a><?php endif; ?></div>
</div>
