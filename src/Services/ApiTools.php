<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Controllers\FeedController;

/**
 * Инструменты внешнего API — одни и те же для REST (/api/v1/<имя>) и MCP-сервера (/mcp/<ключ>) для Claude.
 * Все инструменты только читают данные кабинета, которому принадлежит ключ.
 */
final class ApiTools
{
    private const PERIOD_ORDERS = ['type' => 'string', 'enum' => ['today', 'yesterday', '7d', '30d', 'custom'],
        'description' => 'Период по дате заказа: today, yesterday, 7d, 30d или custom (тогда нужны from и to)'];
    private const PERIOD_FIN = ['type' => 'string',
        'description' => 'Период отчётов реализации: w4 (последние 4 недели, по умолчанию), w12, all, месяц ГГГГ-ММ или неделя week:ГГГГ-ММ-ДД. Вместо него можно передать from и to'];
    private const DATE = ['type' => 'string', 'description' => 'Дата ГГГГ-ММ-ДД'];
    private const STORE = ['type' => 'integer', 'description' => 'ID магазина (из account_info); не указан — все магазины'];
    private const CURRENCY = ['type' => 'string', 'description' => 'Валюта отчётов (RUB, KGS, KZT…), если у магазинов отчёты в разных валютах; не указана — все отчёты'];

