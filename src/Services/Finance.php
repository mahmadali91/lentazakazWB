<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Расчёт чистой прибыли по детализации отчётов реализации WB — та же логика, что в таблице «9.5»
 * (лист «Исходник»: вычисляемые столбцы «Продажи», «Возврат покупателя», «Комиссия WB с учётом возврата»,
 * «Налоговая база», «Себестоимость» и т. д., дальше сводные «промежуточный отчёт», «Отчёт реализации свод», «ОПИУ»).
 *
 * Строка отчёта → показатели:
 *  sales      Продажи: цена со скидкой продавца (retailPriceWithDisc) для продаж/сторно возвратов,
 *             «К перечислению» (forPay) для компенсаций WB (брак, потеря, подмена, коррекции)
 *  returns    Возврат покупателя: то же для документов «Возврат»
 *  commission Комиссия WB = ±(цена со скидкой − к перечислению − эквайринг), для компенсаций 0
 *  acquiring  Эквайринг (±)
 *  logistics  Услуги по доставке (deliveryService)
 *  loyalty    Баллы и программа лояльности (± по типу документа)
 *  storage, acceptance, penalty, deduction, additional — хранение, приёмка, штрафы, удержания, доплаты
 *  tax_base   Налоговая база: «Вайлдберриз реализовал товар» (retailAmount) для продаж (−для возвратов),
 *             к перечислению — для компенсаций брака/потерь
 *  tax, vat   Налог = (база − НДС) × ставка; НДС = база / (1 + ставка НДС) × ставка НДС
 *  cost       Себестоимость: + за продажу и компенсацию, − за возврат
 *
 * Итоги: Выручка = Продажи − Возвраты; Оборот (придёт от WB) = Выручка − Комиссия − Эквайринг − Логистика − Лояльность;
 * Чистая прибыль = Оборот − Хранение и прочие удержания WB − Себестоимость − Налог − НДС − Реклама − Прочие расходы − Самовыкупы.
 */
final class Finance
{
    private const COMPENSATION = ['коррекция продаж', 'компенсация ущерба', 'добровольная компенсация при возврате', 'компенсация подмененного товара',
        'перевыставление расходов по логистике', 'возмещение издержек по перевозке', 'компенсация потерянного товара', 'оплата потерянного товара',
        'оплата по итогам инвентаризации', 'компенсация брака', 'частичная компенсация брака'];
    private const SALE_OPS = ['продажа', 'корректная продажа', 'сторно возвратов', 'коррекция возвратов'];
    private const RETURN_OPS = ['коррекция возвратов', 'сторно продажи', 'сторно продаж', 'корректный возврат', 'возврат'];
    private const NO_COMMISSION = ['компенсация подмененного товара', 'компенсация потерянного товара', 'добровольная компенсация при возврате',
        'коррекция продаж', 'компенсация ущерба', 'компенсация брака', 'частичная компенсация брака', 'оплата брака'];
    private const DEFECT = ['частичная компенсация брака', 'оплата брака', 'компенсация брака', 'добровольная компенсация при возврате'];
    private const LOST = ['компенсация потерянного товара', 'компенсация подмененного товара', 'компенсация ущерба'];
    private const SALE_QTY_PLUS = ['продажа', 'корректная продажа', 'сторно возвратов'];
    private const SALE_QTY_MINUS = ['сторно продажи', 'возврат', 'сторно продаж', 'корректный возврат'];

    /** Показатели (порядок — как в ОПИУ). */
    public const METRICS = ['sales', 'returns', 'revenue', 'sales_qty', 'deliveries', 'back_deliveries', 'returns_qty', 'defect_qty', 'lost_qty',
        'defect_comp', 'lost_comp', 'spp_comp', 'commission', 'acquiring', 'logistics', 'loyalty', 'turnover', 'storage', 'acceptance', 'penalty', 'deduction',
        'additional', 'ads_deducted', 'wb_other', 'cost', 'tax_base', 'tax', 'vat', 'ads', 'exp_sku', 'exp_period', 'exp_fixed', 'pay_calc', 'profit'];

    public const LABELS = [
        'sales'           => 'Продажи',
        'returns'         => 'Возвраты покупателей',
        'revenue'         => 'Выручка (продажи − возвраты)',
        'sales_qty'       => 'Кол-во продаж',
        'deliveries'      => 'Кол-во доставок (заказов)',
        'back_deliveries' => 'Обратная доставка с ПВЗ',
        'returns_qty'     => 'Кол-во возвратов',
        'defect_qty'      => 'Брак, компенсированный WB',
        'lost_qty'        => 'Потерянный товар, компенсированный WB',
        'defect_comp'     => 'Компенсация брака от WB',
        'lost_comp'       => 'Компенсация потерянного товара от WB',
        'spp_comp'        => 'Компенсация скидки WB (СПП)',
        'commission'      => 'Комиссия WB',
        'acquiring'       => 'Эквайринг',
        'logistics'       => 'Логистика',
        'loyalty'         => 'Программа лояльности (баллы)',
        'turnover'        => 'Оборот: придёт от WB за товар',
        'storage'         => 'Хранение',
        'acceptance'      => 'Платная приёмка',
        'penalty'         => 'Штрафы',
        'deduction'       => 'Удержания',
        'additional'      => 'Доплаты (корректировка ВВ)',
        'ads_deducted'    => 'Из удержаний — оплата рекламы (учтена в «Реклама»)',
        'wb_other'        => 'Хранение и прочие удержания WB',
        'cost'            => 'Себестоимость',
        'tax_base'        => 'Налоговая база',
        'tax'             => 'Налог',
        'vat'             => 'НДС',
        'ads'             => 'Реклама',
        'exp_sku'         => 'Доп. затраты на товары',
        'exp_period'      => 'Прочие расходы за период',
        'exp_fixed'       => 'Фиксированные расходы магазинов',
        'pay_calc'        => 'Итого к оплате (расчёт по отчёту)',
        'profit'          => 'Чистая прибыль',
    ];

