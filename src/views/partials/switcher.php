<?php
// Переключатель кабинетов как в WB Partners: свои магазины и кабинеты, к которым открыт доступ
$sw = App\Services\StoreService::switcher();
$back = in_array($nav ?? '', ['feed', 'products', 'finance'], true) ? strtok($_SERVER['REQUEST_URI'] ?? '/app', '?') : '/app';
if ($sw['count'] > 1 || count($sw['groups']) > 1):
    $sid = 'cabSw' . ($mobile ?? false ? 'M' : ''); ?>
  <form method="post" action="/app/switch" class="acc-switch<?= ($mobile ?? false) ? ' acc-switch-m' : '' ?>">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
    <label class="muted small" for="<?= $sid ?>">Кабинет WB</label>
    <select id="<?= $sid ?>" name="account" onchange="this.form.submit()">
      <?php foreach ($sw['groups'] as $g): ?>
        <optgroup label="<?= e($g['label']) ?>">
          <?php foreach ($g['stores'] as $s): $v = $g['owner'] . ':' . (int) $s['id']; ?>
            <option value="<?= e($v) ?>" <?= $sw['value'] === $v ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
          <?php if (count($g['stores']) > 1): $v = $g['owner'] . ':0'; ?>
            <option value="<?= e($v) ?>" <?= $sw['value'] === $v ? 'selected' : '' ?>>Все магазины вместе</option>
          <?php elseif (!$g['stores']): $v = $g['owner'] . ':0'; ?>
            <option value="<?= e($v) ?>" <?= $sw['value'] === $v ? 'selected' : '' ?>>Мой кабинет (магазинов нет)</option>
          <?php endif; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
  </form>
<?php endif; ?>