    /** Описание инструментов (JSON Schema входных параметров). */
    public static function definitions(array $account): array
    {
        $tools = [
            'account_info' => [
                'title' => 'Кабинет и магазины',
                'description' => 'Кабинет, тариф и срок, подключённые магазины WB: категории токена, состояние загрузки, ошибки, число заказов. Начинайте с этого инструмента.',
                'properties' => [],
            ],
            'orders_summary' => [
                'title' => 'Сводка по заказам',
                'description' => 'Сводка ленты заказов за период: заказы, выкупы, отмены, возвраты, суммы, средний чек, оценка прибыли, топ товаров и график по часам/дням.',
                'properties' => ['period' => self::PERIOD_ORDERS, 'from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE,
                    'scheme' => ['type' => 'string', 'description' => 'Схема: FBO, SELLER (все со склада продавца), FBS, DBS, DBW, CC']],
            ],
            'list_orders' => [
                'title' => 'Список заказов',
                'description' => 'Заказы из ленты (новые сверху) с товаром, ценой, статусом, схемой, складом, городом и расчётом прибыли по каждому.',
                'properties' => ['period' => self::PERIOD_ORDERS, 'from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE,
                    'status' => ['type' => 'string', 'enum' => ['created', 'buyout', 'cancel', 'return'], 'description' => 'Статус заказа'],
                    'scheme' => ['type' => 'string', 'description' => 'FBO, SELLER, FBS, DBS, DBW, CC'],
                    'q' => ['type' => 'string', 'description' => 'Поиск: артикул, WB ID, бренд, город'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Сколько заказов вернуть (по умолчанию 50)']],
            ],
            'products' => [
                'title' => 'Товары',
                'description' => 'Товары: себестоимость, комиссия WB категории, объём, остатки на складах WB, заказы за 30 дней и средняя цена.',
                'properties' => ['q' => ['type' => 'string', 'description' => 'Поиск по артикулу, названию, бренду, предмету'], 'store_id' => self::STORE,
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'По умолчанию 100']],
            ],
            'finance_summary' => [
                'title' => 'Финансы: сводка',
                'description' => 'Чистая прибыль по отчётам реализации WB за период: выручка, комиссия, логистика, удержания, себестоимость, налог, реклама, прочие расходы, прибыль, маржа, ROI, сверка «Итого к оплате»; разбивка по неделям.',
                'properties' => ['currency' => self::CURRENCY, 'period' => self::PERIOD_FIN, 'from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE],
            ],
            'finance_opiu' => [
                'title' => 'Финансы: ОПИУ',
                'description' => 'Отчёт о прибылях и убытках по неделям или месяцам (все строки ОПИУ) и прочие расходы по статьям.',
                'properties' => ['currency' => self::CURRENCY, 'period' => self::PERIOD_FIN, 'from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE,
                    'group' => ['type' => 'string', 'enum' => ['week', 'month'], 'description' => 'Группировка (по умолчанию week)']],
            ],
            'finance_sku' => [
                'title' => 'Финансы: прибыль по товарам',
                'description' => 'Прибыль по каждому товару за период с ABC-классами по выручке и прибыли.',
                'properties' => ['currency' => self::CURRENCY, 'period' => self::PERIOD_FIN, 'from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE,
                    'sort' => ['type' => 'string', 'enum' => ['revenue', 'profit', 'sales_qty', 'margin'], 'description' => 'Сортировка (по умолчанию revenue)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'По умолчанию 100']],
            ],
            'finance_reports' => [
                'title' => 'Финансы: отчёты WB',
                'description' => 'Загруженные еженедельные отчёты реализации WB: суммы WB и наш расчёт «Итого к оплате» для сверки, состояние загрузки.',
                'properties' => ['store_id' => self::STORE, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'По умолчанию 30']],
            ],
            'finance_order' => [
                'title' => 'Финансы: заказ по Srid',
                'description' => 'Все строки отчётов реализации по одному заказу (Srid) и расчёт прибыли по нему.',
                'properties' => ['srid' => ['type' => 'string', 'description' => 'Srid заказа']],
                'required' => ['srid'],
            ],
            'finance_deductions' => [
                'title' => 'Финансы: удержания и реклама',
                'description' => 'Из чего состоят удержания WB в отчётах реализации (по видам, по неделям) и расходы на рекламу по способу оплаты — для сверки, что реклама не учтена дважды.',
                'properties' => ['period' => self::PERIOD_FIN, 'from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE],
            ],
            'orders_check' => [
                'title' => 'Сверка заказов с WB',
                'description' => 'Сверка по дням для сравнения с кабинетом WB: заказы (всего, из Ленты заказов, из Статистики, только из одного источника), суммы по цене продавца и по цене для покупателя (после СПП), выкупы/отмены/возвраты по дате события и по дате заказа, по схемам.',
                'properties' => ['from' => self::DATE, 'to' => self::DATE, 'store_id' => self::STORE],
            ],
            'wb_live_check' => [
                'title' => 'Живая сверка с WB',
                'description' => 'Запрашивает у WB (Статистика, токеном магазина) заказы и выкупы за день и сравнивает с данными сайта: количество, суммы по цене продавца и для покупателя, отмены, возвраты, заказы которых нет на сайте или в WB. Лимит WB — 1 запрос в минуту, вызывайте не чаще раза в 2 минуты.',
                'properties' => ['date' => self::DATE, 'store_id' => self::STORE],
            ],
            'currency_check' => [
                'title' => 'Проверка валют',
                'description' => 'В какой валюте WB отдаёт данные каждого магазина: валюта Ленты заказов (запрос к WB сейчас), валюты отчётов реализации, сравнение цены заказа из Ленты и из Статистики по одним и тем же заказам.',
                'properties' => ['store_id' => self::STORE],
            ],
            'sync_status' => [
                'title' => 'Состояние синхронизации',
                'description' => 'Проверка работы сервиса: последний запуск cron, по каждому магазину — когда обновлялся каждый источник WB, следующий запрос, ошибки и журнал последних запросов к WB.',
                'properties' => ['store_id' => self::STORE, 'log_limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Строк журнала на магазин (по умолчанию 15)']],
            ],
        ];
        if (($account['role'] ?? '') === 'admin') {
            $tools['admin_overview'] = [
                'title' => 'Админ: обзор сервиса',
                'description' => 'Только для администратора: клиенты и их тарифы/сроки, магазины всех клиентов с ошибками, платежи, cron.',
                'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Клиентов в списке (по умолчанию 100)']],
            ];
        }
        $out = [];
        foreach ($tools as $name => $t) {
            $schema = ['type' => 'object', 'properties' => (object) $t['properties']];
            if (!empty($t['required'])) {
                $schema['required'] = $t['required'];
            }
            $out[] = ['name' => $name, 'title' => $t['title'], 'description' => $t['description'], 'inputSchema' => $schema,
                'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false]];
        }
        return $out;
    }

    /** Выполнить инструмент. Исключение \InvalidArgumentException — ошибка в параметрах. */
    public static function call(string $name, array $args, array $account): array
    {
        $names = array_column(self::definitions($account), 'name');
        if (!in_array($name, $names, true)) {
            throw new \InvalidArgumentException("Неизвестный инструмент: $name. Доступны: " . implode(', ', $names));
        }
        $plan = Plans::forUser($account);
        $active = StoreService::activeIds((int) $account['id'], $plan);
        $storeIds = $active;
        if (!empty($args['store_id'])) {
            if (!in_array((int) $args['store_id'], $active, true)) {
                throw new \InvalidArgumentException('Магазин ' . (int) $args['store_id'] . ' не найден или на паузе. Список — в account_info.');
            }
            $storeIds = [(int) $args['store_id']];
        }
        Finance::$currency = !empty($args['currency']) ? strtoupper(substr((string) $args['currency'], 0, 8)) : null;
        $method = 'tool' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        return self::$method($args, $account, $plan, $storeIds);
    }

    // ------------------------------------------------------------------ кабинет и заказы

    private static function toolAccountInfo(array $a, array $account, array $plan, array $storeIds): array
    {
        $stores = [];
        foreach (StoreService::forUser((int) $account['id']) as $s) {
            $scopes = json_decode((string) $s['scopes'], true) ?: [];
            $stores[] = [
                'id' => (int) $s['id'], 'name' => $s['name'], 'seller' => $s['seller_name'], 'inn' => $s['seller_tin'], 'country' => $s['seller_country'], 'currency' => $s['currency'] ?? null,
                'active' => (int) $s['is_active'] === 1 && in_array((int) $s['id'], $storeIds, true),
                'token_scopes' => array_filter($scopes, static fn($v) => is_bool($v)), 'token_expires_at' => $s['token_expires_at'],
                'initial_load_done' => (bool) $s['initial_done'], 'last_error' => $s['last_error'],
                'orders_in_db' => (int) DB::value('SELECT COUNT(*) FROM orders WHERE store_id = ?', [$s['id']]),
            ];
        }
        return [
            'account' => ['id' => (int) $account['id'], 'login' => $account['email'], 'name' => $account['name'], 'role' => $account['role']],
            'plan' => ['code' => $plan['code'] ?? $account['plan_code'], 'name' => $plan['name'], 'expires_at' => $plan['expires_at'] ?? null,
                'history_days' => $plan['history_days'], 'sync_minutes' => $plan['sync_minutes'], 'profit' => (bool) $plan['profit'], 'finance' => Plans::finance($plan), 'stores_limit' => $plan['stores']],
            'profit_settings' => Profit::settings($account),
            'stores' => $stores,
            'server_time' => date('c'),
        ];
    }

    /** Фильтры ленты из параметров — та же логика, что у страницы «Лента заказов». */
    private static function orderFilters(array $a, array $account, array $plan, array $storeIds): array
    {
        $period = in_array($a['period'] ?? '', ['today', 'yesterday', '7d', '30d', 'custom'], true) ? $a['period'] : '7d';
        $saved = $_GET;
        $_GET = ['period' => $period, 'from' => $a['from'] ?? '', 'to' => $a['to'] ?? '', 'status' => $a['status'] ?? '',
            'scheme' => $a['scheme'] ?? '', 'q' => $a['q'] ?? '', 'store' => count($storeIds) === 1 ? $storeIds[0] : 0];
        try {
            [$ids, $f] = FeedController::filters($account, $plan);
        } finally {
            $_GET = $saved;
        }
        return [$ids, $f, FeedController::profitSettings($account, $plan)];
    }

    private static function toolOrdersSummary(array $a, array $account, array $plan, array $storeIds): array
    {
        [$ids, $f, $profit] = self::orderFilters($a, $account, $plan, $storeIds);
        return [
            'period' => ['from' => $f['from'], 'to' => $f['to'], 'history_days_in_plan' => $plan['history_days']],
            'kpi' => OrderRepository::kpi($ids, $f, $profit),
            'top_products' => OrderRepository::topProducts($ids, $f, 10),
            'chart' => OrderRepository::chart($ids, $f),
        ];
    }

    private static function toolListOrders(array $a, array $account, array $plan, array $storeIds): array
    {
        [$ids, $f, $profit] = self::orderFilters($a, $account, $plan, $storeIds);
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));
        $orders = OrderRepository::list($ids, $f, $limit, null, null, $profit);
        return ['period' => ['from' => $f['from'], 'to' => $f['to']], 'count' => count($orders), 'has_more' => count($orders) === $limit, 'orders' => $orders];
    }