    /** Статьи прочих расходов (как в листе «Прочие расходы»). */
    public const EXPENSE_CATEGORIES = ['Фулфилмент', 'Упаковка и маркировка', 'Доставка до склада WB', 'Самовыкупы и раздачи', 'Внешняя реклама',
        'Фото и контент', 'Зарплата', 'Аренда', 'Сервисы и подписки', 'Банк и комиссии', 'Сертификация', 'Прочее'];

    /** Валюта отчётов для расчёта (если у магазинов отчёты в разных валютах). null — все отчёты. */
    public static ?string $currency = null;
    /** Пересчитать суммы в рубли по курсу ЦБ (настройка кабинета «Показывать в рублях»). */
    public static bool $toRub = false;
    /** Курс ЦБ был недоступен — показаны суммы без пересчёта. */
    public static bool $fxFailed = false;
    /** Валюта, в которой учитываются общие «Прочие расходы» без магазина при расчёте по валютам. */
    private static ?string $nullExpCurrency = null;
    /** Учитывать общие расходы кабинета без магазина (false — для клиента менеджера с доступом к отдельным магазинам). */
    public static bool $commonExpenses = true;
    private const QTY = ['sales_qty', 'deliveries', 'back_deliveries', 'returns_qty', 'defect_qty', 'lost_qty'];

    public static function empty(): array
    {
        return array_fill_keys(self::METRICS, 0.0);
    }

    private static function norm(?string $s): string
    {
        return mb_strtolower(trim(str_replace("\u{A0}", ' ', (string) $s)));
    }

    /**
     * Показатели одной строки детализации.
     * @param float $cost себестоимость единицы товара, $dup — сколько раз ШК повторяется в компенсациях брака
     */
    public static function row(array $r, float $cost, int $dup, float $taxRate, float $vatRate, bool $adsLoaded = false): array
    {
        $doc = self::norm($r['doc_type']);
        $op = self::norm($r['oper']);
        $bonus = self::norm($r['bonus_type']);
        $sign = $doc === 'продажа' ? 1 : ($doc === 'возврат' ? -1 : 0);
        $rpwd = (float) $r['retail_price_disc'];
        $forPay = (float) $r['for_pay'];
        $acq = (float) $r['acquiring_fee'];
        $retail = (float) $r['retail_amount'];
        $dup = max(1, $dup);
        $m = [];

        // Продажи (CR) и возвраты (CP) — по цене продажи WB (retail_amount), как графа «Продажа» в отчётах WB.
        // Разница с ценой продавца (retail_price_withdisc) — скидка WB покупателю (СПП), её WB компенсирует продавцу.
        // $p — цена товара, $c — сумма компенсации (компенсации WB тоже входят в «Продажу» по retail_amount).
        $isComp = in_array($op, self::COMPENSATION, true);
        $sale = static fn(float $p, float $c): float => $doc === 'возврат' ? 0.0 : ($isComp ? $c : (in_array($op, self::SALE_OPS, true) ? $p : 0.0));
        $ret = static fn(float $p, float $c): float => $doc === 'возврат'
            ? ($isComp ? $c : (in_array($op, self::RETURN_OPS, true) ? $p : 0.0))
            : ($op === 'возврат' ? $p : 0.0);
        $sellerSale = $sale($rpwd, $forPay);
        $sellerReturn = $ret($rpwd, $forPay);
        $isSale = $sellerSale > 0;
        $isReturn = $sellerReturn > 0;
        $m['sales'] = $sale($retail, $retail);
        $m['returns'] = $ret($retail, $retail);
        $m['revenue'] = $m['sales'] - $m['returns'];
        $m['spp_comp'] = $sellerSale - $sellerReturn - $m['revenue'];

        // Комиссия WB (CU) и эквайринг — от цены продавца, со знаком документа:
        // Выручка + Компенсация СПП − Комиссия − Эквайринг = к перечислению
        $m['commission'] = in_array($op, self::NO_COMMISSION, true) ? 0.0 : $sign * ($rpwd - $forPay - $acq);
        $m['acquiring'] = ($sign ?: 1) * $acq;
        $m['logistics'] = (float) $r['delivery_service'];
        // Программа лояльности: удержанные баллы и стоимость участия. «Компенсацию скидки по программе лояльности»
        // WB в «Итого к оплате» не включает (bank_payment отчёта сходится без неё) — в расчёт не берём.
        $m['loyalty'] = ($doc === 'возврат' ? -1 : 1) * ((float) $r['cashback_amount'] + (float) $r['cashback_comm']);

        // Количества (DD, CV, DO, DF, CX, CY)
        // Строка может быть суммой однотипных строк отчёта (_n — сколько их, _qty — сумма max(1, |quantity|))
        $n = (int) ($r['_n'] ?? 1);
        $m['sales_qty'] = $n * (($doc === 'возврат' && in_array($op, self::SALE_QTY_MINUS, true)) ? -1 : (in_array($op, self::SALE_QTY_PLUS, true) ? 1 : 0));
        $m['deliveries'] = in_array($bonus, ['к клиенту при продаже', 'к клиенту при отмене'], true) ? (int) $r['delivery_amount'] : 0;
        $m['back_deliveries'] = in_array($bonus, ['от клиента при отмене', 'от клиента при возврате'], true) ? (int) $r['return_amount'] : 0;
        $m['returns_qty'] = ($doc === 'возврат' && $op === 'возврат') ? $n : 0;
        $m['defect_qty'] = in_array($op, self::DEFECT, true) ? $sign / $dup : 0.0;
        $m['lost_qty'] = in_array($op, self::LOST, true) ? (float) $sign : 0.0;
        $m['defect_comp'] = $m['defect_qty'] > 0 ? $sellerSale : 0.0;
        $m['lost_comp'] = $m['lost_qty'] > 0 ? $sellerSale : 0.0;

        // Удержания WB (DC)
        $m['storage'] = (float) $r['paid_storage'];
        $m['acceptance'] = (float) $r['paid_acceptance'];
        $m['penalty'] = (float) $r['penalty'];
        $m['deduction'] = (float) $r['deduction'];
        $m['additional'] = (float) $r['additional'];
        // Удержание «Оказание услуг WB Продвижение» — оплата рекламы деньгами (взаиморасчёт с баланса, пополнение счёта
        // продвижения из выплат). Это расход на рекламу в неделю отчёта: переносим его из удержаний в «Рекламу».
        $m['ads_deducted'] = $m['deduction'] != 0.0 && preg_match('/продвиж|реклам/u', $bonus) ? $m['deduction'] : 0.0;
        $m['ads'] = $m['ads_deducted'];

        // Налоговая база (DT), НДС (EA), налог (DB)
        $comp = $m['defect_qty'] + $m['lost_qty'];
        $base = $isSale ? $retail : ($isReturn ? -$retail : ($comp > 0 ? $forPay : ($comp < 0 ? -$forPay : 0.0)));
        $m['tax_base'] = $base;
        $m['vat'] = $vatRate > 0 ? $base / (1 + $vatRate / 100) * ($vatRate / 100) : 0.0;
        $m['tax'] = ($base - $m['vat']) * $taxRate / 100;

        // Себестоимость (CQ)
        $qty = isset($r['_qty']) ? (float) $r['_qty'] : max(1, abs((int) $r['quantity']));
        if ($op === 'коррекция продаж') {
            $m['cost'] = 0.0;
        } elseif (in_array($op, self::DEFECT, true)) {
            $m['cost'] = ($doc === 'возврат' ? -1 : 1) * $cost / $dup;
        } elseif ($isReturn) {
            $m['cost'] = -$cost * $qty;
        } elseif ($isSale) {
            $m['cost'] = $cost * $qty;
        } else {
            $m['cost'] = 0.0;
        }

        // Для сверки с «Итого к оплате» WB
        $m['pay_calc'] = $sign * $forPay - $m['logistics'] - $m['storage'] - $m['acceptance'] - $m['penalty'] - $m['deduction'] - $m['additional'] - $m['loyalty'];
        return $m;
    }

