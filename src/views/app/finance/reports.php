<?php if (!empty($report)): $pages = (int) ceil($count / 200); ?>
  <div class="card" style="margin-bottom:16px">
    <div class="row" style="justify-content:space-between">
      <div>
        <div class="card-title" style="margin:0">Отчёт <?= (int) $report['report_id'] > 0 ? '№ ' . (int) $report['report_id'] : '(из файла)' ?> за <?= date_ru($report['date_from'], false) ?> – <?= date_ru($report['date_to'], false) ?></div>
        <div class="muted small"><?= num_ru($count) ?> строк · к перечислению за товар <?= money($report['for_pay']) ?> · итого к оплате <?= money($report['bank_payment']) ?></div>
      </div>
      <div class="row">
        <?php if (!empty($plan['export'])): ?><a class="btn btn-ghost btn-sm" href="/app/finance/reports/<?= (int) $report['store_id'] ?>/<?= (int) $report['report_id'] ?>?export=1"><?= icon('download', 16) ?> CSV</a><?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="/app/finance/reports<?= $qs() ?>">← Все отчёты</a>
      </div>
    </div>
  </div>
  <div class="card" style="padding:0">
    <div class="table-wrap">
      <table class="table fin-table small-table">
        <thead><tr><th>Артикул</th><th>Тип документа</th><th>Обоснование</th><th class="num">Цена со скидкой</th><th class="num">Реализовал WB</th><th class="num">К перечислению</th><th class="num">Эквайринг</th><th class="num">Логистика</th><th class="num">Хранение</th><th class="num">Приёмка</th><th class="num">Штрафы</th><th>Вид логистики</th><th>Дата продажи</th><th>Srid</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><td><?= e($r['vendor_code'] ?: '') ?><div class="muted small"><?= $r['nm_id'] ? (int) $r['nm_id'] : '' ?></div></td><td><?= e($r['doc_type'] ?: '') ?></td><td><?= e($r['oper']) ?></td>
            <td class="num"><?= num_ru($r['retail_price_disc'], 2) ?></td><td class="num"><?= num_ru($r['retail_amount'], 2) ?></td><td class="num"><?= num_ru($r['for_pay'], 2) ?></td>
            <td class="num"><?= num_ru($r['acquiring_fee'], 2) ?></td><td class="num"><?= num_ru($r['delivery_service'], 2) ?></td><td class="num"><?= num_ru($r['paid_storage'], 2) ?></td>
            <td class="num"><?= num_ru($r['paid_acceptance'], 2) ?></td><td class="num"><?= num_ru($r['penalty'], 2) ?></td><td class="small"><?= e($r['bonus_type'] ?: '') ?></td>
            <td class="nowrap"><?= date_ru($r['sale_dt'], false) ?></td><td class="mono small"><?= e($r['srid'] ?: '') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($pages > 1): ?>
    <div class="row" style="margin-top:12px">
      <?php for ($i = 1; $i <= $pages; $i++): ?><a class="btn btn-sm <?= $i === $page ? '' : 'btn-ghost' ?>" href="?page=<?= $i ?>"><?= $i ?></a><?php endfor; ?>
    </div>
  <?php endif; ?>
<?php return; endif; ?>