    private static function toolProducts(array $a, array $account, array $plan, array $storeIds): array
    {
        if (!$storeIds) {
            return ['products' => []];
        }
        $in = implode(',', $storeIds);
        $limit = max(1, min(500, (int) ($a['limit'] ?? 100)));
        $where = "p.store_id IN ($in)";
        $params = ['since' => date('Y-m-d H:i:s', strtotime('today') - 29 * 86400)];
        if (($q = trim((string) ($a['q'] ?? ''))) !== '') {
            $where .= ' AND (p.vendor_code LIKE :q1 OR p.title LIKE :q2 OR p.brand LIKE :q3 OR p.subject LIKE :q4 OR p.nm_id = :qn)';
            $like = '%' . $q . '%';
            $params += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'qn' => ctype_digit($q) ? (int) $q : -1];
        }
        $rows = DB::all("SELECT p.store_id, p.nm_id, p.vendor_code, p.title, p.brand, p.subject, p.subject_id, p.cost_price, p.commission_pct,
                p.volume_l, p.volume_src, p.dims, p.stock_wb, p.stock_to_client, p.stock_from_client, p.stock_at, c.fbo comm_fbo, c.fbs comm_fbs, c.dbs comm_dbs,
                (SELECT COUNT(*) FROM orders o WHERE o.store_id = p.store_id AND o.nm_id = p.nm_id AND o.order_created_at >= :since) orders_30d,
                (SELECT ROUND(AVG(o.seller_price), 2) FROM orders o WHERE o.store_id = p.store_id AND o.nm_id = p.nm_id AND o.order_created_at >= :since2) avg_price_30d
            FROM products p LEFT JOIN wb_commissions c ON c.store_id = p.store_id AND c.subject_id = p.subject_id
            WHERE $where ORDER BY orders_30d DESC, p.nm_id DESC LIMIT $limit", $params + ['since2' => $params['since']]);
        return ['count' => count($rows), 'products' => array_map([self::class, 'typed'], $rows)];
    }

    // ------------------------------------------------------------------ финансы

