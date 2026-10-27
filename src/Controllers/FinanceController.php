<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Finance;
use App\Services\Fx;
use App\Services\FinanceSync;
use App\Services\Plans;
use App\Services\Profit;
use App\Services\ReportImport;
use App\Services\StoreService;

/**
 * Раздел «Финансы»: расчёт чистой прибыли по еженедельным отчётам реализации WB.
 * Вкладки повторяют листы таблицы «9.5»: Дашборд, ОПИУ, Отчёт по SKU, ABC-анализ, Детализация,
 * Исходник (отчёты WB), Себестоимость, Хранение на SKU, Реклама на SKU, Прочие расходы.
 */
final class FinanceController
{
    public const TABS = [
        ''         => 'Сводка',
        'opiu'     => 'ОПИУ',
        'sku'      => 'По товарам',
        'abc'      => 'ABC-анализ',
        'orders'   => 'Детализация',
        'reports'  => 'Отчёты WB',
        'costs'    => 'Себестоимость',
        'storage'  => 'Хранение',
        'ads'      => 'Реклама',
        'expenses' => 'Прочие расходы',
    ];

    /** Общий контекст: кабинет, тариф, магазины, период. */
    private function ctx(): array
    {
        $user = Auth::require();
        $account = Auth::account();
        $plan = Plans::forUser($account);
        $active = StoreService::visibleIds((int) $account['id'], $plan);
        // Клиенту менеджера с доступом к отдельным магазинам не показываем общие расходы кабинета
        Finance::$commonExpenses = StoreService::memberAccess($user) === null;
        $storeId = (int) input('store', 0);
        $ids = $storeId && in_array($storeId, $active, true) ? [$storeId] : $active;
        // Валюта: отчёты WB приходят в валюте продавца; при разных валютах считаем по одной (выбор ?cur=)
        $currencies = $ids ? array_column(DB::all('SELECT currency, COUNT(*) c FROM wb_reports WHERE store_id IN (' . implode(',', $ids) . ")
            AND currency <> '' GROUP BY currency ORDER BY c DESC"), 'currency') : [];
        $cur = in_array(strtoupper((string) input('cur', '')), $currencies, true) ? strtoupper((string) input('cur')) : ($currencies[0] ?? currency_of_stores($ids)['code']);
        Finance::$currency = count($currencies) > 1 ? $cur : null;
        if ($cur) {
            currency_use($cur);
        }
        // «Показывать в рублях»: все валюты пересчитываются по курсу ЦБ и складываются
        $fxOn = Fx::enabled($account) && $currencies && $currencies !== ['RUB'];
        if ($fxOn) {
            Finance::$toRub = true;
            Finance::$currency = null;
            currency_use('RUB');
            $currencies = [];
            $cur = 'RUB';
        }
        $periods = Finance::availablePeriods($ids);
        [$from, $to, $preset] = $this->period($periods);
        return [
            'user' => $user, 'account' => $account, 'plan' => $plan, 'activeIds' => $active, 'storeIds' => $ids, 'storeId' => $storeId,
            'stores' => $active ? DB::all('SELECT * FROM stores WHERE id IN (' . implode(',', $active) . ') ORDER BY id') : [],
            'periods' => $periods, 'from' => $from, 'to' => $to, 'preset' => $preset,
            'canEdit' => !Auth::isMember(), 'settings' => Profit::settings($account),
            'currencies' => $currencies, 'currency' => $cur, 'fxOn' => $fxOn,
        ];
    }

    /** Период: последние 4/12 недель, месяц, неделя, всё время или произвольные даты. По умолчанию — 4 недели. */
    private function period(array $periods): array
    {
        $preset = (string) input('p', 'w4');
        $weeks = $periods['weeks'];
        $max = $periods['max'] ? date('Y-m-d', strtotime($periods['max']) + 6 * 86400) : date('Y-m-d');
        if (preg_match('/^m:(\d{4}-\d{2})$/', $preset, $m)) {
            return [$m[1] . '-01', date('Y-m-t', strtotime($m[1] . '-01')), $preset];
        }
        if (preg_match('/^w:(\d{4}-\d{2}-\d{2})$/', $preset, $m)) {
            return [$m[1], date('Y-m-d', strtotime($m[1]) + 6 * 86400), $preset];
        }
        if ($preset === 'custom') {
            $f = (string) input('from', '');
            $t = (string) input('to', '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && $f <= $t) {
                return [$f, $t, 'custom'];
            }
            $preset = 'w4';
        }
        if ($preset === 'all') {
            return [$periods['min'] ?? date('Y-m-d', time() - 90 * 86400), $max, 'all'];
        }
        $n = $preset === 'w12' ? 12 : 4;
        $from = $weeks[min($n, count($weeks)) - 1] ?? date('Y-m-d', time() - $n * 7 * 86400);
        return [$from, $max, $n === 12 ? 'w12' : 'w4'];
    }

    private function render(string $tab, array $ctx, array $data = []): string
    {
        $status = $this->status($ctx['storeIds'], $ctx['stores']);
        return view('app/finance', $ctx + $data + [
            'title' => 'Финансы — ' . self::TABS[$tab], 'tab' => $tab, 'status' => $status, 'nav' => 'finance',
            'locked' => !Plans::finance($ctx['plan']),
        ], 'app');
    }

    /** Состояние загрузки по магазинам: категория токена, сколько отчётов загружено, хранение, реклама. */
    private function status(array $storeIds, array $stores): array
    {
        $out = [];
        foreach ($stores as $s) {
            if (!in_array((int) $s['id'], $storeIds, true)) {
                continue;
            }
            $scopes = json_decode((string) $s['scopes'], true) ?: [];
            $rep = DB::one('SELECT COUNT(*) total, SUM(detail_done) done, MIN(date_from) min_from, MAX(date_to) max_to,
                (SELECT COUNT(*) FROM wb_reports WHERE store_id = ? AND report_id < 0) uploaded FROM wb_reports WHERE store_id = ? AND report_id > 0', [$s['id'], $s['id']]);
            $out[] = [
                'id' => (int) $s['id'], 'name' => $s['name'], 'finance' => $scopes['finance'] ?? null, 'advert' => $scopes['advert'] ?? null,
                'storage' => $scopes['storage'] ?? null, 'error' => $scopes['finance_error'] ?? null,
                'reports' => (int) $rep['total'], 'done' => (int) $rep['done'], 'uploaded' => (int) $rep['uploaded'], 'min' => $rep['min_from'], 'max' => $rep['max_to'],
                'fin_ok' => $s['fin_ok_at'], 'storage_ok' => $s['storage_ok_at'], 'ads_ok' => $s['ads_ok_at'],
                'history' => (json_decode((string) $s['fin_state'], true) ?: [])['from'] ?? null,
            ];
        }
        return $out;
    }

    private function locked(array $ctx, string $tab): ?string
    {
        return !Plans::finance($ctx['plan']) ? $this->render($tab, $ctx) : null;
    }

    private function photos(array $storeIds, array $nmIds): array
    {
        if (!$storeIds || !$nmIds) {
            return [];
        }
        $rows = DB::all('SELECT nm_id, photo, title, cost_price FROM products WHERE store_id IN (' . implode(',', array_map('intval', $storeIds)) . ')
            AND nm_id IN (' . implode(',', array_map('intval', $nmIds)) . ')');
        return array_column($rows, null, 'nm_id');
    }

    // ------------------------------------------------------------------ Сводка (Дашборд)

    public function index(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, '')) {
            return $html;
        }
        $res = Finance::compute($ctx['account'], $ctx['storeIds'], $ctx['from'], $ctx['to'], ['group' => 'week', 'sku' => true]);
        $skus = $res['bySku'];
        $bySubject = [];
        foreach ($skus as $s) {
            $k = $s['subject'] ?: 'Без категории';
            $bySubject[$k] ??= ['revenue' => 0.0, 'profit' => 0.0, 'qty' => 0];
            $bySubject[$k]['revenue'] += $s['revenue'];
            $bySubject[$k]['profit'] += $s['profit'];
            $bySubject[$k]['qty'] += $s['sales_qty'];
        }
        uasort($bySubject, static fn($a, $b) => $b['revenue'] <=> $a['revenue']);
        uasort($skus, static fn($a, $b) => $b['profit'] <=> $a['profit']);
        $top = array_slice(array_filter($skus, static fn($s) => $s['profit'] > 0), 0, 8, true);
        $worst = array_slice(array_filter(array_reverse($skus, true), static fn($s) => $s['profit'] < 0), 0, 5, true);
        return $this->render('', $ctx, [
            'res' => $res, 'bySubject' => array_slice($bySubject, 0, 10, true), 'top' => $top, 'worst' => $worst,
            'photos' => $this->photos($ctx['storeIds'], array_merge(array_keys($top), array_keys($worst))),
        ]);
    }

    // ------------------------------------------------------------------ ОПИУ

    public function opiu(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'opiu')) {
            return $html;
        }
        $days = (strtotime($ctx['to']) - strtotime($ctx['from'])) / 86400;
        $group = in_array(input('g'), ['week', 'month'], true) ? (string) input('g') : ($days > 70 ? 'month' : 'week');
        $res = Finance::compute($ctx['account'], $ctx['storeIds'], $ctx['from'], $ctx['to'], ['group' => $group]);
        $expenses = [];
        if ($ctx['storeIds']) {
            foreach (DB::all('SELECT category, day, amount FROM fin_expenses WHERE user_id = ? AND day BETWEEN ? AND ?', [(int) $ctx['account']['id'], $ctx['from'], $ctx['to']]) as $e) {
                $pk = Finance::periodKey(Finance::weekStart($e['day']), $group);
                $expenses[$e['category']][$pk] = ($expenses[$e['category']][$pk] ?? 0) + (float) $e['amount'];
            }
        }
        return $this->render('opiu', $ctx, ['res' => $res, 'group' => $group, 'expenses' => $expenses]);
    }

