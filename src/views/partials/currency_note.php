<?php
// Валюта сумм: одна строка вместо большого предупреждения; кнопка — пересчитать всё в рубли по курсу ЦБ
$storeIds ??= ($acc = App\Core\Auth::account()) ? array_map('intval', array_column(App\Core\DB::all('SELECT id FROM stores WHERE user_id = ?', [$acc['id']]), 'id')) : [];
$fxOn ??= false;
$cc = currency_of_stores($storeIds);
$foreign = $cc['all'] && $cc['all'] !== ['RUB'];
if (!$foreign) {
    return;
}
$canToggle = !App\Core\Auth::isMember();
$signs = implode(', ', array_map(static fn($c) => currency_sign($c), $cc['all']));
?>
<div class="fx-note">
  <?php if ($fxOn): ?>
    <span><?= icon('check', 14) ?> Суммы в рублях по курсу ЦБ РФ<?= ($r = App\Services\Fx::rate($cc['all'][0] === 'RUB' ? ($cc['all'][1] ?? 'RUB') : $cc['all'][0])) && $r != 1.0 ? ' · 1 ' . e(currency_sign($cc['all'][0] === 'RUB' ? ($cc['all'][1] ?? 'RUB') : $cc['all'][0])) . ' = ' . e(num_ru($r, 4)) . ' ₽' : '' ?></span>
  <?php elseif ($cc['mixed']): ?>
    <span><?= icon('zap', 14) ?> Магазины в разных валютах (<?= e($signs) ?>) — общие итоги складываются без пересчёта.</span>
  <?php else: ?>
    <span>Суммы в валюте кабинета WB (<?= e($signs) ?>).</span>
  <?php endif; ?>
  <?php if ($canToggle): ?>
    <form method="post" action="/app/settings/currency"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/app'), '#')) ?>">
      <button class="link-btn"><?= $fxOn ? 'Показывать в валюте магазинов' : 'Показывать в рублях по курсу ЦБ' ?></button></form>
  <?php endif; ?>
</div>
