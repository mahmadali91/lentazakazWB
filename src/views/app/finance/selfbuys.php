<div class="fin-grid">
  <div class="card" style="padding:0">
    <div class="card-title" style="padding:14px 16px 0">Заказы-самовыкупы (<?= count($items) ?>)</div>
    <?php if (!$items): ?>
      <p class="muted" style="padding:16px">Список пуст. Добавьте Srid заказов, которые вы выкупили сами (для продвижения) — их выручка не будет считаться продажей, а в расходы попадёт реальная стоимость выкупа: разница между ценой покупателя и выплатой WB, логистика и налог. Себестоимость не списывается — товар остаётся у вас.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table fin-table">
          <thead><tr><th>Srid</th><th>Товар</th><th class="num">Затраты на выкуп</th><th class="num">Доп. затраты</th><th>Комментарий</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): $f = $found[$i['srid']] ?? null; ?>
            <tr>
              <td class="mono small"><a href="/app/finance/orders?q=<?= urlencode($i['srid']) ?>&amp;p=all"><?= e($i['srid']) ?></a></td>
              <td><?= $f ? '<b>' . e($f['vendor_code']) . '</b> <span class="muted small">' . date_ru($f['date'], false) . '</span>' : '<span class="muted small">ещё нет в отчётах</span>' ?></td>
              <td class="num"><?= $f ? num_ru($f['selfbuy_cost'], 2) : '—' ?></td>
              <td class="num"><?= num_ru($i['extra_cost'], 2) ?></td>
              <td class="small"><?= e($i['note'] ?: '') ?></td>
              <td><?php if ($canEdit): ?><form method="post" action="/app/finance/selfbuys/delete"><?= csrf_field() ?><?= $hidden ?><input type="hidden" name="srid" value="<?= e($i['srid']) ?>"><button class="link-btn">убрать</button></form><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
  <?php if ($canEdit): ?>
    <div class="card">
      <div class="card-title">Добавить самовыкупы</div>
      <form method="post" action="/app/finance/selfbuys" class="stack">
        <?= csrf_field() ?><?= $hidden ?>
        <label>Srid заказов <span class="muted small">(по одному в строке)</span><textarea name="srids" rows="6" required placeholder="Скопируйте из столбца «Srid» отчёта или из «Детализации»"></textarea></label>
        <label>Доп. затраты на каждый, <?= currency_sign() ?> <span class="muted small">(оплата исполнителю, отзыв)</span><input type="text" inputmode="decimal" name="extra_cost" placeholder="0"></label>
        <label>Комментарий <input type="text" name="note" maxlength="255"></label>
        <button class="btn btn-sm">Добавить</button>
      </form>
    </div>
  <?php endif; ?>
</div>
