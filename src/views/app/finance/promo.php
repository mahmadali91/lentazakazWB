<?php if (!$items): ?>
  <div class="card empty"><h3>Нет продаж за этот период</h3><p>Калькулятор берёт среднюю цену, СПП, логистику и рекламу на продажу из отчётов за выбранный период.</p></div>
<?php return; endif; ?>
<p class="muted small">Калькулятор участия в акции (лист «Участие в акции»): впишите цену для акции — прибыль и маржа пересчитаются сразу. Средние СПП, логистика на продажу и ДРР — из отчётов за период, комиссия — тариф WB для категории (FBO). Налог <?= num_ru($settings['tax'], 1) ?>%<?= $settings['vat'] ? ', НДС ' . num_ru($settings['vat'], 0) . '%' : '' ?> — от цены для покупателя.</p>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table fin-table promo-table" id="promoTable" data-tax="<?= (float) $settings['tax'] ?>" data-vat="<?= (float) ($settings['vat'] ?? 0) ?>">
      <thead><tr>
        <th>Товар</th><th class="num">Цена до скидки</th><th class="num">Цена сейчас</th><th class="num">Прибыль сейчас</th><th class="num">Цена в акции</th>
        <th class="num">Прибыль в акции</th><th class="num">Маржа в акции</th>
        <th class="num">СПП, %</th><th class="num">Комиссия, %</th><th class="num">Логистика, ₽</th><th class="num">ДРР, %</th><th class="num">Себест., ₽</th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $i): $now = round($i['price'] * (1 - $i['discount'] / 100)); ?>
        <tr data-row>
          <td><div class="sku-cell"><span class="p-thumb sm"><?php if ($i['photo']): ?><img src="<?= e($i['photo']) ?>" alt="" loading="lazy"><?php endif; ?></span>
            <div style="min-width:0"><b><?= e($i['vendor_code'] ?: '—') ?></b><div class="muted small"><?= (int) $i['nm_id'] ?> · <?= num_ru($i['qty']) ?> прод.</div></div></div></td>
          <td class="num"><?= num_ru($i['price']) ?></td>
          <td class="num"><input class="num-input" data-k="now" value="<?= $now ?>" inputmode="decimal"></td>
          <td class="num" data-out="now">—</td>
          <td class="num"><input class="num-input need" data-k="promo" value="<?= round($now * 0.9) ?>" inputmode="decimal"></td>
          <td class="num b" data-out="promo">—</td>
          <td class="num" data-out="margin">—</td>
          <td class="num"><input class="num-input sm" data-k="spp" value="<?= $i['spp'] ?>" inputmode="decimal"></td>
          <td class="num"><input class="num-input sm" data-k="comm" value="<?= $i['comm'] ?>" inputmode="decimal"></td>
          <td class="num"><input class="num-input sm" data-k="log" value="<?= $i['logistics'] ?>" inputmode="decimal"></td>
          <td class="num"><input class="num-input sm" data-k="drr" value="<?= $i['drr'] ?>" inputmode="decimal"></td>
          <td class="num"><input class="num-input sm <?= $i['cost'] === null ? 'need' : '' ?>" data-k="cost" value="<?= $i['cost'] ?? '' ?>" inputmode="decimal" placeholder="?"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="muted small">Прибыль на 1 шт = цена − комиссия WB − логистика − налог − НДС − реклама (ДРР от цены) − себестоимость. Изменения здесь не сохраняются — это калькулятор.</p>
