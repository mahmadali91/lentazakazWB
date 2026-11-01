<?php
use App\Core\View;
use App\Services\Plans;
use App\Services\YooKassa;
?>
<div class="topbar"><h1>Тарифы — вид клиента</h1></div>
<?= View::partial('admin/_nav', ['tab' => 'billing']) ?>

<form method="get" class="card preview-bar" style="margin-bottom:16px">
  <div class="row" style="align-items:flex-end;gap:12px;flex-wrap:wrap">
    <div class="field" style="margin:0"><label>Тариф клиента</label>
      <select name="plan" onchange="this.form.submit()">
        <option value="free" <?= $pv['plan'] === 'free' ? 'selected' : '' ?>><?= e(Plans::get('free')['name']) ?></option>
        <?php foreach (Plans::paid() as $code => $p): ?><option value="<?= e($code) ?>" <?= $pv['plan'] === $code ? 'selected' : '' ?>><?= e($p['name']) ?> — <?= number_format((float) $p['price'], 0, ',', ' ') ?> ₽</option><?php endforeach; ?>
      </select></div>
    <?php if ($pv['plan'] !== 'free'): ?>
      <div class="field" style="margin:0"><label>Подписка</label>
        <select name="state" onchange="this.form.submit()">
          <?php foreach (['active' => 'действует, осталось 20 дней', 'expiring' => 'истекает через 2 дня', 'expired' => 'истекла 3 дня назад'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $pv['state'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
          <?php endforeach; ?>
        </select></div>
      <?php if ($pv['max'] > $pv['min']): ?>
        <div class="field" style="margin:0"><label>Магазинов у клиента</label>
          <input type="number" name="stores" min="<?= $pv['min'] ?>" max="<?= min($pv['max'], 50) ?>" value="<?= $pv['stores'] ?>" style="width:90px" onchange="this.form.submit()"></div>
      <?php endif; ?>
    <?php endif; ?>
    <span class="small muted" style="margin-left:auto">Цены и тарифы — в <code>config/plans.php</code>. Оплата в просмотре отключена.</span>
  </div>
</form>

<div class="alert alert-info">Так клиент видит раздел «Тариф и оплата». История платежей ниже — пример: у клиента в ней его реальные платежи.</div>

<div class="preview-frame">
  <?php $cp = Plans::forUser($client); if (!empty($cp['expired_code'])): ?>
    <div class="alert alert-warn">Срок тарифа «<?= e(Plans::get($cp['expired_code'])['name']) ?>» закончился — действуют ограничения бесплатного тарифа. <a href="#">Продлить</a></div>
  <?php endif; ?>
  <?= View::partial('app/billing', ['user' => $client, 'plan' => Plans::forUser($client), 'plans' => Plans::paid(), 'payments' => $demo,
      'payEnabled' => YooKassa::enabled(), 'preview' => true]) ?>
</div>