    // ------------------------------------------------------------------ Отчёт по SKU и ABC

    private function skuData(array $ctx): array
    {
        $res = Finance::compute($ctx['account'], $ctx['storeIds'], $ctx['from'], $ctx['to'], ['group' => 'none', 'sku' => true]);
        $skus = $res['bySku'];
        Finance::abc($skus);
        return [$res, $skus];
    }

    public function sku(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'sku')) {
            return $html;
        }
        [$res, $skus] = $this->skuData($ctx);
        $sort = in_array(input('sort'), ['revenue', 'profit', 'sales_qty', 'margin', 'cost'], true) ? (string) input('sort') : 'revenue';
        uasort($skus, static fn($a, $b) => ($b[$sort] ?? -INF) <=> ($a[$sort] ?? -INF));
        if ((string) input('export') === '1') {
            $this->requireExport($ctx);
            $this->exportSku($skus, $ctx);
        }
        return $this->render('sku', $ctx, ['res' => $res, 'skus' => $skus, 'sort' => $sort, 'photos' => $this->photos($ctx['storeIds'], array_keys($skus))]);
    }

    private function exportSku(array $skus, array $ctx): never
    {
        $cols = ['sales_qty', 'deliveries', 'back_deliveries', 'returns_qty', 'defect_qty', 'lost_qty', 'revenue', 'spp_comp', 'commission', 'acquiring', 'logistics',
            'loyalty', 'turnover', 'cost', 'storage', 'acceptance', 'penalty', 'tax', 'vat', 'ads', 'exp_sku', 'profit'];
        $this->csvStart('pribyl_po_tovaram_' . $ctx['from'] . '_' . $ctx['to']);
        $out = fopen('php://output', 'w');
        fputcsv($out, array_merge(['Артикул WB', 'Артикул продавца', 'Предмет', 'Бренд'], array_map(static fn($c) => Finance::LABELS[$c], $cols), ['Маржа, %', 'ABC выручка', 'ABC прибыль']), ';');
        foreach ($skus as $s) {
            $line = [$s['nm_id'], $s['vendor_code'], $s['subject'], $s['brand']];
            foreach ($cols as $c) {
                $line[] = self::csvNum($s[$c]);
            }
            $line[] = $s['margin'] !== null ? self::csvNum($s['margin']) : '';
            $line[] = $s['abc_rev'];
            $line[] = $s['abc_profit'];
            fputcsv($out, $line, ';');
        }
        exit;
    }

    public function abc(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'abc')) {
            return $html;
        }
        [$res, $skus] = $this->skuData($ctx);
        $matrix = [];
        foreach ($skus as $s) {
            $k = $s['abc_rev'] . $s['abc_profit'];
            $matrix[$k] ??= ['count' => 0, 'revenue' => 0.0, 'profit' => 0.0];
            $matrix[$k]['count']++;
            $matrix[$k]['revenue'] += $s['revenue'];
            $matrix[$k]['profit'] += $s['profit'];
        }
        uasort($skus, static fn($a, $b) => strcmp($a['abc_rev'] . $a['abc_profit'], $b['abc_rev'] . $b['abc_profit']) ?: $b['revenue'] <=> $a['revenue']);
        return $this->render('abc', $ctx, ['res' => $res, 'skus' => $skus, 'matrix' => $matrix, 'photos' => $this->photos($ctx['storeIds'], array_keys($skus))]);
    }

    // ------------------------------------------------------------------ Детализация по заказам

    public function orders(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'orders')) {
            return $html;
        }
        $q = mb_substr((string) input('q', ''), 0, 80);
        // Сначала страница заказов из базы, потом расчёт только по ним — за «всё время» строк сотни тысяч
        $perPage = 300;
        $page = max(1, (int) input('page', 1));
        [$srids, $total] = Finance::sridPage($ctx['storeIds'], $ctx['from'], $ctx['to'], $q !== '' ? $q : null, $perPage, ($page - 1) * $perPage);
        $res = $srids ? Finance::compute($ctx['account'], $ctx['storeIds'], $ctx['from'], $ctx['to'], ['group' => 'none', 'srid' => true, 'srids' => $srids, 'srids_in_period' => true])
            : ['bySrid' => []];
        $orders = [];
        foreach ($srids as $sr) {
            if (isset($res['bySrid'][$sr])) {
                $orders[$sr] = $res['bySrid'][$sr];
            }
        }
        $rows = [];
        $srid = mb_substr((string) input('srid', ''), 0, 80);
        if ($srid !== '' && $ctx['storeIds']) {
            $rows = DB::all('SELECT * FROM wb_report_rows WHERE store_id IN (' . implode(',', $ctx['storeIds']) . ') AND srid = ? ORDER BY rrd_id', [$srid]);
        }
        return $this->render('orders', $ctx, ['res' => $res, 'orders' => $orders, 'total' => $total, 'page' => $page, 'perPage' => $perPage, 'q' => $q, 'srid' => $srid, 'rows' => $rows]);
    }

    // ------------------------------------------------------------------ Отчёты WB (Исходник)

    public function reports(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'reports')) {
            return $html;
        }
        $reports = [];
        if ($ctx['storeIds']) {
            $reports = DB::all('SELECT r.*, s.name store_name,
                    (SELECT SUM(CASE WHEN rr.doc_type = \'Продажа\' THEN rr.for_pay WHEN rr.doc_type = \'Возврат\' THEN -rr.for_pay ELSE 0 END)
                        - SUM(rr.delivery_service + rr.paid_storage + rr.paid_acceptance + rr.penalty + rr.deduction + rr.additional)
                        - SUM(CASE WHEN rr.doc_type = \'Возврат\' THEN -1 ELSE 1 END * (rr.cashback_amount + rr.cashback_comm))
                     FROM wb_report_rows rr WHERE rr.store_id = r.store_id AND rr.report_id = r.report_id) pay_calc
                FROM wb_reports r JOIN stores s ON s.id = r.store_id
                WHERE r.store_id IN (' . implode(',', $ctx['storeIds']) . ') ORDER BY r.date_from DESC, r.report_id DESC LIMIT 300');
        }
        return $this->render('reports', $ctx, ['reports' => $reports]);
    }

    public function report(string $storeId, string $reportId): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'reports')) {
            return $html;
        }
        $report = in_array((int) $storeId, $ctx['activeIds'], true)
            ? DB::one('SELECT * FROM wb_reports WHERE store_id = ? AND report_id = ?', [(int) $storeId, (int) $reportId]) : null;
        if (!$report) {
            flash('error', 'Отчёт не найден.');
            redirect('/app/finance/reports');
        }
        $page = max(1, (int) input('page', 1));
        $rows = DB::all('SELECT * FROM wb_report_rows WHERE store_id = ? AND report_id = ? ORDER BY rrd_id LIMIT 200 OFFSET ' . (($page - 1) * 200),
            [(int) $storeId, (int) $reportId]);
        $count = (int) DB::value('SELECT COUNT(*) FROM wb_report_rows WHERE store_id = ? AND report_id = ?', [(int) $storeId, (int) $reportId]);
        if ((string) input('export') === '1') {
            $this->requireExport($ctx);
            $this->exportRows('WHERE store_id = ' . (int) $storeId . ' AND report_id = ' . (int) $reportId, 'otchet_' . $report['date_from'] . '_' . (int) $reportId);
        }
        return $this->render('reports', $ctx, ['report' => $report, 'rows' => $rows, 'count' => $count, 'page' => $page]);
    }

    /** Выгрузка отчётов WB в Excel/CSV — только на тарифах с выгрузкой. */
    private function requireExport(array $ctx): void
    {
        if (empty($ctx['plan']['export'])) {
            flash('error', 'Выгрузка отчётов в Excel доступна на тарифе ' . Plans::namesWith('export') . '.');
            redirect('/app/finance/reports');
        }
    }

    /** Все строки периода в CSV (для сверки с Excel). */
    public function export(): never
    {
        $ctx = $this->ctx();
        if (!Plans::finance($ctx['plan']) || !$ctx['storeIds']) {
            redirect('/app/finance');
        }
        $this->requireExport($ctx);
        $this->exportRows('WHERE store_id IN (' . implode(',', $ctx['storeIds']) . ') AND date_from BETWEEN ' . DB::pdo()->quote($ctx['from']) . ' AND ' . DB::pdo()->quote($ctx['to']),
            'detalizaciya_' . $ctx['from'] . '_' . $ctx['to']);
    }

    private const EXPORT_COLUMNS = [
        'report_id' => '№ отчёта', 'date_from' => 'Период с', 'date_to' => 'Период по', 'rrd_id' => '№ строки', 'gi_id' => 'Номер поставки', 'subject' => 'Предмет',
        'nm_id' => 'Код номенклатуры', 'brand' => 'Бренд', 'vendor_code' => 'Артикул поставщика', 'title' => 'Название', 'ts_name' => 'Размер', 'barcode' => 'Баркод',
        'doc_type' => 'Тип документа', 'oper' => 'Обоснование для оплаты', 'order_dt' => 'Дата заказа', 'sale_dt' => 'Дата продажи', 'quantity' => 'Кол-во',
        'retail_price' => 'Цена розничная', 'retail_amount' => 'Вайлдберриз реализовал Товар (Пр)', 'sale_percent' => 'Согласованная скидка, %',
        'retail_price_disc' => 'Цена розничная с учётом согласованной скидки', 'commission_pct' => 'Размер кВВ, %', 'spp' => 'СПП, %',
        'acquiring_fee' => 'Эквайринг', 'for_pay' => 'К перечислению Продавцу', 'delivery_amount' => 'Количество доставок', 'return_amount' => 'Количество возврата',
        'delivery_service' => 'Услуги по доставке', 'penalty' => 'Штрафы', 'additional' => 'Корректировка ВВ', 'bonus_type' => 'Виды логистики, штрафов и корректировок',
        'office_name' => 'Склад', 'pvz_name' => 'Офис доставки', 'shk_id' => 'ШК', 'srid' => 'Srid', 'paid_storage' => 'Хранение', 'deduction' => 'Удержания',
        'paid_acceptance' => 'Платная приёмка', 'cashback_amount' => 'Баллы лояльности', 'cashback_discount' => 'Компенсация скидки лояльности',
        'cashback_comm' => 'Стоимость участия в лояльности', 'delivery_method' => 'Способ продажи', 'is_b2b' => 'Продажа юрлицу',
    ];

    private function exportRows(string $where, string $name): never
    {
        $this->csvStart($name);
        $out = fopen('php://output', 'w');
        fputcsv($out, array_values(self::EXPORT_COLUMNS), ';');
        $stmt = DB::pdo()->query('SELECT ' . implode(',', array_keys(self::EXPORT_COLUMNS)) . " FROM wb_report_rows $where ORDER BY date_from, rrd_id");
        while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            fputcsv($out, array_map(static fn($v) => is_string($v) && is_numeric($v) && str_contains($v, '.') ? str_replace('.', ',', $v) : $v, $r), ';');
        }
        exit;
    }

    private function csvStart(string $name): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^\w.-]/', '_', $name) . '.csv"');
        echo "\xEF\xBB\xBF";
    }

    private static function csvNum(float|int|null $v): string
    {
        return $v === null ? '' : str_replace('.', ',', (string) round((float) $v, 2));
    }

    /** Загрузка детализации из файла .xlsx, скачанного в кабинете WB (если у токена нет категории «Финансы»). */
    public function upload(): never
    {
        $ctx = $this->requireEdit();
        $storeId = (int) input('store_id', 0);
        if (!in_array($storeId, $ctx['activeIds'], true)) {
            $storeId = $ctx['activeIds'][0] ?? 0;
        }
        $file = $_FILES['file'] ?? null;
        if ($file && in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            error_log('[upload] store ' . $storeId . ': файл больше upload_max_filesize ' . ini_get('upload_max_filesize'));
            flash('error', 'Файл слишком большой для сервера (лимит ' . ini_get('upload_max_filesize') . '). Загрузите детализацию за одну неделю или напишите в поддержку.');
            redirect('/app/finance/reports');
        }
        if (!$storeId || !$file || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            flash('error', 'Выберите файл детализации отчёта (.xlsx), скачанный в кабинете WB.');
            redirect('/app/finance/reports');
        }
        if ((int) $file['size'] > 30 * 1024 * 1024) {
            flash('error', 'Файл больше 30 МБ. Загрузите детализацию за одну неделю.');
            redirect('/app/finance/reports');
        }
        try {
            $info = ReportImport::import($storeId, (string) $file['tmp_name'], (string) $file['name']);
            flash('success', 'Загружено строк: ' . $info['rows'] . ' за период ' . date_ru($info['from'], false) . ' – ' . date_ru($info['to'], false) . '.');
        } catch (\Throwable $e) {
            error_log('[upload] store ' . $storeId . ' «' . $file['name'] . '» ' . round((int) $file['size'] / 1048576, 1) . ' МБ: ' . $e->getMessage()
                . ($e instanceof \RuntimeException ? '' : ' @ ' . basename($e->getFile()) . ':' . $e->getLine()));
            flash('error', 'Не удалось прочитать файл: ' . $e->getMessage());
        }
        redirect('/app/finance/reports');
    }

    public function deleteReport(string $storeId, string $reportId): never
    {
        $ctx = $this->requireEdit();
        if (in_array((int) $storeId, $ctx['activeIds'], true) && (int) $reportId < 0) {
            DB::exec('DELETE FROM wb_report_rows WHERE store_id = ? AND report_id = ?', [(int) $storeId, (int) $reportId]);
            DB::exec('DELETE FROM wb_reports WHERE store_id = ? AND report_id = ?', [(int) $storeId, (int) $reportId]);
            flash('success', 'Загруженный отчёт удалён.');
        }
        redirect('/app/finance/reports');
    }

    /** Загрузить историю отчётов глубже (до 29.01.2024 — с этой даты WB отдаёт данные). */
    public function history(): never
    {
        $ctx = $this->requireEdit();
        $months = max(1, min(36, (int) input('months', 6)));
        $from = max(FinanceSync::WB_DATA_START, date('Y-m-d', strtotime("-$months months")));
        foreach ($ctx['activeIds'] as $id) {
            $state = json_decode((string) DB::value('SELECT fin_state FROM stores WHERE id = ?', [$id]), true) ?: [];
            if (!empty($state['from']) && $state['from'] <= $from) {
                continue;
            }
            $state['from'] = $from;
            unset($state['history_done'], $state['listed']);
            DB::exec('UPDATE stores SET fin_state = ?, fin_next_at = NULL, storage_state = ?, storage_next_at = NULL, ads_state = ?, ads_next_at = NULL WHERE id = ?',
                [json_encode($state), json_encode(['cursor' => $from]), json_encode(['cursor' => $from]), $id]);
        }
        flash('success', 'Загрузка отчётов с ' . date_ru($from, false) . ' запущена. WB отдаёт один отчёт в минуту — история появится постепенно.');
        redirect('/app/finance/reports');
    }

    // ------------------------------------------------------------------ Себестоимость

    public function costs(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'costs')) {
            return $html;
        }
        $items = [];
        if ($ctx['storeIds']) {
            $in = implode(',', $ctx['storeIds']);
            $items = DB::all("SELECT r.store_id, r.nm_id, MAX(r.vendor_code) vendor_code, MAX(r.subject) subject, MAX(r.brand) brand, MAX(r.title) title,
                    SUM(CASE WHEN r.doc_type = 'Продажа' AND r.oper IN ('Продажа','Корректная продажа') THEN 1 WHEN r.doc_type = 'Возврат' AND r.oper = 'Возврат' THEN -1 ELSE 0 END) qty,
                    p.cost_price, p.photo, p.title p_title
                FROM wb_report_rows r LEFT JOIN products p ON p.store_id = r.store_id AND p.nm_id = r.nm_id
                WHERE r.store_id IN ($in) AND r.nm_id IS NOT NULL AND r.date_from BETWEEN ? AND ?
                GROUP BY r.store_id, r.nm_id, p.cost_price, p.photo, p.title ORDER BY (p.cost_price IS NULL) DESC, qty DESC", [$ctx['from'], $ctx['to']]);
        }
        return $this->render('costs', $ctx, ['items' => $items]);
    }

    public function saveCosts(): never
    {
        $ctx = $this->requireEdit();
        $n = 0;
        foreach ((array) ($_POST['cost'] ?? []) as $key => $v) {
            if (!preg_match('/^(\d+)_(\d+)$/', (string) $key, $m) || !in_array((int) $m[1], $ctx['activeIds'], true)) {
                continue;
            }
            $v = str_replace([' ', ','], ['', '.'], trim((string) $v));
            $cost = $v === '' || !is_numeric($v) ? null : max(0.0, round((float) $v, 2));
            DB::exec('INSERT INTO products (store_id, nm_id, cost_price, updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cost_price = VALUES(cost_price)',
                [(int) $m[1], (int) $m[2], $cost]);
            $n++;
        }
        flash('success', 'Себестоимость сохранена (' . $n . ' ' . plural($n, 'товар', 'товара', 'товаров') . ').');
        $this->back('costs');
    }

    // ------------------------------------------------------------------ Хранение на SKU

    public function storage(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'storage')) {
            return $html;
        }
        $items = $days = [];
        $reportStorage = 0.0;
        if ($ctx['storeIds']) {
            $in = implode(',', $ctx['storeIds']);
            $items = DB::all("SELECT st.nm_id, MAX(st.vendor_code) vendor_code, MAX(st.subject) subject, SUM(st.amount) amount,
                    AVG(st.qty) avg_qty, MAX(st.volume) volume, COUNT(DISTINCT st.day) days, MAX(p.photo) photo, MAX(p.title) title
                FROM wb_storage st LEFT JOIN products p ON p.store_id = st.store_id AND p.nm_id = st.nm_id
                WHERE st.store_id IN ($in) AND st.day BETWEEN ? AND ? GROUP BY st.nm_id ORDER BY amount DESC", [$ctx['from'], $ctx['to']]);
            $days = DB::all("SELECT day, SUM(amount) amount FROM wb_storage WHERE store_id IN ($in) AND day BETWEEN ? AND ? GROUP BY day ORDER BY day", [$ctx['from'], $ctx['to']]);
            $reportStorage = (float) DB::value("SELECT SUM(paid_storage) FROM wb_report_rows WHERE store_id IN ($in) AND date_from BETWEEN ? AND ?", [$ctx['from'], $ctx['to']]);
        }
        return $this->render('storage', $ctx, ['items' => $items, 'days' => $days, 'reportStorage' => $reportStorage]);
    }

    // ------------------------------------------------------------------ Реклама на SKU

    public function ads(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'ads')) {
            return $html;
        }
        $camps = $bySku = [];
        if ($ctx['storeIds']) {
            $in = implode(',', $ctx['storeIds']);
            $camps = DB::all("SELECT c.store_id, c.advert_id, MAX(c.camp_name) camp_name, MAX(c.payment_type) payment_type, SUM(c.amount) amount, COUNT(*) cnt,
                    MIN(c.day) first_day, MAX(c.day) last_day,
                    (SELECT GROUP_CONCAT(n.nm_id) FROM wb_ad_nms n WHERE n.store_id = c.store_id AND n.advert_id = c.advert_id AND n.nm_id > 0) nms
                FROM wb_ad_costs c WHERE c.store_id IN ($in) AND c.day BETWEEN ? AND ? GROUP BY c.store_id, c.advert_id ORDER BY amount DESC", [$ctx['from'], $ctx['to']]);
            foreach ($camps as $c) {
                $nms = array_filter(explode(',', (string) $c['nms']));
                foreach ($nms as $nm) {
                    $bySku[(int) $nm] = ($bySku[(int) $nm] ?? 0) + (float) $c['amount'] / count($nms);
                }
            }
            arsort($bySku);
        }
        return $this->render('ads', $ctx, ['camps' => $camps, 'bySku' => $bySku, 'photos' => $this->photos($ctx['storeIds'], array_keys($bySku))]);
    }

    // ------------------------------------------------------------------ Прочие расходы

    public function expenses(): string
    {
        $ctx = $this->ctx();
        if ($html = $this->locked($ctx, 'expenses')) {
            return $html;
        }
        $only = Finance::$commonExpenses ? '' : ' AND e.store_id IN (' . (implode(',', $ctx['activeIds']) ?: '0') . ')';
        $items = DB::all('SELECT e.*, s.name store_name FROM fin_expenses e LEFT JOIN stores s ON s.id = e.store_id
            WHERE e.user_id = ? AND e.day BETWEEN ? AND ?' . $only . ' ORDER BY e.day DESC, e.id DESC', [(int) $ctx['account']['id'], $ctx['from'], $ctx['to']]);
        return $this->render('expenses', $ctx, ['items' => $items]);
    }

    public function addExpense(): never
    {
        $ctx = $this->requireEdit();
        $day = (string) input('day', '');
        $amount = (float) str_replace([' ', ','], ['', '.'], (string) input('amount', '0'));
        $category = mb_substr(trim((string) input('category', '')), 0, 80);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $amount == 0.0 || $category === '') {
            flash('error', 'Укажите дату, статью и сумму расхода.');
            $this->back('expenses');
        }
        $storeId = (int) input('store_id', 0);
        $nm = preg_replace('/\D/', '', (string) input('nm_id', ''));
        DB::insert('fin_expenses', [
            'user_id' => (int) $ctx['account']['id'], 'store_id' => in_array($storeId, $ctx['activeIds'], true) ? $storeId : null, 'day' => $day,
            'category' => $category, 'nm_id' => $nm !== '' ? (int) $nm : null, 'amount' => round($amount, 2),
            'note' => mb_substr(trim((string) input('note', '')), 0, 255) ?: null, 'created_at' => now(),
        ]);
        flash('success', 'Расход добавлен.');
        $this->back('expenses');
    }

    public function deleteExpense(string $id): never
    {
        $ctx = $this->requireEdit();
        DB::exec('DELETE FROM fin_expenses WHERE id = ? AND user_id = ?', [(int) $id, (int) $ctx['account']['id']]);
        flash('success', 'Расход удалён.');
        $this->back('expenses');
    }

    private function requireEdit(): array
    {
        Auth::requireOwner();
        $ctx = $this->ctx();
        if (!Plans::finance($ctx['plan'])) {
            flash('error', 'Раздел «Финансы» доступен на платных тарифах.');
            redirect('/app/billing');
        }
        return $ctx;
    }

    private function back(string $tab): never
    {
        $q = array_filter(['p' => input('p'), 'from' => input('from'), 'to' => input('to'), 'store' => input('store'), 'cur' => input('cur')]);
        redirect('/app/finance' . ($tab !== '' && isset(self::TABS[$tab]) ? '/' . $tab : '') . ($q ? '?' . http_build_query($q) : ''));
    }
}
