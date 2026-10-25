<div class="card">
  <div class="card-title">Налоги</div>
  <?php if ($canEdit): ?>
    <form method="post" action="/app/finance/settings" class="stack">
      <?= csrf_field() ?><?= $hidden ?><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <div class="row" style="gap:10px">
        <label class="grow">Налог, % <input type="text" inputmode="decimal" name="tax" value="<?= e(num_ru($settings['tax'], 1)) ?>"></label>
        <label class="grow">НДС, % <input type="text" inputmode="decimal" name="vat" value="<?= e(num_ru($settings['vat'] ?? 0, 0)) ?>"></label>
      </div>
      <div class="muted small">Налог считается от суммы «Вайлдберриз реализовал товар» (цена для покупателя), как в отчёте. УСН «Доходы» — 6%, НДС 0 — если вы не плательщик НДС (5% и 7% — для УСН с НДС).</div>
      <button class="btn btn-sm">Сохранить</button>
    </form>
  <?php else: ?>
    <div class="muted small">Налог <?= num_ru($settings['tax'], 1) ?>%, НДС <?= num_ru($settings['vat'] ?? 0, 0) ?>%. Меняет владелец кабинета.</div>
  <?php endif; ?>
</div>