    private static function finPeriod(array $a, array $storeIds): array
    {
        $from = (string) ($a['from'] ?? '');
        $to = (string) ($a['to'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return [$from, $to];
        }
        $p = (string) ($a['period'] ?? 'w4');
        $periods = Finance::availablePeriods($storeIds);
        $max = $periods['max'] ? date('Y-m-d', strtotime($periods['max']) + 6 * 86400) : date('Y-m-d');
        if (preg_match('/^\d{4}-\d{2}$/', $p)) {
            return [$p . '-01', date('Y-m-t', strtotime($p . '-01'))];
        }
        if (preg_match('/^week:(\d{4}-\d{2}-\d{2})$/', $p, $m)) {
            return [$m[1], date('Y-m-d', strtotime($m[1]) + 6 * 86400)];
        }
        if ($p === 'all') {
            return [$periods['min'] ?? date('Y-m-d', time() - 90 * 86400), $max];
        }
        $n = $p === 'w12' ? 12 : 4;
        return [$periods['weeks'][min($n, count($periods['weeks'])) - 1] ?? date('Y-m-d', time() - $n * 7 * 86400), $max];
    }

    private static function requireProfit(array $plan): void
    {
        if (!Plans::finance($plan)) {
            throw new \InvalidArgumentException('Раздел «Финансы» доступен на тарифах ' . Plans::namesWith('finance') . '.');
        }
    }

    private static function metrics(array $m): array
    {
        $out = [];
        foreach (array_merge(Finance::METRICS, ['margin', 'roi']) as $k) {
            if (array_key_exists($k, $m)) {
                $out[$k] = $m[$k] === null ? null : round((float) $m[$k], 2);
            }
        }
        return $out;
    }

    private static function toolFinanceSummary(array $a, array $account, array $plan, array $storeIds): array
    {
        self::requireProfit($plan);
        [$from, $to] = self::finPeriod($a, $storeIds);
        $res = Finance::compute($account, $storeIds, $from, $to, ['group' => 'week']);
        $weeks = [];
        foreach ($res['periods'] as $k => $p) {
            $weeks[] = ['week' => Finance::periodLabel($k, 'week'), 'date_from' => $k] + self::metrics($p);
        }
        return [
            'period' => ['from' => $from, 'to' => $to], 'report_rows' => $res['info']['rows'],
            'products_without_cost' => array_keys($res['info']['no_cost']),
            'labels' => Finance::LABELS, 'total' => self::metrics($res['total']), 'weeks' => $weeks,
        ];
    }

    private static function toolFinanceOpiu(array $a, array $account, array $plan, array $storeIds): array
    {
        self::requireProfit($plan);
        [$from, $to] = self::finPeriod($a, $storeIds);
        $group = ($a['group'] ?? 'week') === 'month' ? 'month' : 'week';
        $res = Finance::compute($account, $storeIds, $from, $to, ['group' => $group]);
        $cols = [];
        foreach ($res['periods'] as $k => $p) {
            $cols[] = ['period' => Finance::periodLabel($k, $group), 'key' => $k] + self::metrics($p);
        }
        $exp = DB::all('SELECT category, SUM(amount) amount FROM fin_expenses WHERE user_id = ? AND day BETWEEN ? AND ? GROUP BY category ORDER BY amount DESC',
            [(int) $account['id'], $from, $to]);
        return ['period' => ['from' => $from, 'to' => $to], 'group' => $group, 'labels' => Finance::LABELS, 'columns' => $cols,
            'total' => self::metrics($res['total']), 'expenses_by_category' => array_map([self::class, 'typed'], $exp)];
    }

    private static function toolFinanceSku(array $a, array $account, array $plan, array $storeIds): array
    {
        self::requireProfit($plan);
        [$from, $to] = self::finPeriod($a, $storeIds);
        $res = Finance::compute($account, $storeIds, $from, $to, ['group' => 'none', 'sku' => true]);
        $skus = $res['bySku'];
        Finance::abc($skus);
        $sort = in_array($a['sort'] ?? '', ['revenue', 'profit', 'sales_qty', 'margin'], true) ? $a['sort'] : 'revenue';
        uasort($skus, static fn($x, $y) => ($y[$sort] ?? -INF) <=> ($x[$sort] ?? -INF));
        $list = [];
        foreach (array_slice($skus, 0, max(1, min(500, (int) ($a['limit'] ?? 100))), true) as $nm => $s) {
            $list[] = ['nm_id' => $nm, 'vendor_code' => $s['vendor_code'], 'subject' => $s['subject'], 'brand' => $s['brand'],
                'abc_revenue' => $s['abc_rev'], 'abc_profit' => $s['abc_profit']] + self::metrics($s);
        }
        return ['period' => ['from' => $from, 'to' => $to], 'products_total' => count($skus), 'products' => $list,
            'not_allocated_profit' => round($res['unallocated']['profit'], 2), 'total' => self::metrics($res['total'])];
    }

    private static function toolFinanceReports(array $a, array $account, array $plan, array $storeIds): array
    {
        self::requireProfit($plan);
        if (!$storeIds) {
            return ['reports' => []];
        }
        $limit = max(1, min(200, (int) ($a['limit'] ?? 30)));
        $rows = DB::all('SELECT r.store_id, r.report_id, r.date_from, r.date_to, r.create_date, r.report_type, r.currency, r.retail_amount, r.for_pay, r.delivery,
                r.storage, r.acceptance, r.penalty, r.deduction, r.additional, r.bank_payment, r.rows_loaded, r.detail_done,
                (SELECT ROUND(SUM(CASE WHEN rr.doc_type = \'Продажа\' THEN rr.for_pay WHEN rr.doc_type = \'Возврат\' THEN -rr.for_pay ELSE 0 END)
                    - SUM(rr.delivery_service + rr.paid_storage + rr.paid_acceptance + rr.penalty + rr.deduction + rr.additional)
                    - SUM(CASE WHEN rr.doc_type = \'Возврат\' THEN -1 ELSE 1 END * (rr.cashback_amount + rr.cashback_comm - rr.cashback_discount)), 2)
                 FROM wb_report_rows rr WHERE rr.store_id = r.store_id AND rr.report_id = r.report_id) bank_payment_calc
            FROM wb_reports r WHERE r.store_id IN (' . implode(',', $storeIds) . ") ORDER BY r.date_from DESC, r.report_id DESC LIMIT $limit");
        foreach ($rows as &$r) {
            $r = self::typed($r);
            $r['source'] = $r['report_id'] > 0 ? 'wb_api' : 'xlsx_upload';
            $r['matches_wb'] = $r['detail_done'] && $r['bank_payment_calc'] !== null ? abs($r['bank_payment_calc'] - $r['bank_payment']) < 1 : null;
        }
        unset($r);
        return ['reports' => $rows];
    }

    private static function toolFinanceDeductions(array $a, array $account, array $plan, array $storeIds): array
    {
        self::requireProfit($plan);
        if (!$storeIds) {
            return ['deductions' => [], 'ads' => []];
        }
        $in = implode(',', $storeIds);
        [$from, $to] = self::finPeriod($a, $storeIds);
        $deductions = DB::all("SELECT r.date_from week, rr.oper, rr.bonus_type, COUNT(*) n, ROUND(SUM(rr.deduction), 2) deduction, ROUND(SUM(rr.additional), 2) additional
            FROM wb_report_rows rr JOIN wb_reports r ON r.store_id = rr.store_id AND r.report_id = rr.report_id
            WHERE rr.store_id IN ($in) AND r.date_from BETWEEN ? AND ? AND (rr.deduction <> 0 OR rr.additional <> 0)
            GROUP BY r.date_from, rr.oper, rr.bonus_type ORDER BY r.date_from DESC, deduction DESC", [$from, $to]);
        $ads = DB::all("SELECT DATE_SUB(day, INTERVAL WEEKDAY(day) DAY) week, payment_type, COUNT(*) n, ROUND(SUM(amount), 2) amount
            FROM wb_ad_costs WHERE store_id IN ($in) AND day BETWEEN ? AND ? GROUP BY week, payment_type ORDER BY week DESC, amount DESC", [$from, $to]);
        return ['period' => ['from' => $from, 'to' => $to], 'deductions' => array_map([self::class, 'typed'], $deductions),
            'ads' => array_map([self::class, 'typed'], $ads),
            'note' => 'Удержания с видом «продвижение/реклама» — оплата рекламы из выручки; в прибыли они не вычитаются повторно (метрика ads_deducted), реклама считается по истории затрат.'];
    }

    private static function toolFinanceOrder(array $a, array $account, array $plan, array $storeIds): array
    {
        self::requireProfit($plan);
        $srid = trim((string) ($a['srid'] ?? ''));
        if ($srid === '' || !$storeIds) {
            throw new \InvalidArgumentException('Укажите srid заказа.');
        }
        $rows = DB::all('SELECT * FROM wb_report_rows WHERE store_id IN (' . implode(',', $storeIds) . ') AND srid = ? ORDER BY rrd_id', [$srid]);
        $res = Finance::compute($account, $storeIds, FinanceSync::WB_DATA_START, date('Y-m-d'), ['group' => 'none', 'srid' => true, 'srids' => [$srid]]);
        $o = $res['bySrid'][$srid] ?? null;
        return ['srid' => $srid, 'found' => (bool) $rows,
            'calc' => $o ? self::metrics($o) : null, 'rows' => array_map([self::class, 'typed'], $rows)];
    }

    // ------------------------------------------------------------------ проверка работы

    private static function toolCurrencyCheck(array $a, array $account, array $plan, array $storeIds): array
    {
        $out = ['stores' => []];
        foreach ($storeIds as $sid) {
            $store = DB::one('SELECT * FROM stores WHERE id = ?', [$sid]);
            $res = ['store_id' => $sid, 'name' => $store['name'], 'seller' => $store['seller_name'], 'stored_currency' => $store['currency'] ?? null,
                'reports_by_currency' => array_map([self::class, 'typed'], DB::all('SELECT currency, report_type, COUNT(*) reports, MIN(date_from) since, MAX(date_to) till,
                    ROUND(SUM(retail_amount), 2) retail_amount, ROUND(SUM(for_pay), 2) for_pay, ROUND(SUM(bank_payment), 2) bank_payment
                    FROM wb_reports WHERE store_id = ? GROUP BY currency, report_type', [$sid]))];
            try {
                $wb = new \App\Wb\WbClient(\App\Core\Crypto::decrypt($store['token_enc']));
                $data = $wb->orderFeed(date('c', time() - 6 * 3600), 0, 30);
                $res['feed_currency_now'] = $data['currency'] ?? null;
                $sample = [];
                foreach (array_slice((array) ($data['orders'] ?? []), 0, 30) as $o) {
                    $db = DB::one('SELECT seller_price, finished_price, total_price, spp, in_feed FROM orders WHERE store_id = ? AND srid = ?', [$sid, (string) $o['srid']]);
                    $sample[] = ['srid' => $o['srid'], 'created' => $o['createdAt'] ?? null, 'feed_seller_price' => $o['sellerPrice'] ?? null,
                        'site_seller_price' => $db ? (float) $db['seller_price'] : null, 'stats_buyer_price' => $db && $db['finished_price'] !== null ? (float) $db['finished_price'] : null,
                        'stats_total_price' => $db && $db['total_price'] !== null ? (float) $db['total_price'] : null,
                        'ratio_feed_to_site' => $db && (float) $db['seller_price'] > 0 && isset($o['sellerPrice']) ? round((float) $o['sellerPrice'] / (float) $db['seller_price'], 4) : null];
                }
                $res['feed_vs_site_sample'] = $sample;
                $ratios = array_filter(array_column($sample, 'ratio_feed_to_site'));
                $res['ratio_feed_to_site_avg'] = $ratios ? round(array_sum($ratios) / count($ratios), 4) : null;
            } catch (\App\Wb\WbException $e) {
                $res['feed_error'] = $e->status . ' ' . $e->humanMessage() . ($e->isRateLimit() ? ' — повторите через 1–2 минуты' : '');
            }
            $out['stores'][] = $res;
        }
        return $out;
    }

    private static function toolWbLiveCheck(array $a, array $account, array $plan, array $storeIds): array
    {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['date'] ?? '')) ? $a['date'] : date('Y-m-d', time() - 86400);
        $out = ['date' => $date, 'timezone' => date_default_timezone_get(), 'stores' => []];
        foreach ($storeIds as $sid) {
            $store = DB::one('SELECT * FROM stores WHERE id = ?', [$sid]);
            $res = ['store_id' => $sid, 'name' => $store['name']];
            $wb = new \App\Wb\WbClient(\App\Core\Crypto::decrypt($store['token_enc']));
            $day = static fn($v) => substr((string) wb_time((string) $v), 0, 10) === $date;
            try {
                $orders = array_values(array_filter($wb->supplierOrdersDay($date), static fn($o) => $day($o['date'] ?? '')));
                $res['wb_orders'] = [
                    'count' => count($orders),
                    'sum_seller_price' => round(array_sum(array_map(static fn($o) => (float) ($o['priceWithDisc'] ?? 0), $orders)), 2),
                    'sum_buyer_price' => round(array_sum(array_map(static fn($o) => (float) ($o['finishedPrice'] ?? 0), $orders)), 2),
                    'cancelled_so_far' => count(array_filter($orders, static fn($o) => !empty($o['isCancel']))),
                    'fbo' => count(array_filter($orders, static fn($o) => ($o['warehouseType'] ?? '') !== 'Склад продавца')),
                ];
                $wbSrids = array_column($orders, 'srid');
            } catch (\App\Wb\WbException $e) {
                $res['wb_orders'] = ['error' => $e->status . ' ' . $e->humanMessage()];
                $wbSrids = null;
            }
            usleep(1500000);
            try {
                $sales = array_values(array_filter($wb->supplierSalesDay($date), static fn($s) => $day($s['date'] ?? '')));
                $buy = array_values(array_filter($sales, static fn($s) => str_starts_with((string) ($s['saleID'] ?? ''), 'S')));
                $ret = array_values(array_filter($sales, static fn($s) => str_starts_with((string) ($s['saleID'] ?? ''), 'R')));
                $res['wb_buyouts'] = [
                    'count' => count($buy),
                    'sum_seller_price' => round(array_sum(array_map(static fn($s) => (float) ($s['priceWithDisc'] ?? 0), $buy)), 2),
                    'sum_buyer_price' => round(array_sum(array_map(static fn($s) => (float) ($s['finishedPrice'] ?? 0), $buy)), 2),
                    'for_pay' => round(array_sum(array_map(static fn($s) => (float) ($s['forPay'] ?? 0), $buy)), 2),
                    'returns' => count($ret),
                ];
                $buySrids = array_column($buy, 'srid');
            } catch (\App\Wb\WbException $e) {
                $res['wb_buyouts'] = ['error' => $e->status . ' ' . $e->humanMessage() . ($e->isRateLimit() ? ' — повторите через 1–2 минуты' : '')];
                $buySrids = null;
            }
            // Данные сайта за тот же день
            $range = [$sid, $date . ' 00:00:00', $date . ' 23:59:59'];
            $site = DB::one("SELECT COUNT(*) count, ROUND(SUM(seller_price), 2) sum_seller_price, ROUND(SUM(finished_price), 2) sum_buyer_price
                FROM orders WHERE store_id = ? AND order_created_at BETWEEN ? AND ?", $range);
            $siteBuy = DB::one("SELECT COUNT(*) count, ROUND(SUM(seller_price), 2) sum_seller_price, ROUND(SUM(finished_price), 2) sum_buyer_price
                FROM orders WHERE store_id = ? AND status = 'buyout' AND COALESCE(status_at, order_created_at) BETWEEN ? AND ?", $range);
            $res['site_orders'] = self::typed($site);
            $res['site_buyouts'] = self::typed($siteBuy);
            if ($wbSrids !== null) {
                $siteSrids = array_column(DB::all('SELECT srid FROM orders WHERE store_id = ? AND order_created_at BETWEEN ? AND ?', $range), 'srid');
                $res['orders_missing_on_site'] = array_slice(array_values(array_diff($wbSrids, $siteSrids)), 0, 20);
                $res['orders_not_in_wb_stats'] = array_slice(array_values(array_diff($siteSrids, $wbSrids)), 0, 20);
                $res['orders_missing_on_site_count'] = count(array_diff($wbSrids, $siteSrids));
                $res['orders_not_in_wb_stats_count'] = count(array_diff($siteSrids, $wbSrids));
            }
            if ($buySrids !== null) {
                $siteBuySrids = array_column(DB::all("SELECT srid FROM orders WHERE store_id = ? AND status = 'buyout' AND COALESCE(status_at, order_created_at) BETWEEN ? AND ?", $range), 'srid');
                $missing = array_values(array_diff($buySrids, $siteBuySrids));
                $res['buyouts_missing_on_site_count'] = count($missing);
                $res['buyouts_missing_on_site'] = [];
                foreach (array_slice($missing, 0, 20) as $sr) {
                    $o = DB::one('SELECT srid, status, order_created_at, status_at FROM orders WHERE store_id = ? AND srid = ?', [$sid, $sr]);
                    $res['buyouts_missing_on_site'][] = $o ? self::typed($o) : ['srid' => $sr, 'status' => 'нет на сайте'];
                }
                $res['buyouts_on_site_not_in_wb_count'] = count(array_diff($siteBuySrids, $buySrids));
            }
            $out['stores'][] = $res;
        }
        return $out;
    }

    private static function toolOrdersCheck(array $a, array $account, array $plan, array $storeIds): array
    {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['from'] ?? '')) ? $a['from'] : date('Y-m-d', time() - 86400);
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['to'] ?? '')) ? $a['to'] : date('Y-m-d');
        if (!$storeIds) {
            return ['days' => []];
        }
        $in = implode(',', $storeIds);
        $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
        // Источник: Лента заказов заполняет регион склада/город/chrt, Статистика — категорию и цену для покупателя
        $feed = '(o.warehouse_region IS NOT NULL OR o.dest_city IS NOT NULL OR o.chrt_id IS NOT NULL)';
        $stats = '(o.category IS NOT NULL OR o.finished_price IS NOT NULL)';
        $byOrder = DB::all("SELECT DATE(o.order_created_at) day, COUNT(*) orders, SUM($feed) in_feed, SUM($stats) in_stats,
                SUM($stats AND NOT $feed) stats_only, SUM($feed AND NOT $stats) feed_only,
                ROUND(SUM(o.seller_price), 2) sum_seller_price, ROUND(SUM(o.finished_price), 2) sum_buyer_price,
                SUM(o.status = 'buyout') buyouts_of_these_orders, SUM(o.status = 'cancel') cancels_of_these_orders,
                SUM(o.is_mp = 0) fbo, SUM(o.is_mp = 1) seller_warehouse
            FROM orders o WHERE o.store_id IN ($in) AND o.order_created_at BETWEEN ? AND ? GROUP BY day ORDER BY day", $range);
        $byStatus = DB::all("SELECT DATE(COALESCE(o.status_at, o.order_created_at)) day,
                SUM(o.status = 'buyout') buyouts, ROUND(SUM(CASE WHEN o.status = 'buyout' THEN o.seller_price END), 2) buyouts_sum_seller,
                ROUND(SUM(CASE WHEN o.status = 'buyout' THEN o.finished_price END), 2) buyouts_sum_buyer,
                SUM(o.status = 'cancel') cancels, SUM(o.status IN ('return','returnDefective')) returns
            FROM orders o WHERE o.store_id IN ($in) AND o.status <> 'created' AND COALESCE(o.status_at, o.order_created_at) BETWEEN ? AND ?
            GROUP BY day ORDER BY day", $range);
        $days = [];
        foreach ($byOrder as $r) {
            $days[$r['day']] = ['date' => $r['day']] + self::typed($r);
            unset($days[$r['day']]['day']);
        }
        foreach ($byStatus as $r) {
            $days[$r['day']] = ($days[$r['day']] ?? ['date' => $r['day']]) + self::typed($r);
            unset($days[$r['day']]['day']);
        }
        ksort($days);
        return [
            'timezone' => date_default_timezone_get(),
            'notes' => [
                'orders' => 'Заказы по дате оформления (как «Заказали, шт» в WB), включая позже отменённые',
                'buyouts' => 'Выкупы/отмены/возвраты — по дате события (как «Выкупили» в WB)',
                'sum_seller_price' => 'Сумма по цене продавца со скидкой продавца (до СПП): из Статистики WB, а если заказа там нет — из Ленты заказов',
                'sum_buyer_price' => 'Сумма по цене для покупателя после СПП (из Статистики WB)',
                'stats_only' => 'Заказы, которых нет в Ленте заказов (пришли только из Статистики)',
            ],
            'days' => array_values($days),
        ];
    }

    private static function toolSyncStatus(array $a, array $account, array $plan, array $storeIds): array
    {
        $limit = max(1, min(100, (int) ($a['log_limit'] ?? 15)));
        $stores = [];
        foreach (StoreService::forUser((int) $account['id']) as $s) {
            if (!empty($a['store_id']) && (int) $s['id'] !== (int) $a['store_id']) {
                continue;
            }
            $stores[] = self::storeStatus($s, $limit);
        }
        $cron = DB::setting('cron_last_run');
        return [
            'cron_last_run' => $cron, 'cron_ok' => $cron !== null && strtotime($cron) > time() - 600,
            'plan_sync_minutes' => $plan['sync_minutes'], 'server_time' => date('Y-m-d H:i:s'),
            'stores' => $stores,
        ];
    }

    private static function storeStatus(array $s, int $logLimit): array
    {
        $scopes = json_decode((string) $s['scopes'], true) ?: [];
        $src = ['feed' => 'feed', 'marketplace' => 'mp', 'statistics' => 'stats', 'content' => 'cards', 'tariffs' => 'tariffs', 'remains' => 'remains',
            'finance' => 'fin', 'storage' => 'storage', 'ads' => 'ads'];
        $sources = [];
        foreach ($src as $label => $prefix) {
            $sources[$label] = ['last_ok' => $s[$prefix . '_ok_at'] ?? null, 'next' => $s[$prefix . '_next_at'] ?? null];
        }
        $fin = DB::one('SELECT COUNT(*) reports, COALESCE(SUM(detail_done), 0) loaded, COALESCE(SUM(rows_loaded), 0) row_count, MIN(date_from) since, MAX(date_to) till
            FROM wb_reports WHERE store_id = ?', [$s['id']]);
        return [
            'id' => (int) $s['id'], 'name' => $s['name'], 'active' => (bool) $s['is_active'], 'initial_load_done' => (bool) $s['initial_done'],
            'token_scopes' => $scopes, 'token_expires_at' => $s['token_expires_at'],
            'last_error' => $s['last_error'], 'last_error_at' => $s['last_error_at'], 'sources' => $sources,
            'feed_state' => json_decode((string) ($s['feed_state'] ?? ''), true),
            'orders_in_db' => (int) DB::value('SELECT COUNT(*) FROM orders WHERE store_id = ?', [$s['id']]),
            'orders_last_24h' => (int) DB::value('SELECT COUNT(*) FROM orders WHERE store_id = ? AND order_created_at >= ?', [$s['id'], date('Y-m-d H:i:s', time() - 86400)]),
            'finance_reports' => self::typed($fin),
            'recent_log' => array_map([self::class, 'typed'], DB::all('SELECT created_at, source, ok, rows_count, message FROM sync_log WHERE store_id = ? ORDER BY id DESC LIMIT ' . $logLimit, [$s['id']])),
        ];
    }

    private static function toolAdminOverview(array $a, array $account, array $plan, array $storeIds): array
    {
        if (($account['role'] ?? '') !== 'admin') {
            throw new \InvalidArgumentException('Только для администратора.');
        }
        $limit = max(1, min(500, (int) ($a['limit'] ?? 100)));
        $clients = DB::all("SELECT u.id, u.email login, u.name, u.role, u.is_blocked, u.plan_code, u.plan_expires_at, u.created_at, u.last_login_at, u.note,
                (SELECT COUNT(*) FROM stores s WHERE s.user_id = u.id) stores, (SELECT COUNT(*) FROM users m WHERE m.owner_id = u.id) team
            FROM users u WHERE u.owner_id IS NULL ORDER BY u.created_at DESC LIMIT $limit");
        $errors = DB::all('SELECT s.id, s.user_id, u.email owner, s.name, s.last_error, s.last_error_at FROM stores s JOIN users u ON u.id = s.user_id
            WHERE s.last_error IS NOT NULL ORDER BY s.last_error_at DESC LIMIT 50');
        $cron = DB::setting('cron_last_run');
        $tg = ['configured' => Telegram::enabled(), 'username' => (string) config('telegram.bot_username', '')];
        if ($tg['configured']) {
            $me = Telegram::api('getMe');
            $tg['token_ok'] = $me !== null;
            $tg['error'] = $me === null ? Telegram::$lastError : null;
            $hook = Telegram::api('getWebhookInfo');
            $tg['webhook_url'] = $hook['result']['url'] ?? null;
            $tg['pending_updates'] = $hook['result']['pending_update_count'] ?? null;
        }
        $tg['linked_users'] = (int) DB::value('SELECT COUNT(*) FROM users WHERE tg_chat_id IS NOT NULL');
        $max = ['configured' => Max::enabled(), 'username' => (string) config('max.bot_username', '')];
        if ($max['configured']) {
            $me = Max::api('GET', '/me');
            $max['token_ok'] = $me !== null;
            $max['error'] = $me === null ? Max::$lastError : null;
        }
        $max['linked_users'] = (int) DB::value('SELECT COUNT(*) FROM users WHERE max_user_id IS NOT NULL');
        $max['recent_errors'] = self::logTail(BASE_PATH . '/storage/logs/max.log', 5);
        $tg['updates_offset'] = DB::setting('tg_offset');
        $logFile = BASE_PATH . '/storage/logs/telegram.log';
        $tg['recent_errors'] = is_file($logFile) ? array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -5) : [];
        return [
            'version' => app_version(),
            'cron_last_run' => $cron, 'cron_ok' => $cron !== null && strtotime($cron) > time() - 600,
            'telegram' => $tg,
            'max' => $max,
            'counts' => self::typed(DB::one('SELECT (SELECT COUNT(*) FROM users WHERE owner_id IS NULL) clients, (SELECT COUNT(*) FROM users WHERE owner_id IS NULL AND plan_code <> \'free\' AND plan_expires_at > NOW()) paid,
                (SELECT COUNT(*) FROM stores) stores, (SELECT COUNT(*) FROM orders) orders, (SELECT COUNT(*) FROM wb_reports) finance_reports')),
            'payments_30d' => self::typed(DB::one("SELECT COUNT(*) count, COALESCE(SUM(amount), 0) amount FROM payments WHERE status = 'succeeded' AND created_at >= ?", [date('Y-m-d H:i:s', time() - 30 * 86400)])),
            'clients' => array_map([self::class, 'typed'], $clients),
            'store_errors' => array_map([self::class, 'typed'], $errors),
            // Магазины всех клиентов: отчёты из WB по API и загруженные файлом — чтобы видеть, почему у клиента пустые финансы
            'stores' => array_map(static function (array $s): array {
                $s = self::typed($s);
                $s['scopes'] = json_decode((string) $s['scopes'], true) ?: [];
                $s['fin_state'] = json_decode((string) $s['fin_state'], true) ?: [];
                $s['recent_errors'] = array_map([self::class, 'typed'], DB::all('SELECT created_at, source, message FROM sync_log WHERE store_id = ? AND ok = 0 ORDER BY id DESC LIMIT 3', [$s['id']]));
                return $s;
            }, DB::all("SELECT s.id, s.user_id, u.email owner, s.name, s.currency, s.is_active, s.created_at, s.scopes,
                    (SELECT COUNT(*) FROM wb_reports r WHERE r.store_id = s.id AND r.report_id > 0) reports_api,
                    (SELECT COUNT(*) FROM wb_reports r WHERE r.store_id = s.id AND r.report_id > 0 AND r.detail_done = 1) reports_api_loaded,
                    s.fin_ok_at, s.fin_next_at, s.fin_state,
                    (SELECT COUNT(*) FROM wb_reports r WHERE r.store_id = s.id AND r.report_id < 0) reports_uploaded,
                    (SELECT MAX(r.loaded_at) FROM wb_reports r WHERE r.store_id = s.id AND r.report_id < 0) last_upload,
                    (SELECT COALESCE(SUM(r.rows_loaded), 0) FROM wb_reports r WHERE r.store_id = s.id) report_rows,
                    (SELECT COUNT(*) FROM products p WHERE p.store_id = s.id) products
                FROM stores s JOIN users u ON u.id = s.user_id ORDER BY s.id DESC LIMIT 100")),
            'php_errors' => self::logTail(BASE_PATH . '/storage/logs/php-error.log', 20),
            'sync_log' => self::logTail(BASE_PATH . '/storage/logs/sync.log', 10),
            'limits' => ['memory_limit' => ini_get('memory_limit'), 'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
                'max_execution_time' => ini_get('max_execution_time'), 'php' => PHP_VERSION, 'zip' => class_exists(\ZipArchive::class), 'xmlreader' => class_exists(\XMLReader::class)],
        ];
    }

    /** Последние строки лога (читаем только хвост файла). */
    private static function logTail(string $file, int $lines): array
    {
        if (!is_file($file) || !($fh = fopen($file, 'rb'))) {
            return [];
        }
        $size = filesize($file);
        fseek($fh, max(0, $size - 64 * 1024));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);
        $out = array_values(array_filter(explode("\n", $tail), static fn($l) => trim($l) !== ''));
        if ($size > 64 * 1024) {
            array_shift($out);
        }
        return array_map(static fn($l) => mb_substr($l, 0, 600), array_slice($out, -$lines));
    }

    /** Числа из БД (строки) — в числа, чтобы JSON был удобен для разбора. */
    private static function typed(?array $row): array
    {
        if (!$row) {
            return [];
        }
        foreach ($row as $k => $v) {
            if (is_string($v) && is_numeric($v) && !preg_match('/^0\d/', $v) && !in_array($k, ['srid', 'barcode', 'login', 'inn', 'vendor_code', 'ts_name'], true)) {
                $row[$k] = str_contains($v, '.') || stripos($v, 'e') !== false ? (float) $v : (int) $v;
            }
        }
        return $row;
    }
}