<div class="products-side" style="margin-bottom:16px">
    <div class="card">
      <div class="card-title">Загрузка из WB</div>
      <?php foreach ($status as $st): ?>
        <div class="src-list small">
          <div><b><?= e($st['name']) ?></b></div>
          <div>Отчёты: <?= $st['finance'] === false ? '<span class="neg">нет доступа</span>' : ($st['reports'] ? $st['done'] . ' из ' . $st['reports'] . ($st['min'] ? ', с ' . date_ru($st['min'], false) : '') : 'ожидание…') ?></div>
          <div>Платное хранение: <?= $st['storage'] === false ? '<span class="neg">нет доступа</span>' : ($st['storage_ok'] ? 'обновлено ' . date_ru($st['storage_ok']) : 'ожидание…') ?></div>
          <div>Реклама: <?= $st['advert'] === false ? '<span class="neg">нет категории «Продвижение»</span>' : ($st['ads_ok'] ? 'обновлено ' . date_ru($st['ads_ok']) : 'ожидание…') ?></div>
        </div>
      <?php endforeach; ?>
      <p class="muted small">Новые отчёты WB формирует по понедельникам — сервис проверяет их каждые 6 часов. Нужен токен «Персональный» с категориями «Финансы», «Аналитика» и «Продвижение».</p>
      <?php if ($canEdit): ?>
        <form method="post" action="/app/finance/history" class="row" style="gap:8px">
          <?= csrf_field() ?>
          <select name="months" style="width:auto">
            <option value="6">за 6 месяцев</option><option value="12">за 12 месяцев</option><option value="36">всю историю (с 29.01.2024)</option>
          </select>
          <button class="btn btn-ghost btn-sm">Загрузить историю</button>
        </form>
      <?php endif; ?>
    </div>
    <?php if ($canEdit): ?>
      <div class="card">
        <div class="card-title">Загрузить из файла</div>
        <p class="muted small">Если у токена нет категории «Финансы»: в кабинете WB откройте «Финансовые отчёты», выберите отчёт и нажмите «Скачать детализацию» — загрузите полученный файл .xlsx или архив .zip как есть, распаковывать не нужно.</p>
        <form method="post" action="/app/finance/upload" enctype="multipart/form-data" class="stack">
          <?= csrf_field() ?>
          <?php if (count($activeIds) > 1): ?>
            <select name="store_id"><?php foreach ($stores as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
          <?php endif; ?>
          <input type="file" name="file" accept=".xlsx,.zip" required>
          <button class="btn btn-sm">Загрузить</button>
        </form>
      </div>
    <?php endif; ?>
  </div>

<div class="card" style="padding:0">
    <div class="row" style="justify-content:space-between;padding:14px 16px 0">
      <div class="card-title" style="margin:0">Еженедельные отчёты реализации</div>
      <?php if (!empty($plan['export'])): ?><a class="btn btn-ghost btn-sm" href="/app/finance/export<?= $qs() ?>"><?= icon('download', 16) ?> Строки за период (CSV)</a><?php endif; ?>
    </div>
    <?php if (!$reports): ?>
      <p class="muted" style="padding:16px">Отчётов пока нет. Они загружаются автоматически из WB (1 отчёт в минуту) или из файла — форма справа.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table fin-table">
          <thead><tr><th>Период</th><th>Отчёт</th><th>Валюта</th><?= count($activeIds) > 1 ? '<th>Магазин</th>' : '' ?><th class="num">Продажа</th><th class="num">К перечислению за товар</th><th class="num">Логистика</th><th class="num">Хранение</th><th class="num">Итого к оплате (WB)</th><th class="num">Наш расчёт</th><th>Строк</th></tr></thead>
          <tbody>
          <?php foreach ($reports as $r): $diff = $r['pay_calc'] !== null ? abs((float) $r['pay_calc'] - (float) $r['bank_payment']) : null; ?>
            <tr>
              <td class="nowrap"><a href="/app/finance/reports/<?= (int) $r['store_id'] ?>/<?= (int) $r['report_id'] ?>"><?= date('d.m', strtotime($r['date_from'])) ?> – <?= date('d.m.y', strtotime($r['date_to'])) ?></a></td>
              <td class="small"><?= (int) $r['report_id'] > 0 ? (int) $r['report_id'] . ((int) $r['report_type'] === 2 ? ' · по выкупам' : '') : 'из файла' ?>
                <?php if ((int) $r['report_id'] < 0 && $canEdit): ?>
                  <form method="post" action="/app/finance/reports/<?= (int) $r['store_id'] ?>/<?= (int) $r['report_id'] ?>/delete" style="display:inline" data-confirm="Удалить загруженный отчёт?"><?= csrf_field() ?><button class="link-btn">удалить</button></form>
                <?php endif; ?></td>
              <td class="small"><?= e($r['currency'] ?: '—') ?></td>
              <?= count($activeIds) > 1 ? '<td>' . e($r['store_name']) . '</td>' : '' ?>
              <td class="num"><?= num_ru($r['retail_amount']) ?></td>
              <td class="num"><?= num_ru($r['for_pay']) ?></td>
              <td class="num"><?= num_ru($r['delivery']) ?></td>
              <td class="num"><?= num_ru($r['storage']) ?></td>
              <td class="num b"><?= num_ru($r['bank_payment']) ?></td>
              <td class="num"><?= (int) $r['detail_done'] ? num_ru($r['pay_calc']) . ($diff !== null && $diff < 1 ? ' <span class="pos" title="Совпадает с WB">✓</span>' : '') : '<span class="muted small">загрузка…</span>' ?></td>
              <td class="small muted"><?= num_ru($r['rows_loaded']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
