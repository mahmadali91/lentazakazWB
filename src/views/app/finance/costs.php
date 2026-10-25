<?php if (!$items): ?>
  <div class="card empty"><h3>Нет продаж за этот период</h3><p>Здесь появятся товары из отчётов WB — укажите им себестоимость.</p></div>
<?php return; endif; ?>
<?php $empty = count(array_filter($items, static fn($i) => $i['cost_price'] === null)); ?>
<p class="muted small">Товары из отчётов за период. Себестоимость — закупка + доставка до склада + упаковка на 1 шт. Она общая с разделом «<a href="/app/products">Товары</a>» (там же — загрузка из CSV).
  <?= $empty ? '<b>Без себестоимости: ' . $empty . '</b> — они сверху.' : 'У всех товаров себестоимость указана.' ?></p>
<form method="post" action="/app/finance/costs">
  <?= csrf_field() ?><?= $hidden ?>
  <div class="card" style="padding:0">
    <div class="table-wrap">
      <table class="table fin-table">
        <thead><tr><th>Товар</th><th class="num">Продано за период</th><th class="num" style="width:160px">Себестоимость, <?= currency_sign() ?></th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): $key = (int) $i['store_id'] . '_' . (int) $i['nm_id']; ?>
          <tr>
            <td><div class="sku-cell"><span class="p-thumb"><?php if ($i['photo']): ?><img src="<?= e($i['photo']) ?>" alt="" loading="lazy"><?php endif; ?></span>
              <div style="min-width:0"><b><?= e($i['vendor_code'] ?: '—') ?></b><div class="muted small"><?= (int) $i['nm_id'] ?> · <?= e($i['subject'] ?: '') ?></div>
              <div class="small clip"><?= e($i['p_title'] ?: $i['title'] ?: '') ?></div></div></div></td>
            <td class="num"><?= num_ru($i['qty']) ?> шт</td>
            <td class="num"><input class="num-input <?= $i['cost_price'] === null ? 'need' : '' ?>" type="text" inputmode="decimal" name="cost[<?= $key ?>]"
              value="<?= $i['cost_price'] !== null ? e(rtrim(rtrim(number_format((float) $i['cost_price'], 2, ',', ''), '0'), ',')) : '' ?>" placeholder="0" <?= $canEdit ? '' : 'disabled' ?>></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($canEdit): ?><div class="sticky-save"><button class="btn">Сохранить себестоимость</button></div><?php endif; ?>
</form>
