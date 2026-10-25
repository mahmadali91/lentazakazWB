<?php
// Предупреждение, если магазины кабинета работают в разных валютах (WB отдаёт суммы в валюте продавца)
$storeIds ??= ($acc = App\Core\Auth::account()) ? array_map('intval', array_column(App\Core\DB::all('SELECT id FROM stores WHERE user_id = ?', [$acc['id']]), 'id')) : [];
$cc = currency_of_stores($storeIds);
if ($cc['mixed']): ?>
  <div class="alert alert-warn">Магазины работают в разных валютах (<?= e(implode(', ', array_map(static fn($c) => $c . ' ' . currency_sign($c), $cc['all']))) ?>). WB отдаёт суммы в валюте каждого магазина, поэтому общие итоги по всем магазинам складываются без пересчёта курса — для точных сумм выберите один магазин.</div>
<?php endif; ?>
