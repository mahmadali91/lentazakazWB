<?php
$fmt = static fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$countryNames = ['RU' => 'Россия', 'KG' => 'Кыргызстан', 'KZ' => 'Казахстан', 'BY' => 'Беларусь', 'AM' => 'Армения', 'UZ' => 'Узбекистан', 'TJ' => 'Таджикистан', 'GE' => 'Грузия'];
?>
<div class="topbar">
  <h1>Товары и прибыль</h1>
  <a class="btn btn-ghost btn-sm" href="/app/products/export"><?= icon('download', 16) ?> Выгрузить CSV</a>
</div>

<?php if (empty($plan['profit'])): ?>
  <div class="alert alert-warn">Расчёт прибыли доступен на платных тарифах. <a href="/app/billing">Выбрать тариф</a></div>
<?php elseif ($total && $withCost < $total): ?>
  <div class="alert alert-info">Себестоимость указана у <?= $withCost ?> из <?= $total ?> товаров. Прибыль считается только по товарам с себестоимостью.</div>
<?php endif; ?>

<div class="products-grid">
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
      <form method="post" action="/app/products" class="p-desktop">
        <?= csrf_field() ?>
        <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="empty" value="<?= $onlyEmpty ? '1' : '' ?>">
        <div class="table-wrap">
          <table class="table products-table">
            <thead><tr>
              <th>Товар</th>
              <th title="Остаток на складах WB · в пути к покупателям">Остаток</th>
              <th title="Заказов за последние 30 дней">Заказы<br>30 дн</th>
              <th title="Средняя цена продавца за 30 дней">Ср. цена</th>
              <th title="Объём товара в упаковке — от него считается логистика">Объём</th>
              <th title="Комиссия WB для категории и схемы продажи. Можно указать свою">Комиссия, %</th>
              <th title="Логистика до покупателя по формуле WB">Логистика</th>
              <th>Себестоимость, <?= currency_sign() ?></th>
              <th title="Цена − комиссия − логистика − налог − прочие − себестоимость">Прибыль/шт</th>
            </tr></thead>
            <tbody>
            <?php foreach ($products as $p):
                $key = (int) $p['store_id'] . '_' . (int) $p['nm_id'];
                $c = $p['calc'];
                $title = $p['title'] ?: trim(($p['subject'] ?? '') . ' ' . ($p['brand'] ?? '')) ?: 'Товар ' . $p['nm_id'];
            ?>
              <tr>
                <td style="min-width:230px">
                  <div class="row" style="gap:12px;flex-wrap:nowrap">
                    <?php if ($p['photo']): ?><img src="<?= e($p['photo']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" class="p-thumb">
                    <?php else: ?><div class="p-thumb"></div><?php endif; ?>
                    <div style="min-width:0">
                      <div style="font-weight:600;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($title) ?>"><?= e($title) ?></div>
                      <div class="small muted"><?= $p['vendor_code'] ? 'арт. ' . e($p['vendor_code']) . ' · ' : '' ?>WB <?= (int) $p['nm_id'] ?><?= $multi ? ' · ' . e($p['store_name']) : '' ?></div>
                      <div class="small muted"><?= e($p['subject'] ?: '') ?><?= $p['main_scheme'] ? ' · ' . e(scheme_label($p['main_scheme'])) : '' ?></div>
                    </div>
                  </div>
                </td>
                <td class="nowrap" title="<?= $p['stock_at'] ? 'Отчёт WB «Остатки на складах» от ' . e(date_ru($p['stock_at'])) : 'Остатки ещё не загружены' ?>">
                  <?php if ($p['stock_wb'] !== null): ?>
                    <b><?= (int) $p['stock_wb'] ?></b> шт
                    <?php if ((int) $p['stock_to_client'] || (int) $p['stock_from_client']): ?><div class="small muted">в пути <?= (int) $p['stock_to_client'] ?> / возвр. <?= (int) $p['stock_from_client'] ?></div><?php endif; ?>
                  <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
                <td><?= (int) $p['orders_30'] ?></td>
                <td class="nowrap"><?= $p['avg_price'] !== null ? money($p['avg_price']) : '—' ?></td>
                <td class="nowrap" title="<?= $p['volume_src'] === 'remains' ? 'Из отчёта WB об остатках' : ($p['volume_src'] === 'card' ? 'По габаритам карточки ' . e($p['dims']) . ' см' : 'Объём неизвестен') ?>">
                  <?= $p['volume_l'] !== null ? e($fmt($p['volume_l'])) . ' л' : '<span class="muted">—</span>' ?>
                  <?php if ($p['dims']): ?><div class="small muted"><?= e($p['dims']) ?> см</div><?php endif; ?>
                </td>
                <td>
                  <input type="text" inputmode="decimal" name="comm[<?= $key ?>]" value="<?= e($fmt($p['commission_pct'])) ?>"
                    placeholder="<?= e($fmt($p['auto_comm'] ?? $settings['commission'])) ?>" style="width:66px;padding:7px 10px" <?= $canEdit ? '' : 'disabled' ?>
                    title="<?= e($c['commission_src'] ?? '') ?>">
                  <?php if ($c): ?><div class="small muted src-note"><?= $p['commission_pct'] !== null ? 'вручную' : (str_starts_with($c['commission_src'], 'тариф WB') ? 'WB ✓' : 'по умолч.') ?></div><?php endif; ?>
                </td>
                <td class="nowrap" title="<?= e($c['logistics_src'] ?? '') ?>">
                  <?= $c ? money($c['logistics']) : '—' ?>
                  <?php if ($c): ?><div class="small muted src-note"><?= $p['volume_l'] !== null ? 'по формуле WB' : 'фикс.' ?></div><?php endif; ?>
                </td>
                <td><input type="text" inputmode="decimal" name="cost[<?= $key ?>]" value="<?= e($fmt($p['cost_price'])) ?>" placeholder="—" style="width:86px;padding:7px 10px" <?= $canEdit ? '' : 'disabled' ?>></td>
                <td class="nowrap"
                  title="<?= $c && $c['profit'] !== null ? e('Цена ' . money($c['price']) . ' − комиссия ' . money($c['commission']) . ' − логистика ' . money($c['logistics']) . ' − налог ' . money($c['tax']) . ' − прочие ' . money($c['other']) . ' − себестоимость ' . money($c['cost'])) : '' ?>">
                  <?php if ($c && $c['profit'] !== null): ?><b style="color:<?= $c['profit'] >= 0 ? 'var(--ok)' : 'var(--bad)' ?>"><?= ($c['profit'] >= 0 ? '+' : '−') . money(abs($c['profit'])) ?></b>
                  <?php else: ?><span class="muted">—</span><?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($canEdit): ?>
          <div class="more" style="text-align:left"><button class="btn"><?= icon('check', 16) ?> Сохранить</button>
            <span class="small muted" style="margin-left:10px">Пустая комиссия — берётся ставка WB для категории. Наведите курсор на цифру, чтобы увидеть, откуда она.</span></div>
        <?php endif; ?>
      </form>

      <?php /* Телефон: карточки товаров, по нажатию — окно с подробностями и полем себестоимости */ ?>
      <div class="p-cards">
        <?php foreach ($products as $p):
            $c = $p['calc'];
            $title = $p['title'] ?: trim(($p['subject'] ?? '') . ' ' . ($p['brand'] ?? '')) ?: 'Товар ' . $p['nm_id'];
            $data = [
                'key' => (int) $p['store_id'] . '_' . (int) $p['nm_id'], 'title' => $title, 'photo' => $p['photo'],
                'nm' => (int) $p['nm_id'], 'article' => $p['vendor_code'], 'subject' => $p['subject'], 'brand' => $p['brand'],
                'store' => $multi ? $p['store_name'] : null, 'scheme' => $p['main_scheme'] ? scheme_label($p['main_scheme']) : null,
                'stock' => $p['stock_wb'] !== null ? (int) $p['stock_wb'] : null, 'stock_to' => (int) $p['stock_to_client'], 'stock_from' => (int) $p['stock_from_client'],
                'stock_at' => $p['stock_at'] ? date_ru($p['stock_at']) : null,
                'orders' => (int) $p['orders_30'], 'avg' => $p['avg_price'] !== null ? round((float) $p['avg_price']) : null,
                'volume' => $p['volume_l'] !== null ? (float) $p['volume_l'] : null, 'volume_src' => $p['volume_src'], 'dims' => $p['dims'],
                'cost' => $p['cost_price'] !== null ? (float) $p['cost_price'] : null,
                'comm_manual' => $p['commission_pct'] !== null ? (float) $p['commission_pct'] : null,
                'comm_auto' => $p['auto_comm'] ?? $settings['commission'],
                'calc' => $c,
            ];
        ?>
          <div class="p-card" role="button" tabindex="0" data-p="<?= e(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>">
            <?php if ($p['photo']): ?><img class="p-card-photo" src="<?= e($p['photo']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <?php else: ?><div class="p-card-photo"></div><?php endif; ?>
            <div class="p-card-info">
              <div class="p-card-title"><?= e($title) ?></div>
              <div class="small muted"><?= $p['vendor_code'] ? 'арт. ' . e($p['vendor_code']) . ' · ' : '' ?>WB <?= (int) $p['nm_id'] ?></div>
              <div class="p-card-stats">
                <div class="o-stat"><span class="o-cap">Цена</span><b><?= $p['avg_price'] !== null ? money($p['avg_price']) : '—' ?></b></div>
                <div class="o-stat"><span class="o-cap">Прибыль/шт</span>
                  <?php if ($c && $c['profit'] !== null): ?><b class="<?= $c['profit'] < 0 ? 'neg' : 'pos' ?>"><?= ($c['profit'] < 0 ? '−' : '+') . money(abs($c['profit'])) ?></b>
                  <?php else: ?><b class="muted small">нет себест.</b><?php endif; ?></div>
                <div class="o-stat"><span class="o-cap">Остаток</span><b><?= $p['stock_wb'] !== null ? (int) $p['stock_wb'] . ' шт' : '—' ?></b></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <script>window.LZ_PRODUCTS = <?= json_encode(['canEdit' => $canEdit, 'q' => $q, 'empty' => $onlyEmpty ? '1' : ''], JSON_UNESCAPED_UNICODE) ?>;</script>
    <?php endif; ?>
  </div>

  <div class="products-side">
    <div class="card">
      <div class="card-title"><?= icon('zap', 18) ?> Откуда берутся цифры</div>
      <div class="src-list small">
        <div><b>Комиссия</b> — тариф WB по каждой категории и схеме (FBO / FBS / DBS / самовывоз), метод <code>tariffs/commission</code>.
          <?= $sources['comm_count'] ? 'Загружено категорий: ' . $sources['comm_count'] . ', обновлено ' . e(date_ru($sources['commissions'])) : '<span style="color:var(--warn)">ещё не загружено — появится после ближайшей синхронизации</span>' ?>.
          <?php if ($sources['countries']): ?>Ставки продавца: <?= e(implode(', ', array_map(fn($c) => $countryNames[$c] ?? $c, $sources['countries']))) ?>.<?php endif; ?></div>
        <div><b>Логистика</b> — по формуле WB: до 1 л — 23–32 ₽ по таблице объёма, от 1 л — 46 ₽ + 14 ₽ за каждый доп. литр; × коэффициент склада
          (<?= $sources['box_count'] ? 'тарифы ' . $sources['box_count'] . ' складов WB от ' . e(date_ru($sources['box'])) : 'тарифы складов ещё не загружены' ?>; если склад неизвестен — <?= e($fmt($settings['coef'])) ?>%).
          Отказ при получении и возврат: + обратная доставка по базовому тарифу.</div>
        <div><b>Объём и остатки</b> — отчёт WB «Остатки на складах»<?= $sources['remains'] ? ' от ' . e(date_ru($sources['remains'])) : ' (ещё не загружен)' ?>; если его нет — габариты из карточки товара.</div>
        <div><b>Себестоимость, налог, прочие</b> — ваши значения.</div>
      </div>
    </div>

    <div class="card">
      <div class="card-title"><?= icon('chart', 18) ?> Параметры расчёта</div>
      <form method="post" action="/app/products/settings">
        <?= csrf_field() ?>
        <div class="field"><label>Налог, % от цены</label><input type="text" inputmode="decimal" name="tax" value="<?= e($fmt($settings['tax'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">УСН «доходы» — 6%. Без налога — 0.</div></div>
        <div class="field"><label>Прочие расходы на единицу, <?= currency_sign() ?></label><input type="text" inputmode="decimal" name="other" value="<?= e($fmt($settings['other'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">Упаковка, хранение, реклама, фулфилмент.</div></div>
        <div class="field"><label>Коэффициент склада, если неизвестен, %</label><input type="text" inputmode="decimal" name="coef" value="<?= e($fmt($settings['coef'])) ?>" <?= $canEdit ? '' : 'disabled' ?>><div class="hint">С 15 августа для складов РФ единый — 170%. Для складов СНГ — свои.</div></div>
        <div class="field"><label>Комиссия, если категория не найдена, %</label><input type="text" inputmode="decimal" name="commission" value="<?= e($fmt($settings['commission'])) ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <div class="field"><label>Логистика, если объём неизвестен, <?= currency_sign() ?></label><input type="text" inputmode="decimal" name="logistics" value="<?= e($fmt($settings['logistics'])) ?>" <?= $canEdit ? '' : 'disabled' ?>></div>
        <?php if ($canEdit): ?><button class="btn btn-sm">Сохранить параметры</button><?php endif; ?>
      </form>
      <p class="small muted" style="margin-top:14px">Это оценка. Точные удержания WB — в еженедельном отчёте реализации.</p>
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
