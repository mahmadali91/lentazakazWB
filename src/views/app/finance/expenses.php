<?php
use App\Services\Finance;

$total = array_sum(array_map(static fn($i) => (float) $i['amount'], $items));
?>
<div class="fin-grid">
  <div class="card" style="padding:0">
    <div class="row" style="justify-content:space-between;padding:14px 16px 0">
      <div class="card-title" style="margin:0">Расходы за период</div>
      <b><?= money($total) ?></b>
    </div>
    <?php if (!$items): ?>
      <p class="muted" style="padding:16px">Расходов за период нет. Добавьте фулфилмент, упаковку, доставку до склада, зарплаты, сервисы — всё, что не попадает в отчёт WB.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table fin-table">
          <thead><tr><th>Дата</th><th>Статья</th><th>На товар</th><th>Комментарий</th><th class="num">Сумма, <?= currency_sign() ?></th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): ?>
            <tr>
              <td class="nowrap"><?= date_ru($i['day'], false) ?></td>
              <td><?= e($i['category']) ?><?= $i['store_name'] && count($activeIds) > 1 ? '<div class="muted small">' . e($i['store_name']) . '</div>' : '' ?></td>
              <td><?= $i['nm_id'] ? (int) $i['nm_id'] : '<span class="muted small">на период</span>' ?></td>
              <td class="small"><?= e($i['note'] ?: '') ?></td>
              <td class="num b"><?= num_ru($i['amount'], 2) ?></td>
              <td><?php if ($canEdit): ?><form method="post" action="/app/finance/expenses/<?= (int) $i['id'] ?>/delete" data-confirm="Удалить расход?"><?= csrf_field() ?><?= $hidden ?><button class="link-btn">удалить</button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($canEdit): ?>
    <div class="card">
      <div class="card-title">Добавить расход</div>
      <form method="post" action="/app/finance/expenses" class="stack">
        <?= csrf_field() ?><?= $hidden ?>
        <label>Дата <input type="date" name="day" value="<?= e(min(date('Y-m-d'), $to)) ?>" required></label>
        <label>Статья <input type="text" name="category" list="expCats" required placeholder="Например, Фулфилмент"></label>
        <datalist id="expCats"><?php foreach (Finance::EXPENSE_CATEGORIES as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
        <label>Сумма, <?= currency_sign() ?> <input type="text" inputmode="decimal" name="amount" required placeholder="0"></label>
        <label>Артикул WB <span class="muted small">(необязательно — расход ляжет на товар)</span><input type="text" inputmode="numeric" name="nm_id" placeholder="Например, 186452311"></label>
        <?php if (count($activeIds) > 1): ?>
          <label>Магазин <select name="store_id"><option value="0">Все магазины</option><?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
        <?php endif; ?>
        <label>Комментарий <input type="text" name="note" maxlength="255"></label>
        <button class="btn btn-sm">Добавить</button>
      </form>
      <p class="muted small">Расход без артикула вычитается из прибыли за неделю/месяц по дате. С артикулом — ещё и из прибыли товара.</p>
    </div>
  <?php endif; ?>
</div>
