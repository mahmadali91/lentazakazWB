<?php $names = ['pending' => 'Ожидает', 'succeeded' => 'Оплачен', 'canceled' => 'Отменён', 'manual' => 'Вручную']; ?>
<div class="topbar"><h1>Платежи</h1></div>
<?= App\Core\View::partial('admin/_nav', ['tab' => 'payments']) ?>
<div class="card" style="padding:0">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>ID</th><th>Дата</th><th>Пользователь</th><th>Тариф</th><th>Срок</th><th>Сумма</th><th>Статус</th><th>ID ЮKassa</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="muted"><?= (int) $p['id'] ?></td>
          <td class="nowrap small"><?= date_ru($p['created_at']) ?></td>
          <td><?= e($p['email']) ?></td>
          <td><?= e(App\Services\Plans::get($p['plan_code'])['name']) ?></td>
          <td><?= (int) $p['months'] ?: '—' ?></td>
          <td><?= money($p['amount']) ?></td>
          <td><span class="badge <?= in_array($p['status'], ['succeeded', 'manual'], true) ? 'badge-buyout' : ($p['status'] === 'canceled' ? 'badge-cancel' : 'badge-created') ?>"><?= e($names[$p['status']] ?? $p['status']) ?></span></td>
          <td class="small muted"><?= e($p['provider_id']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$payments): ?><tr><td colspan="8" class="muted" style="text-align:center;padding:30px">Платежей пока нет</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
