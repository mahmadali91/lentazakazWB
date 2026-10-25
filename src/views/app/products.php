<?php
$fmt = static fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
?>
<div class="topbar">
  <h1>Товары и себестоимость</h1>
  <a class="btn btn-ghost btn-sm" href="/app/products/export"><?= icon('download', 16) ?> Выгрузить CSV</a>
</div>

<?php if (empty($plan['profit'])): ?>
  <div class="alert alert-warn">Расчёт прибыли доступен на платных тарифах. <a href="/app/billing">Выбрать тариф</a></div>
<?php elseif ($total && $withCost < $total): ?>
  <div class="alert alert-info">Себестоимость указана у <?= $withCost ?> из <?= $total ?> товаров. Прибыль считается только по товарам с себестоимостью.</div>
<?php endif; ?>

<div class="feed-grid">
  <div class="card orders">
    <form class="orders-head row" method="get" action="/app/products" style="gap:8px">
      <div class="search grow" style="position:relative;min-width:200px">
        <span style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--muted)"><?= icon('search', 16) ?></span>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Артикул, название, бренд" style="padding-left:36px">
      </div>
      <label class="check small" style="margin:0"><input type="checkbox" name="empty" value="1" <?= $onlyEmpty ? 'checked' : '' ?> onchange="this.form.submit()"> без себестоимости</label>
      <button class="btn btn-ghost btn-sm">Найти</button>
    </form>

    <?php if (!$products): ?>
      <div class="empty"><div class="ico"><?= icon('box', 28) ?></div><h3>Товаров пока нет</h3><p>Товары появляются автоматически вместе с заказами после синхронизации с WB.</p></div>
    <?php else: ?>
      <form method="post" action="/app/products">
        <?= csrf_field() ?>
        <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="empty" value="<?= $onlyEmpty ? '1' : '' ?>">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Товар</th><th>Заказов<br>за 30 дн</th><th>Ср. цена</th><th>Себестоимость, ₽</th><th>Комиссия, %</th><th>Прибыль/шт ≈</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p):
                $key = (int) $p['store_id'] . '_' . (int) $p['nm_id'];
                $avg = $p['avg_price'] !== null ? (float) $p['avg_price'] : null;
                $unit = null;
                if ($avg !== null && $p['cost_price'] !== null) {
                    $c = $p['commission_pct'] !== null ? (float) $p['commission_pct'] : $settings['commission'];
                    $unit = $avg - $avg * $c / 100 - $settings['logistics'] - $avg * $settings['tax'] / 100 - $settings['other'] - (float) $p['cost_price'];
                }
                $title = $p['title'] ?: trim(($p['subject'] ?? '') . ' ' . ($p['brand'] ?? '')) ?: 'Товар ' . $p['nm_id'];
            ?>
              <tr>
                <td style="min-width:240px">
                  <div class="row" style="gap:10px;flex-wrap:nowrap">
                    <?php if ($p['photo']): ?><img src="<?= e($p['photo']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" style="width:36px;height:48px;object-fit:cover;border-radius:6px;flex:none">
                    <?php else: ?><div style="width:36px;height:48px;border-radius:6px;background:var(--surface-2);flex:none"></div><?php endif; ?>
                    <div style="min-width:0">
                      <div style="font-weight:600;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($title) ?>"><?= e($title) ?></div>
                      <div class="small muted"><?= $p['vendor_code'] ? 'арт. ' . e($p['vendor_code']) . ' · ' : '' ?>WB <?= (int) $p['nm_id'] ?><?= $multi ? ' · ' . e($p['store_name']) : '' ?></div>
                    </div>
                  </div>
                </td>
                <td><?= (int) $p['orders_30'] ?></td>
                <td class="nowrap"><?= $avg !== null ? money($avg) : '—' ?></td>
                <td><input type="text" inputmode="decimal" name="cost[<?= $key ?>]" value="<?= e($fmt($p['cost_price'])) ?>" placeholder="—" style="width:110px;padding:7px 10px" <?= $canEdit ? '' : 'disabled' ?>></td>
                <td><input type="text" inputmode="decimal" name="comm[<?= $key ?>]" value="<?= e($fmt($p['commission_pct'])) ?>" placeholder="<?= e($fmt($settings['commission'])) ?>" style="width:80px;padding:7px 10px" <?= $canEdit ? '' : 'disabled' ?>></td>
                <td class="nowrap"><?php if ($unit !== null): ?><b style="color:<?= $unit >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= ($unit >= 0 ? '+' : '−') . money(abs($unit)) ?></b><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($canEdit): ?>
          <div class="more" style="text-align:left"><button class="btn"><?= icon('check', 16) ?> Сохранить себестоимость</button>
            <span class="small muted" style="margin-left:10px">Пустая комиссия — берётся общая (<?= e($fmt($settings['commission'])) ?>%).</span></div>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </div>

  <div class="feed-side stack">
    <div class="card">
      <div class="card-title"><?= icon('chart', 18) ?> Параметры расчёта прибыли</div>
      <form method="post" action="/app/products/settings">
        <?= csrf_field() ?>
        <div class="field"><label>Комиссия WB по умолчанию, %</label><input type="text" inputmode="decimal" name="commission" value="<?= e($fmt($settings['commission'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">Смотрите в кабинете WB: «Тарифы» → комиссия по категории.</div></div>
        <div class="field"><label>Логистика за единицу, ₽</label><input type="text" inputmode="decimal" name="logistics" value="<?= e($fmt($settings['logistics'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">Доставка до покупателя. Для отмен при получении и возвратов считается как убыток.</div></div>
        <div class="field"><label>Налог, % от цены</label><input type="text" inputmode="decimal" name="tax" value="<?= e($fmt($settings['tax'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">УСН «доходы» — 6%. Без налога — 0.</div></div>
        <div class="field"><label>Прочие расходы на единицу, ₽</label><input type="text" inputmode="decimal" name="other" value="<?= e($fmt($settings['other'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">Упаковка, хранение, реклама, фулфилмент.</div></div>
        <?php if ($canEdit): ?><button class="btn btn-sm">Сохранить параметры</button><?php endif; ?>
      </form>
      <p class="small muted" style="margin-top:14px">Формула: цена продавца − комиссия − логистика − налог − прочие − себестоимость. Это оценка; точные удержания WB — в еженедельном отчёте реализации.</p>
    </div>
    <?php if ($canEdit): ?>
      <div class="card">
        <div class="card-title"><?= icon('download', 18) ?> Загрузить из Excel / CSV</div>
        <p class="small muted">Выгрузите CSV, заполните колонку «Себестоимость» в Excel и загрузите обратно. Первая колонка — артикул WB или артикул продавца.</p>
        <form method="post" action="/app/products/import" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="file" name="file" accept=".csv,text/csv" required style="margin-bottom:10px;max-width:100%">
          <button class="btn btn-ghost btn-sm">Загрузить</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>
