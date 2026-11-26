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
    <?php if ($canEdit && !\App\Core\Auth::isMember()): ?><a href="/app/stores">Изменить в разделе «Магазины»</a><?php endif; ?></div>
  <?php if ($canEdit && \App\Core\Auth::isMember()): $num = static fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); ?>
    <?php foreach ($fin as $st): ?>
      <details class="store-fin-edit">
        <summary>Изменить для «<?= e($st['name']) ?>»</summary>
        <form method="post" action="/app/stores/<?= (int) $st['id'] ?>/finance" class="row" style="gap:10px;align-items:flex-end;margin-top:10px">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e(strtok($_SERVER['REQUEST_URI'] ?? '/app/finance', '#')) ?>">
          <div class="field" style="width:90px"><label>Налог, %</label><input type="text" inputmode="decimal" name="tax_rate" value="<?= e($num($st['tax_rate'])) ?>" placeholder="6"></div>
          <div class="field" style="width:90px"><label>НДС, %</label><input type="text" inputmode="decimal" name="vat_rate" value="<?= e($num($st['vat_rate'])) ?>" placeholder="0"></div>
          <div class="field grow"><label>Фикс. расходы в неделю</label><input type="text" inputmode="decimal" name="weekly_expense" value="<?= e($num($st['weekly_expense'])) ?>" placeholder="Например: 15 000"></div>
          <button class="btn btn-sm" style="margin-bottom:14px">Сохранить</button>
        </form>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
