<?php
use App\Controllers\FinanceController;
use App\Core\View;
use App\Services\Finance;

$monthsRu = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
// Параметры периода и магазина — сохраняются при переходе между вкладками
$keep = array_filter(['p' => $preset !== 'w4' ? $preset : null, 'from' => $preset === 'custom' ? $from : null, 'to' => $preset === 'custom' ? $to : null, 'store' => $storeId ?: null, 'cur' => count($currencies) > 1 ? $currency : null]);
$qs = static fn(array $extra = []) => ($q = http_build_query(array_filter($extra + $keep, static fn($v) => $v !== null && $v !== ''))) ? '?' . $q : '';
$hidden = '';
foreach ($keep as $k => $v) {
    $hidden .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
}
$hasData = ($periods['max'] ?? null) !== null;
?>
<div class="topbar">
  <h1>Финансы</h1>
  <span class="muted small">Чистая прибыль по отчётам реализации WB</span>
</div>

<nav class="tabs" aria-label="Разделы финансов">
  <?php foreach (FinanceController::TABS as $k => $label): ?>
    <a href="/app/finance<?= $k !== '' ? '/' . $k : '' ?><?= $qs() ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($locked): ?>
  <div class="card empty">
    <div class="ico"><?= icon('chart', 30) ?></div>
    <h3>Расчёт чистой прибыли по отчётам WB</h3>
    <p>Сервис сам загружает еженедельные отчёты реализации, хранение и рекламу и считает прибыль по каждому товару и периоду — как в Excel-таблице, только автоматически. Доступно на платных тарифах.</p>
    <a class="btn btn-lg" href="/app/billing">Выбрать тариф</a>
  </div>
<?php return; endif; ?>

<?php if (!$activeIds): ?>
  <div class="card empty">
    <div class="ico"><?= icon('store', 30) ?></div>
    <h3>Подключите магазин</h3>
    <p>Для расчёта нужен токен WB с категорией «Финансы» (тип «Персональный»).</p>
    <a class="btn btn-lg" href="/app/stores"><?= icon('plus', 18) ?> Подключить магазин</a>
  </div>
<?php return; endif; ?>

<?php foreach ($status as $st): ?>
  <?php if ($st['finance'] === false): ?>
    <div class="alert alert-warn">
      <b><?= e($st['name']) ?>:</b> WB не дал доступ к отчётам реализации<?= $st['error'] ? ' (' . e($st['error']) . ')' : '' ?>.
      Создайте в кабинете WB токен типа <b>«Персональный»</b> с категориями <b>«Финансы»</b> и «Продвижение» (для рекламы) и замените его в разделе
      <a href="/app/stores">Магазины</a>. Пока можно загрузить детализацию из файла на вкладке <a href="/app/finance/reports<?= $qs() ?>">«Отчёты WB»</a>.
    </div>
  <?php elseif ($st['reports'] === 0 && $st['uploaded'] === 0 && $st['finance'] === null): ?>
    <div class="alert alert-info"><b><?= e($st['name']) ?>:</b> проверяем доступ токена к отчётам реализации WB. Можно не ждать и загрузить детализацию из файла на вкладке <a href="/app/finance/reports<?= $qs() ?>">«Отчёты WB»</a>.</div>
  <?php elseif ($st['reports'] === 0 && $st['uploaded'] === 0): ?>
    <div class="alert alert-info"><b><?= e($st['name']) ?>:</b> загружаем отчёты реализации из WB. WB отдаёт один отчёт в минуту — первые данные появятся через несколько минут, история за 3 месяца — примерно за полчаса.</div>
  <?php elseif ($st['done'] < $st['reports']): ?>
    <div class="alert alert-info"><b><?= e($st['name']) ?>:</b> загружено <?= $st['done'] ?> из <?= $st['reports'] ?> отчётов, остальные подгружаются (1 отчёт в минуту).</div>
  <?php endif; ?>
<?php endforeach; ?>

<form class="toolbar" method="get" data-auto>
  <select name="p" aria-label="Период" data-custom-toggle>
    <option value="w4" <?= $preset === 'w4' ? 'selected' : '' ?>>Последние 4 недели</option>
    <option value="w12" <?= $preset === 'w12' ? 'selected' : '' ?>>Последние 12 недель</option>
    <option value="all" <?= $preset === 'all' ? 'selected' : '' ?>>Всё время</option>
    <?php if ($periods['months']): ?>
      <optgroup label="Месяц">
        <?php foreach ($periods['months'] as $m): ?>
          <option value="m:<?= e($m) ?>" <?= $preset === 'm:' . $m ? 'selected' : '' ?>><?= e(mb_convert_case($monthsRu[(int) substr($m, 5, 2) - 1], MB_CASE_TITLE) . ' ' . substr($m, 0, 4)) ?></option>
        <?php endforeach; ?>
      </optgroup>
      <optgroup label="Неделя (отчёт)">
        <?php foreach (array_slice($periods['weeks'], 0, 30) as $w): ?>
          <option value="w:<?= e($w) ?>" <?= $preset === 'w:' . $w ? 'selected' : '' ?>><?= e(Finance::periodLabel($w, 'week')) ?></option>
        <?php endforeach; ?>
      </optgroup>
    <?php endif; ?>
    <option value="custom" <?= $preset === 'custom' ? 'selected' : '' ?>>Свои даты…</option>
  </select>
  <span class="custom-dates <?= $preset === 'custom' ? 'on' : '' ?>">
    <input type="date" name="from" value="<?= e($from) ?>" aria-label="С">
    <span class="muted">—</span>
    <input type="date" name="to" value="<?= e($to) ?>" aria-label="По">
    <button class="btn btn-sm">Показать</button>
  </span>
  <?php if (count($activeIds) > 1): ?>
    <select name="store" aria-label="Магазин">
      <option value="0">Все магазины</option>
      <?php foreach ($stores as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $storeId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>
  <?php foreach (['g', 'q', 'sort'] as $k): if (input($k)): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) input($k)) ?>"><?php endif; endforeach; ?>
  <span class="muted small">Отчёты с <?= date_ru($from, false) ?> по <?= date_ru($to, false) ?></span>
</form>
<?= View::partial('partials/currency_note', ['storeIds' => $storeIds, 'fxOn' => $fxOn]) ?>
<?php if (count($currencies) > 1): ?>
  <div class="row" style="margin:-4px 0 14px;gap:10px">
    <div class="seg"><?php foreach ($currencies as $c): ?><a href="?<?= e(http_build_query(['cur' => $c] + $keep)) ?>" class="<?= $c === $currency ? 'on' : '' ?>"><?= e($c . ' ' . currency_sign($c)) ?></a><?php endforeach; ?></div>
    <span class="muted small">Отчёты в каждой валюте — отдельно.</span>
  </div>
<?php endif; ?>

<?= View::partial('app/finance/' . ($tab !== '' ? $tab : 'summary'), get_defined_vars()) ?>
