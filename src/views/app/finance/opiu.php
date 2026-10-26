<?php
use App\Services\Finance;

$cols = $res['periods'];
$t = $res['total'];
// Строки ОПИУ: [показатель, подпись, тип] — тип: money | qty | sub (со знаком «−») | total | pct
$lines = [
    ['revenue', 'Выручка (продажи − возвраты)', 'total'],
    ['sales_qty', 'Кол-во продаж, шт', 'qty'],
    ['deliveries', 'Кол-во доставок (заказов), шт', 'qty'],
    ['back_deliveries', 'Обратная доставка с ПВЗ, шт', 'qty'],
    ['returns_qty', 'Возвраты покупателей, шт', 'qty'],
    ['defect_comp', 'Компенсация брака от WB', 'money'],
    ['lost_comp', 'Компенсация потерянного товара от WB', 'money'],
    ['spp_comp', 'Компенсация скидки WB (СПП)', 'money'],
    ['commission', 'Комиссия WB', 'sub'],
    ['acquiring', 'Эквайринг', 'sub'],
    ['logistics', 'Логистика', 'sub'],
    ['loyalty', 'Программа лояльности (баллы)', 'sub'],
    ['turnover', 'Оборот: придёт от WB за товар', 'total'],
    ['storage', 'Хранение', 'sub'],
    ['acceptance', 'Платная приёмка', 'sub'],
    ['penalty', 'Штрафы', 'sub'],
    ['deduction', 'Удержания', 'sub'],
    ['additional', 'Доплаты (корректировка ВВ)', 'sub'],
    ['wb_other', 'Хранение и прочие удержания WB', 'total'],
    ['pay_calc', 'Итого к оплате (как в отчётах WB)', 'total'],
    ['cost', 'Себестоимость проданного товара', 'sub'],
    ['tax_base', 'Налоговая база', 'money'],
    ['tax', 'Налог', 'sub'],
    ['vat', 'НДС', 'sub'],
    ['ads', 'Реклама (без удержанной WB в отчётах)', 'sub'],
    ['exp_sku', 'Доп. затраты на товары', 'sub'],
    ['exp_period', 'Прочие расходы за период', 'sub'],
    ['profit', 'Чистая прибыль', 'profit'],
    ['margin', 'Маржинальность, % от выручки', 'pct'],
    ['roi', 'ROI, % от себестоимости', 'pct'],
];
$fmt = static function ($v, string $type): string {
    return match ($type) {
        'qty' => num_ru($v, abs((float) $v - round((float) $v)) > 0.001 ? 1 : 0),
        'pct' => $v === null ? '—' : num_ru($v, 1) . '%',
        default => num_ru($v),
    };
};
?>
<?php if (!$res['info']['rows']): ?>
  <div class="card empty"><h3>Нет отчётов за этот период</h3><p>Выберите другой период.</p></div>
<?php return; endif; ?>

<div class="row" style="margin-bottom:12px;gap:10px">
  <div class="seg">
    <a href="/app/finance/opiu<?= $qs(['g' => 'week']) ?>" class="<?= $group === 'week' ? 'on' : '' ?>">По неделям</a>
    <a href="/app/finance/opiu<?= $qs(['g' => 'month']) ?>" class="<?= $group === 'month' ? 'on' : '' ?>">По месяцам</a>
  </div>
  <span class="muted small">Суммы в <?= e(currency_sign()) ?>. Выручка — как графа «Продажа» в отчётах WB. «−» — расход, вычитается из выручки.</span>
</div>

<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table fin-table opiu">
      <thead>
        <tr>
          <th class="sticky">Показатель</th>
          <?php foreach ($cols as $key => $c): ?><th class="num"><?= e(Finance::periodLabel($key, $group)) ?></th><?php endforeach; ?>
          <?php if (count($cols) > 1): ?><th class="num">Итого</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as [$k, $label, $type]):
            if (in_array($k, ['defect_comp', 'lost_comp', 'spp_comp', 'loyalty', 'deduction', 'additional', 'vat', 'exp_sku', 'exp_period', 'acceptance', 'penalty'], true) && abs((float) $t[$k]) < 0.005 && !($k === 'exp_period' && $expenses)) {
                continue;
            } ?>
          <tr class="l-<?= $type ?>">
            <td class="sticky"><?= $type === 'sub' ? '<span class="muted">−</span> ' : '' ?><?= e($label) ?></td>
            <?php foreach ($cols as $c): $v = $c[$k] ?? null; ?>
              <td class="num <?= $type === 'profit' && $v < 0 ? 'neg' : '' ?>"><?= $fmt($v, $type) ?></td>
            <?php endforeach; ?>
            <?php if (count($cols) > 1): $v = $t[$k] ?? null; ?><td class="num b <?= $type === 'profit' && $v < 0 ? 'neg' : '' ?>"><?= $fmt($v, $type) ?></td><?php endif; ?>
          </tr>
          <?php if ($k === 'exp_period' && $expenses): foreach ($expenses as $cat => $vals): ?>
            <tr class="l-detail">
              <td class="sticky muted">&nbsp;&nbsp;&nbsp;в т. ч. <?= e($cat) ?></td>
              <?php foreach ($cols as $key => $c): ?><td class="num muted"><?= isset($vals[$key]) ? num_ru($vals[$key]) : '' ?></td><?php endforeach; ?>
              <?php if (count($cols) > 1): ?><td class="num muted"><?= num_ru(array_sum($vals)) ?></td><?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="muted small">Неделя — период отчёта реализации WB (пн–вс). Расходы на рекламу, хранение и прочие расходы относятся к неделе по дате списания.</p>