    // ------------------------------------------------------------------ сводный расчёт

    /**
     * @param array $opt group: week|month|none; sku: bool; srid: bool; nm: ?int; q: ?string (поиск по srid/артикулу)
     * @return array{total: array, periods: array, bySku: array, bySrid: array, unallocated: array, info: array}
     */
    /** Условие отбора строк отчётов: магазины, период, валюта, товар, заказы (Srid), поиск. */
    private static function filter(string $in, string $from, string $to, array $opt): array
    {
        $where = "r.store_id IN ($in) AND r.date_from BETWEEN :f AND :t";
        $params = ['f' => $from, 't' => $to];
        if (self::$currency !== null) {
            // Отчёты нужной валюты — списком (подзапрос на каждую строку отчёта работал десятки секунд)
            $ids = array_map('intval', array_column(DB::all("SELECT report_id FROM wb_reports WHERE store_id IN ($in) AND currency = ?", [self::$currency]), 'report_id'));
            $where .= ' AND r.report_id IN (' . ($ids ? implode(',', $ids) : '0') . ')';
        }
        if (!empty($opt['nm'])) {
            $where .= ' AND r.nm_id = :nm';
            $params['nm'] = (int) $opt['nm'];
        }
        if (!empty($opt['srids'])) {
            $list = [];
            foreach (array_values($opt['srids']) as $i => $sr) {
                $list[] = ':sr' . $i;
                $params['sr' . $i] = (string) $sr;
            }
            if (!empty($opt['srids_in_period'])) {
                $where .= ' AND r.srid IN (' . implode(',', $list) . ')';
            } else {
                $where = "r.store_id IN ($in) AND r.srid IN (" . implode(',', $list) . ')';
                unset($params['f'], $params['t']);
            }
        }
        if (!empty($opt['q'])) {
            $where .= ' AND (r.srid = :q1 OR r.vendor_code LIKE :q2 OR r.nm_id = :q3)';
            $params += ['q1' => $opt['q'], 'q2' => '%' . $opt['q'] . '%', 'q3' => ctype_digit((string) $opt['q']) ? (int) $opt['q'] : -1];
        }
        // Неделя загружена файлом, а детализация из WB по API ещё не догрузилась — считаем по файлу, недогруженный отчёт пропускаем
        $skip = array_map('intval', array_column(DB::all("SELECT a.report_id FROM wb_reports a
            JOIN wb_reports m ON m.store_id = a.store_id AND m.date_from = a.date_from AND m.report_id < 0
            WHERE a.store_id IN ($in) AND a.report_id > 0 AND a.detail_done = 0"), 'report_id'));
        if ($skip) {
            $where .= ' AND r.report_id NOT IN (' . implode(',', array_unique($skip)) . ')';
        }
        return [$where, $params];
    }

    /**
     * Страница заказов (Srid) за период — новые сверху, без расчёта всех строк в памяти.
     * @return array{0: string[], 1: int} список Srid и общее число заказов
     */
    public static function sridPage(array $storeIds, string $from, string $to, ?string $q, int $limit, int $offset): array
    {
        if (!$storeIds) {
            return [[], 0];
        }
        $in = implode(',', array_map('intval', $storeIds));
        [$where, $params] = self::filter($in, $from, $to, ['q' => $q]);
        $total = (int) DB::value("SELECT COUNT(DISTINCT r.srid) FROM wb_report_rows r WHERE $where AND r.srid <> ''", $params);
        $srids = array_column(DB::all("SELECT r.srid, MAX(COALESCE(r.sale_dt, r.order_dt)) d FROM wb_report_rows r WHERE $where AND r.srid <> ''
            GROUP BY r.srid ORDER BY d DESC, r.srid LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params), 'srid');
        return [$srids, $total];
    }

    /**
     * Расчёт с кэшем: готовый результат хранится в storage/cache/fin, пока не изменились данные
     * (отчёты WB, себестоимость, расходы, реклама, хранение, налог магазинов, курсы). За «всё время» расчёт тяжёлый.
     */
    public static function compute(array $account, array $storeIds, string $from, string $to, array $opt = []): array
    {
        if (!$storeIds || !empty($opt['srids']) || !empty($opt['q']) || !empty($opt['nm'])) {
            return self::computeRaw($account, $storeIds, $from, $to, $opt);
        }
        $ids = array_map('intval', $storeIds);
        sort($ids);
        $key = sha1(json_encode([APP_VERSION, filemtime(__FILE__), (int) $account['id'], $ids, $from, $to, $opt, self::$currency, self::$toRub, self::$commonExpenses,
            $account['profit_settings'] ?? '', self::dataVersion((int) $account['id'], implode(',', $ids))]));
        $dir = BASE_PATH . '/storage/cache/fin';
        $file = "$dir/$key.ser";
        if (is_file($file) && filemtime($file) > time() - 86400 && ($cached = @unserialize((string) file_get_contents($file))) !== false) {
            return $cached;
        }
        $res = self::computeRaw($account, $storeIds, $from, $to, $opt);
        if (!self::$fxFailed && (is_dir($dir) || @mkdir($dir, 0775, true))) {
            @file_put_contents($file, serialize($res), LOCK_EX);
            if (random_int(1, 50) === 1) {
                foreach (glob("$dir/*.ser") ?: [] as $f) {
                    if (filemtime($f) < time() - 86400) {
                        @unlink($f);
                    }
                }
            }
        }
        return $res;
    }

    /** Отпечаток данных, от которых зависит расчёт: изменился — кэш недействителен. */
    private static function dataVersion(int $accountId, string $in): string
    {
        $parts = [
            DB::one("SELECT COUNT(*) c, COALESCE(SUM(rows_loaded), 0) r, MAX(loaded_at) l, COALESCE(SUM(bank_payment), 0) b FROM wb_reports WHERE store_id IN ($in)"),
            DB::one("SELECT COUNT(cost_price) c, COALESCE(SUM(cost_price), 0) s FROM products WHERE store_id IN ($in)"),
            DB::one('SELECT COUNT(*) c, COALESCE(SUM(amount), 0) s, MAX(id) m FROM fin_expenses WHERE user_id = ?', [$accountId]),
            DB::all("SELECT id, currency, tax_rate, vat_rate, weekly_expense FROM stores WHERE id IN ($in) ORDER BY id"),
            DB::one("SELECT COUNT(*) c, COALESCE(SUM(amount), 0) s FROM wb_ad_costs WHERE store_id IN ($in)"),
            DB::one("SELECT COUNT(*) c FROM wb_ad_nms WHERE store_id IN ($in)"),
            DB::one("SELECT COUNT(*) c, COALESCE(SUM(amount), 0) s FROM wb_storage WHERE store_id IN ($in)"),
            // В рублях: курс текущего периода меняется раз в день; расчёт по одной валюте от курса не зависит
            self::$toRub && self::$currency === null ? date('Y-m-d') : 0,
        ];
        return sha1(json_encode($parts));
    }

    private static function computeRaw(array $account, array $storeIds, string $from, string $to, array $opt = []): array
    {
        if (self::$toRub && self::$currency === null && empty($opt['_fx']) && $storeIds) {
            $rub = self::computeRub($account, $storeIds, $from, $to, $opt);
            if ($rub !== null) {
                return $rub;
            }
        }
        $group = $opt['group'] ?? 'week';
        $withSku = !empty($opt['sku']);
        $withSrid = !empty($opt['srid']);
        // Налог и НДС — по ставке магазина (задаются в разделе «Магазины»), по умолчанию — общие настройки
        $settings = Profit::settings($account);
        $taxRate = (float) $settings['tax'];
        $vatRate = (float) ($settings['vat'] ?? 0);
        $res = ['total' => self::empty(), 'periods' => [], 'bySku' => [], 'bySrid' => [], 'info' => ['rows' => 0, 'no_cost' => []]];
        if (!$storeIds) {
            $res['unallocated'] = self::empty();
            return $res;
        }
        $in = implode(',', array_map('intval', $storeIds));
        [$where, $params] = self::filter($in, $from, $to, $opt);

        // Справочники: себестоимость, дубли ШК в компенсациях брака
        $costs = [];
        foreach (DB::all("SELECT store_id, nm_id, cost_price FROM products WHERE store_id IN ($in) AND cost_price IS NOT NULL") as $p) {
            $costs[$p['store_id'] . ':' . $p['nm_id']] = (float) $p['cost_price'];
        }
        $dups = [];
        $defect = "'" . implode("','", self::DEFECT) . "'";
        foreach (DB::all("SELECT shk_id, doc_type, COUNT(*) c FROM wb_report_rows r WHERE $where AND LOWER(r.oper) IN ($defect) GROUP BY shk_id, doc_type", $params) as $d) {
            $dups[$d['shk_id'] . '|' . $d['doc_type']] = (int) $d['c'];
        }

        $storeRates = [];
        foreach (DB::all("SELECT id, tax_rate, vat_rate FROM stores WHERE id IN ($in)") as $st) {
            $storeRates[(int) $st['id']] = [$st['tax_rate'] !== null ? (float) $st['tax_rate'] : $taxRate, $st['vat_rate'] !== null ? (float) $st['vat_rate'] : $vatRate];
        }
        $adStores = array_flip(array_map('intval', array_column(DB::all("SELECT DISTINCT store_id FROM wb_ad_costs WHERE store_id IN ($in)"), 'store_id')));
        $consume = function (array $r) use (&$res, $group, $costs, $storeRates, $taxRate, $vatRate, $dups, $adStores, $withSku, $withSrid): void {
            $res['info']['rows'] += (int) ($r['_n'] ?? 1);
            $pk = self::periodKey((string) $r['date_from'], $group);
            $nm = (int) $r['nm_id'];
            $srid = (string) $r['srid'];
            $cost = $nm ? ($costs[$r['store_id'] . ':' . $nm] ?? 0.0) : 0.0;
            $rates = $storeRates[$r['store_id']] ?? [$taxRate, $vatRate];
            $m = self::row($r, $cost, $dups[$r['shk_id'] . '|' . $r['doc_type']] ?? 1, $rates[0], $rates[1], isset($adStores[$r['store_id']]));
            if ($nm && $cost == 0.0 && ($m['sales'] > 0 || $m['returns'] > 0)) {
                $res['info']['no_cost'][$nm] = true;
            }
            self::add($res['total'], $m);
            $res['periods'][$pk] ??= self::empty();
            self::add($res['periods'][$pk], $m);
            if ($withSku && $nm) {
                $res['bySku'][$nm] ??= self::empty() + ['nm_id' => $nm, 'store_id' => (int) $r['store_id'], 'vendor_code' => '', 'subject' => '', 'brand' => '', 'title' => '', 'ts' => []];
                $sku = &$res['bySku'][$nm];
                self::add($sku, $m);
                $sku['vendor_code'] = $r['vendor_code'] ?: $sku['vendor_code'];
                $sku['subject'] = $r['subject'] ?: $sku['subject'];
                $sku['brand'] = $r['brand'] ?: $sku['brand'];
                $sku['title'] = $r['title'] ?: $sku['title'];
                unset($sku);
            }
            if ($withSrid && $srid !== '') {
                $res['bySrid'][$srid] ??= self::empty() + ['srid' => $srid, 'nm_id' => $nm, 'vendor_code' => '', 'subject' => '', 'date' => null, 'rows' => 0];
                $o = &$res['bySrid'][$srid];
                self::add($o, $m);
                $o['rows']++;
                $o['nm_id'] = $nm ?: $o['nm_id'];
                $o['vendor_code'] = $r['vendor_code'] ?: $o['vendor_code'];
                $o['subject'] = $r['subject'] ?: $o['subject'];
                $o['date'] = $r['sale_dt'] ?: ($r['order_dt'] ?: $o['date']);
                unset($o);
            }
        };
        // Компенсации брака и потерь считаются построчно (важен каждый ШК), остальное — суммами однотипных строк:
        // неделя × товар × тип документа × обоснование × вид услуги. Итог тот же, а строк в PHP в десятки раз меньше.
        $perRow = "'" . implode("','", array_unique(array_merge(self::DEFECT, self::LOST))) . "'";
        $queries = $withSrid ? ["SELECT r.* FROM wb_report_rows r WHERE $where ORDER BY r.date_from, r.rrd_id"] : [
            "SELECT r.* FROM wb_report_rows r WHERE $where AND LOWER(COALESCE(r.oper, '')) IN ($perRow) ORDER BY r.date_from, r.rrd_id",
            "SELECT r.store_id, r.date_from, r.nm_id, r.doc_type, r.oper, r.bonus_type, NULL AS shk_id, NULL AS srid,
                MAX(r.vendor_code) vendor_code, MAX(r.subject) subject, MAX(r.brand) brand, MAX(r.title) title,
                COUNT(*) _n, SUM(GREATEST(1, ABS(COALESCE(r.quantity, 0)))) _qty, SUM(r.quantity) quantity,
                SUM(r.retail_price_disc) retail_price_disc, SUM(r.for_pay) for_pay, SUM(r.acquiring_fee) acquiring_fee, SUM(r.retail_amount) retail_amount,
                SUM(r.delivery_service) delivery_service, SUM(r.cashback_amount) cashback_amount, SUM(r.cashback_comm) cashback_comm,
                SUM(r.cashback_discount) cashback_discount, SUM(r.delivery_amount) delivery_amount, SUM(r.return_amount) return_amount,
                SUM(r.paid_storage) paid_storage, SUM(r.paid_acceptance) paid_acceptance, SUM(r.penalty) penalty, SUM(r.deduction) deduction, SUM(r.additional) additional
            FROM wb_report_rows r WHERE $where AND LOWER(COALESCE(r.oper, '')) NOT IN ($perRow)
            GROUP BY r.store_id, r.date_from, r.nm_id, r.doc_type, r.oper, r.bonus_type, r.retail_price_disc > 0, r.for_pay > 0
            ORDER BY r.date_from",
        ];
        foreach ($queries as $sql) {
            $stmt = DB::pdo()->prepare($sql);
            $stmt->execute($params);
            while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $consume($r);
            }
            $stmt->closeCursor();
        }

        if (empty($opt['nm']) && empty($opt['q']) && empty($opt['srids'])) {
            self::addAdsStorageExpenses($res, $account, $in, $from, $to, $group, $withSku);
        }
        foreach ($res['periods'] as &$p) {
            self::finalize($p);
        }
        unset($p);
        ksort($res['periods']);
        self::finalize($res['total']);

        // По товарам: хранение — из отчёта о платном хранении, удержания — только строки с артикулом
        $skuProfit = self::empty();
        foreach ($res['bySku'] as &$s) {
            self::finalize($s, true);
            self::add($skuProfit, $s);
        }
        unset($s);
        foreach ($res['bySrid'] as &$o) {
            self::finalize($o, true);
        }
        unset($o);
        $res['unallocated'] = ['profit' => $res['total']['profit'] - $skuProfit['profit']];
        return $res;
    }

    /**
     * Расчёт в рублях: отчёты каждой валюты считаются отдельно, каждый период переводится по курсу ЦБ на его последний день,
     * товары и заказы — по среднему курсу периода. null — пересчёт не нужен (всё в рублях) или курс недоступен.
     */
    private static function computeRub(array $account, array $storeIds, string $from, string $to, array $opt): ?array
    {
        $in = implode(',', array_map('intval', $storeIds));
        $codes = array_map('strtoupper', array_column(DB::all("SELECT DISTINCT currency FROM wb_reports WHERE store_id IN ($in) AND date_from BETWEEN ? AND ?", [$from, $to]), 'currency'));
        $codes = array_values(array_filter($codes));
        if (!$codes || $codes === ['RUB']) {
            return null;
        }
        $group = $opt['group'] ?? 'week';
        $parts = [];
        self::$nullExpCurrency = in_array('RUB', $codes, true) ? 'RUB' : $codes[0];
        foreach ($codes as $code) {
            Fx::preload($code, $from, $to);
            self::$currency = $code;
            try {
                $r = self::compute($account, $storeIds, $from, $to, ['_fx' => 1] + $opt);
            } finally {
                self::$currency = null;
            }
            if ($code === end($codes)) {
                self::$nullExpCurrency = null;
            }
            if ($code !== 'RUB') {
                $rates = [];
                foreach (array_keys($r['periods']) as $pk) {
                    $end = match ($group) { 'month' => date('Y-m-t', strtotime($pk . '-01')), 'none' => $to, default => date('Y-m-d', strtotime($pk) + 6 * 86400) };
                    $rates[$pk] = Fx::rate($code, min($end, $to));
                    if ($rates[$pk] === null) {
                        self::$fxFailed = true;
                        self::$nullExpCurrency = null;
                        return null;
                    }
                }
                $rev = $r['total']['revenue'];
                $total = self::empty();
                foreach ($r['periods'] as $pk => &$p) {
                    self::scale($p, $rates[$pk]);
                    self::add($total, $p);
                }
                unset($p);
                $avg = $rev != 0.0 ? $total['revenue'] / $rev : (Fx::rate($code, $to) ?? 1.0);
                $r['total'] = $total;
                foreach (['bySku', 'bySrid'] as $k) {
                    foreach ($r[$k] as &$x) {
                        self::scale($x, $avg);
                        if (isset($x['storage_sku'])) {
                            $x['storage_sku'] *= $avg;
                        }
                    }
                    unset($x);
                }
            }
            $parts[] = $r;
        }
        // Сложение валют
        $res = ['total' => self::empty(), 'periods' => [], 'bySku' => [], 'bySrid' => [], 'info' => ['rows' => 0, 'no_cost' => []]];
        foreach ($parts as $r) {
            self::add($res['total'], $r['total']);
            foreach ($r['periods'] as $pk => $p) {
                $res['periods'][$pk] ??= self::empty();
                self::add($res['periods'][$pk], $p);
            }
            foreach (['bySku', 'bySrid'] as $k) {
                foreach ($r[$k] as $id => $x) {
                    if (!isset($res[$k][$id])) {
                        $res[$k][$id] = $x;
                    } else {
                        self::add($res[$k][$id], $x);
                        if (isset($x['storage_sku'])) {
                            $res[$k][$id]['storage_sku'] = ($res[$k][$id]['storage_sku'] ?? 0) + $x['storage_sku'];
                        }
                    }
                }
            }
            $res['info']['rows'] += $r['info']['rows'];
            $res['info']['no_cost'] += $r['info']['no_cost'];
        }
        ksort($res['periods']);
        foreach ($res['periods'] as &$p) {
            self::finalize($p);
        }
        unset($p);
        self::finalize($res['total']);
        $skuProfit = 0.0;
        foreach ($res['bySku'] as &$x) {
            self::finalize($x, true);
            $skuProfit += $x['profit'];
        }
        unset($x);
        foreach ($res['bySrid'] as &$x) {
            self::finalize($x, true);
        }
        unset($x);
        $res['unallocated'] = ['profit' => $res['total']['profit'] - $skuProfit];
        $res['info']['rub'] = $codes;
        return $res;
    }

    /** Умножает денежные показатели на курс (количества — без изменений). */
    private static function scale(array &$m, float $rate): void
    {
        foreach (self::METRICS as $k) {
            if (isset($m[$k]) && !in_array($k, self::QTY, true)) {
                $m[$k] *= $rate;
            }
        }
    }

    /** Реклама, платное хранение по товарам и прочие расходы за период. */
    private static function addAdsStorageExpenses(array &$res, array $account, string $in, string $from, string $to, string $group, bool $withSku): void
    {
        // Расчёт по одной валюте: реклама, хранение и расходы — только магазинов в этой валюте,
        // общие расходы без магазина — в одной валюте (рубли, если есть), чтобы не учесть их дважды
        $nullExp = self::$commonExpenses;
        if (self::$currency !== null) {
            $ids = array_column(DB::all("SELECT id FROM stores WHERE id IN ($in) AND UPPER(COALESCE(NULLIF(currency, ''), 'RUB')) = ?", [self::$currency]), 'id');
            $in = $ids ? implode(',', array_map('intval', $ids)) : '0';
            $nullExp = $nullExp && (self::$nullExpCurrency === null || self::$nullExpCurrency === self::$currency);
        }
        // Реклама — по деньгам, как в отчётах WB и Excel-шаблонах:
        //  1) удержания «WB Продвижение» в отчётах реализации (уже учтены в строках отчёта, см. ads_deducted);
        //  2) пополнения счёта продвижения картой или по счёту (/adv/v1/payments) — этих денег в отчёте нет.
        // Бонусы и промо-бонусы (кэшбэк) — не деньги продавца, расходом не считаются. Затраты кабинета (/adv/v1/upd)
        // нужны только чтобы разнести рекламу по товарам.
        $topups = 0.0;
        foreach (DB::all("SELECT day, amount FROM wb_ad_payments WHERE store_id IN ($in) AND day BETWEEN ? AND ? AND type IN (0, 3)
                AND COALESCE(status_id, 1) = 1 AND COALESCE(card_status, '') NOT IN ('fail', 'pending', 'unknown')", [$from, $to]) as $p) {
            $pk = self::periodKey(self::weekStart((string) $p['day']), $group);
            $res['periods'][$pk] ??= self::empty();
            $res['periods'][$pk]['ads'] += (float) $p['amount'];
            $res['total']['ads'] += (float) $p['amount'];
            $topups += (float) $p['amount'];
        }
        $spend = DB::all("SELECT payment_type, SUM(amount) s FROM wb_ad_costs WHERE store_id IN ($in) AND day BETWEEN ? AND ? GROUP BY payment_type", [$from, $to]);
        $res['info']['ads'] = ['deducted' => round($res['total']['ads_deducted'], 2), 'topups' => round($topups, 2),
            'spend' => array_map(static fn($x) => round((float) $x, 2), array_column($spend, 's', 'payment_type'))];
        if ($withSku && $res['total']['ads'] != 0.0) {
            // Доли товаров — по затратам кампаний за деньги (без бонусов), кампания делится поровну между её товарами
            $nms = [];
            foreach (DB::all("SELECT store_id, advert_id, nm_id FROM wb_ad_nms WHERE store_id IN ($in) AND nm_id > 0") as $n) {
                $nms[$n['store_id'] . ':' . $n['advert_id']][] = (int) $n['nm_id'];
            }
            $weights = [];
            foreach (DB::all("SELECT store_id, advert_id, SUM(amount) amount FROM wb_ad_costs WHERE store_id IN ($in) AND day BETWEEN ? AND ?
                    AND COALESCE(payment_type, '') NOT IN ('Бонусы', 'Кэшбэк') GROUP BY store_id, advert_id", [$from, $to]) as $a) {
                $list = array_values(array_filter($nms[$a['store_id'] . ':' . $a['advert_id']] ?? [], static fn($nm) => isset($res['bySku'][$nm])));
                foreach ($list as $nm) {
                    $weights[$nm] = ($weights[$nm] ?? 0) + (float) $a['amount'] / count($list);
                }
            }
            $sum = array_sum($weights);
            // Разносим только то, что ещё не попало на товары (удержание с артикулом уже учтено в его строке)
            $left = $res['total']['ads'] - array_sum(array_map(static fn($x) => (float) $x['ads'], $res['bySku']));
            if ($sum > 0 && abs($left) >= 0.01) {
                foreach ($weights as $nm => $w) {
                    $res['bySku'][$nm]['ads'] += $left * $w / $sum;
                }
            }
        }

        // Фиксированные расходы магазина: сумма в неделю за каждую неделю, по которой есть отчёт WB
        $curSql = self::$currency !== null ? ' AND currency = ' . DB::pdo()->quote(self::$currency) : '';
        foreach (DB::all("SELECT s.id, s.weekly_expense FROM stores s WHERE s.id IN ($in) AND s.weekly_expense > 0") as $st) {
            $weeks = [];
            foreach (DB::all("SELECT DISTINCT date_from FROM wb_reports WHERE store_id = ? AND date_from BETWEEN ? AND ?$curSql", [(int) $st['id'], $from, $to]) as $w) {
                $weeks[self::weekStart((string) $w['date_from'])] = true;
            }
            foreach (array_keys($weeks) as $wk) {
                $pk = self::periodKey($wk, $group);
                $res['periods'][$pk] ??= self::empty();
                $res['periods'][$pk]['exp_fixed'] += (float) $st['weekly_expense'];
                $res['total']['exp_fixed'] += (float) $st['weekly_expense'];
            }
        }

        // Платное хранение по товарам (лист «Хранение на SKU»)
        if ($withSku) {
            foreach (DB::all("SELECT nm_id, SUM(amount) s FROM wb_storage WHERE store_id IN ($in) AND day BETWEEN ? AND ? GROUP BY nm_id", [$from, $to]) as $s) {
                if (isset($res['bySku'][(int) $s['nm_id']])) {
                    $res['bySku'][(int) $s['nm_id']]['storage_sku'] = (float) $s['s'];
                }
            }
        }

        // Прочие расходы: с артикулом — на товар, без — на период
        $exp = DB::all("SELECT * FROM fin_expenses WHERE user_id = ? AND day BETWEEN ? AND ? AND (" . ($nullExp ? 'store_id IS NULL OR ' : '') . "store_id IN ($in))",
            [(int) $account['id'], $from, $to]);
        foreach ($exp as $e) {
            $amount = (float) $e['amount'];
            $pk = self::periodKey(self::weekStart((string) $e['day']), $group);
            $res['periods'][$pk] ??= self::empty();
            $key = $e['nm_id'] ? 'exp_sku' : 'exp_period';
            $res['periods'][$pk][$key] += $amount;
            $res['total'][$key] += $amount;
            if ($withSku && $e['nm_id'] && isset($res['bySku'][(int) $e['nm_id']])) {
                $res['bySku'][(int) $e['nm_id']]['exp_sku'] += $amount;
            }
        }
    }

    private static function add(array &$to, array $m): void
    {
        foreach (self::METRICS as $k) {
            if (isset($m[$k])) {
                $to[$k] += $m[$k];
            }
        }
    }

    /** Итоговые показатели. Для товара хранение берётся из отчёта о платном хранении. */
    private static function finalize(array &$m, bool $sku = false): void
    {
        $m['turnover'] = $m['revenue'] + $m['spp_comp'] - $m['commission'] - $m['acquiring'] - $m['logistics'] - $m['loyalty'];
        if ($sku) {
            $m['storage'] = $m['storage_sku'] ?? $m['storage'];
        }
        $m['wb_other'] = $m['storage'] + $m['acceptance'] + $m['penalty'] + $m['deduction'] + $m['additional'] - $m['ads_deducted'];
        $m['profit'] = $m['turnover'] - $m['wb_other'] - $m['cost'] - $m['tax'] - $m['vat'] - $m['ads'] - $m['exp_sku'] - $m['exp_period'] - $m['exp_fixed'];
        $m['margin'] = $m['revenue'] > 0 ? $m['profit'] / $m['revenue'] * 100 : null;
        $m['roi'] = $m['cost'] > 0 ? $m['profit'] / $m['cost'] * 100 : null;
    }

    public static function weekStart(string $day): string
    {
        $t = strtotime($day);
        return date('Y-m-d', $t - ((int) date('N', $t) - 1) * 86400);
    }

    public static function periodKey(string $dateFrom, string $group): string
    {
        return match ($group) {
            'month' => substr($dateFrom, 0, 7),
            'none'  => 'all',
            default => $dateFrom,
        };
    }

    public static function periodLabel(string $key, string $group): string
    {
        static $months = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];
        if ($group === 'month') {
            return $months[(int) substr($key, 5, 2) - 1] . ' ' . substr($key, 0, 4);
        }
        if ($group === 'none') {
            return 'Итого';
        }
        $t = strtotime($key);
        return date('d.m', $t) . '–' . date('d.m.y', $t + 6 * 86400);
    }

    // ------------------------------------------------------------------ ABC-анализ

    /**
     * ABC по доле в выручке и в прибыли (как в листе «Отчёт реализации свод»):
     * накопленная доля до 80% — A, до 95% — B, остальное — C. Товары с убытком — C по прибыли.
     */
    public static function abc(array &$skus): void
    {
        foreach (['revenue' => 'abc_rev', 'profit' => 'abc_profit'] as $metric => $key) {
            $list = array_filter($skus, static fn($s) => $s[$metric] > 0);
            uasort($list, static fn($a, $b) => $b[$metric] <=> $a[$metric]);
            $total = array_sum(array_column($list, $metric));
            $acc = 0.0;
            foreach ($skus as $nm => $s) {
                $skus[$nm][$key] = 'C';
            }
            foreach ($list as $nm => $s) {
                $share = $total > 0 ? $acc / $total : 1;
                $skus[$nm][$key] = $share < 0.8 ? 'A' : ($share < 0.95 ? 'B' : 'C');
                $acc += $s[$metric];
            }
        }
    }

    // ------------------------------------------------------------------ периоды

    /** Недели и месяцы, за которые есть отчёты (для выбора периода). */
    public static function availablePeriods(array $storeIds): array
    {
        if (!$storeIds) {
            return ['weeks' => [], 'months' => [], 'min' => null, 'max' => null];
        }
        $in = implode(',', array_map('intval', $storeIds));
        $weeks = array_column(DB::all("SELECT DISTINCT date_from FROM wb_reports WHERE store_id IN ($in) ORDER BY date_from DESC"), 'date_from');
        $months = array_values(array_unique(array_map(static fn($d) => substr($d, 0, 7), $weeks)));
        return ['weeks' => $weeks, 'months' => $months, 'min' => end($weeks) ?: null, 'max' => $weeks[0] ?? null];
    }
}
