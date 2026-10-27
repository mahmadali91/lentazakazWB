<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Wb\WbClient;

/**
 * Загрузка данных для раздела «Финансы» (расчёт чистой прибыли по отчётам реализации WB).
 * Вызывается из SyncService; каждый шаг делает один запрос к WB (лимиты: отчёты — 1 в минуту,
 * платное хранение — 1 в минуту, реклама — 1 в секунду) и сам назначает время следующего шага.
 *
 *  - reports(): список еженедельных отчётов → детализация каждого отчёта порциями (rrdId)
 *  - storage(): отчёт о платном хранении по дням и товарам (задания по 8 дней)
 *  - ads():     списания за рекламу и товары рекламных кампаний
 */
final class FinanceSync
{
    /** За сколько дней загружать историю при подключении (данные WB доступны с 29.01.2024). */
    public const HISTORY_DAYS = 92;
    public const WB_DATA_START = '2024-01-29';
    /**
     * Строк детализации за запрос: меньше максимума WB (100 000), чтобы уложиться в память хостинга
     * (строка детализации — ~80 полей, 10 000 строк ≈ 100 МБ в PHP). Если запрос всё же не уложился — порция уменьшается сама.
     */
    private const ROWS_LIMIT = 10000;
    private const ROWS_LIMIT_MIN = 1000;
    private const LIST_EVERY = 6 * 3600;

    public function __construct(private array $store, private readonly WbClient $wb)
    {
    }

    private function state(string $column): array
    {
        return json_decode((string) ($this->store[$column] ?? ''), true) ?: [];
    }

    /**
     * Как часто проверять список отчётов: с понедельника по среду, пока нет отчёта за прошлую неделю, — раз в час
     * (WB открывает новый отчёт в API в разное время после формирования), иначе — раз в 6 часов.
     */
    private function listEvery(int $storeId): int
    {
        if ((int) date('N') > 3) {
            return self::LIST_EVERY;
        }
        $last = DB::value('SELECT MAX(date_to) FROM wb_reports WHERE store_id = ?', [$storeId]);
        return $last !== null && $last < date('Y-m-d', strtotime('last sunday')) ? 3600 : self::LIST_EVERY;
    }

    private function save(string $column, array $state, string $nextColumn, int $seconds, ?string $okColumn = null): void
    {
        $sql = "UPDATE stores SET $column = ?, $nextColumn = ?" . ($okColumn ? ", $okColumn = NOW()" : '') . ' WHERE id = ?';
        DB::exec($sql, [json_encode($state), date('Y-m-d H:i:s', time() + $seconds), $this->store['id']]);
        $this->store[$column] = json_encode($state);
    }

    /** Начало истории: из настроек магазина (кнопка «Загрузить историю») или HISTORY_DAYS назад. */
    private function historyFrom(array $state): string
    {
        $from = $state['from'] ?? date('Y-m-d', time() - self::HISTORY_DAYS * 86400);
        return max(self::WB_DATA_START, (string) $from);
    }

    private static function num(mixed $v): float
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        return is_numeric($v) ? (float) $v : (float) str_replace([',', ' '], ['.', ''], (string) $v);
    }

    private static function dt(mixed $v): ?string
    {
        if (!$v || str_starts_with((string) $v, '0001')) {
            return null;
        }
        $t = strtotime((string) $v);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    // ------------------------------------------------------------------ отчёты реализации

    public function reports(): int
    {
        $state = $this->state('fin_state');
        $storeId = (int) $this->store['id'];

        // 1. Список отчётов: при подключении — за всю историю, дальше раз в 6 часов за последние 5 недель
        if (empty($state['listed']) || (int) $state['listed'] < time() - $this->listEvery($storeId)) {
            $from = empty($state['history_done']) ? $this->historyFrom($state) : date('Y-m-d', time() - 35 * 86400);
            $offset = (int) ($state['list_offset'] ?? 0);
            $list = $this->wb->salesReports($from, date('Y-m-d'), $offset);
            $count = 0;
            foreach ($list as $r) {
                if (!empty($r['reportId'])) {
                    $this->upsertReport($storeId, $r);
                    $count++;
                }
            }
            if (count($list) >= 1000) {
                // Больше 1000 отчётов — следующую страницу запросим через минуту (лимит WB)
                $state['list_offset'] = $offset + 1000;
            } else {
                unset($state['list_offset']);
                $state['listed'] = time();
                $state['history_done'] = true;
            }
            $this->save('fin_state', $state, 'fin_next_at', 61);
            return $count;
        }

        // 2. Детализация: по одному запросу в минуту, от новых отчётов к старым — свежие недели появляются в расчёте первыми
        $rep = DB::one('SELECT * FROM wb_reports WHERE store_id = ? AND detail_done = 0 ORDER BY date_from DESC, report_id LIMIT 1', [$storeId]);
        if (!$rep) {
            $this->save('fin_state', $state, 'fin_next_at', max(61, (int) $state['listed'] + $this->listEvery($storeId) - time()), 'fin_ok_at');
            return 0;
        }
        // Порция строк: если прошлый запрос этой же порции не завершился (PHP не хватило памяти — процесс прерван),
        // берём вчетверо меньше и запоминаем для магазина
        $limit = (int) ($state['rows_limit'] ?? self::ROWS_LIMIT);
        $try = $state['detail_try'] ?? null;
        if ($try && (int) $try['report'] === (int) $rep['report_id'] && (int) $try['cursor'] === (int) $rep['rrd_cursor']) {
            $limit = max(self::ROWS_LIMIT_MIN, intdiv((int) $try['limit'], 4));
            $state['rows_limit'] = $limit;
            log_message('sync', "store {$storeId}: детализация отчёта {$rep['report_id']} не уложилась в память, порция уменьшена до $limit строк");
        }
        $state['detail_try'] = ['report' => (int) $rep['report_id'], 'cursor' => (int) $rep['rrd_cursor'], 'limit' => $limit];
        DB::exec('UPDATE stores SET fin_state = ? WHERE id = ?', [json_encode($state), $storeId]);
        unset($state['detail_try']);
        try {
            $rows = $this->wb->salesReportRows((int) $rep['report_id'], (int) $rep['rrd_cursor'], $limit);
        } catch (\Throwable $e) {
            // Обычная ошибка WB (429 и т. п.) — не повод уменьшать порцию
            DB::exec('UPDATE stores SET fin_state = ? WHERE id = ?', [json_encode($state), $storeId]);
            $this->store['fin_state'] = json_encode($state);
            throw $e;
        }
        $last = (int) $rep['rrd_cursor'];
        $n = count($rows);
        $chunk = [];
        foreach ($rows as $k => $row) {
            unset($rows[$k]); // освобождаем память по ходу записи
            if (empty($row['rrdId'])) {
                continue;
            }
            $chunk[] = $this->mapRow($storeId, $rep, $row);
            $last = max($last, (int) $row['rrdId']);
            if (count($chunk) >= 500) {
                $this->insertRows($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            $this->insertRows($chunk);
        }
        $done = $n < $limit;
        if ($done) {
            self::dropManual($storeId, (string) $rep['date_from']);
            // Товары из отчёта — в справочник, чтобы им можно было указать себестоимость
            DB::exec('INSERT IGNORE INTO products (store_id, nm_id, vendor_code, brand, subject, title, updated_at)
                SELECT store_id, nm_id, MAX(vendor_code), LEFT(MAX(brand), 120), LEFT(MAX(subject), 120), MAX(title), NOW() FROM wb_report_rows
                WHERE store_id = ? AND report_id = ? AND nm_id IS NOT NULL GROUP BY store_id, nm_id', [$storeId, $rep['report_id']]);
        }
        DB::exec('UPDATE wb_reports SET rows_loaded = rows_loaded + ?, rrd_cursor = ?, detail_done = ?, loaded_at = IF(?, NOW(), loaded_at) WHERE store_id = ? AND report_id = ?',
            [$n, $last, $done ? 1 : 0, $done ? 1 : 0, $storeId, $rep['report_id']]);
        $this->save('fin_state', $state, 'fin_next_at', 61, 'fin_ok_at');
        return $n;
    }

    /** Удаляет загруженные файлом отчёты за неделю (их заменил отчёт из WB по API). */
    private static function dropManual(int $storeId, string $weekFrom): void
    {
        foreach (DB::all('SELECT report_id FROM wb_reports WHERE store_id = ? AND date_from = ? AND report_id < 0', [$storeId, $weekFrom]) as $m) {
            DB::exec('DELETE FROM wb_report_rows WHERE store_id = ? AND report_id = ?', [$storeId, $m['report_id']]);
            DB::exec('DELETE FROM wb_reports WHERE store_id = ? AND report_id = ?', [$storeId, $m['report_id']]);
        }
    }

    private function upsertReport(int $storeId, array $r): void
    {
        // Отчёт из WB заменяет загруженный вручную файл за ту же неделю — когда его детализация загружена полностью
        if ((int) DB::value('SELECT detail_done FROM wb_reports WHERE store_id = ? AND report_id = ?', [$storeId, (int) $r['reportId']])) {
            self::dropManual($storeId, substr((string) $r['dateFrom'], 0, 10));
        }
        $n = static fn($k) => round(self::num($r[$k] ?? 0), 2);
        DB::exec('INSERT INTO wb_reports (store_id, report_id, date_from, date_to, create_date, report_type, currency, retail_amount, for_pay,
                delivery, storage, acceptance, deduction, penalty, additional, cashback, bank_payment, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE retail_amount = VALUES(retail_amount), for_pay = VALUES(for_pay), delivery = VALUES(delivery),
                storage = VALUES(storage), acceptance = VALUES(acceptance), deduction = VALUES(deduction), penalty = VALUES(penalty),
                additional = VALUES(additional), cashback = VALUES(cashback), bank_payment = VALUES(bank_payment), updated_at = NOW()', [
            $storeId, (int) $r['reportId'], substr((string) $r['dateFrom'], 0, 10), substr((string) $r['dateTo'], 0, 10),
            !empty($r['createDate']) ? substr((string) $r['createDate'], 0, 10) : null, (int) ($r['reportType'] ?? 1),
            mb_substr((string) ($r['currency'] ?? ''), 0, 8), $n('retailAmountSum'), $n('forPaySum'), $n('deliveryServiceSum'),
            $n('paidStorageSum'), $n('paidAcceptanceSum'), $n('deductionSum'), $n('penaltySum'), $n('additionalPaymentSum'),
            round(self::num($r['cashbackAmountSum'] ?? 0) + self::num($r['cashbackCommissionChangeSum'] ?? 0) - self::num($r['cashbackDiscountSum'] ?? 0), 2),
            $n('bankPaymentSum'),
        ]);
    }

    private const ROW_COLUMNS = ['store_id', 'rrd_id', 'report_id', 'date_from', 'date_to', 'gi_id', 'subject', 'nm_id', 'brand', 'vendor_code',
        'title', 'ts_name', 'barcode', 'doc_type', 'oper', 'quantity', 'retail_price', 'retail_amount', 'retail_price_disc', 'sale_percent',
        'commission_pct', 'spp', 'for_pay', 'acquiring_fee', 'delivery_amount', 'return_amount', 'delivery_service', 'bonus_type', 'penalty',
        'additional', 'paid_storage', 'deduction', 'paid_acceptance', 'cashback_amount', 'cashback_discount', 'cashback_comm', 'office_name',
        'pvz_name', 'order_dt', 'sale_dt', 'shk_id', 'srid', 'delivery_method', 'is_b2b'];

    private function mapRow(int $storeId, array $rep, array $r): array
    {
        $s = static fn($k, $len) => isset($r[$k]) && $r[$k] !== '' ? mb_substr(trim(str_replace("\u{A0}", ' ', (string) $r[$k])), 0, $len) : null;
        $m = static fn($k) => round(self::num($r[$k] ?? 0), 2);
        return [
            $storeId, (int) $r['rrdId'], (int) $rep['report_id'], $rep['date_from'], $rep['date_to'], isset($r['giId']) ? (int) $r['giId'] : null,
            $s('subjectName', 160), !empty($r['nmId']) ? (int) $r['nmId'] : null, $s('brandName', 160), $s('vendorCode', 120), $s('title', 255),
            $s('techSize', 60), $s('sku', 40), $s('docTypeName', 40), $s('sellerOperName', 160), (int) ($r['quantity'] ?? 0),
            $m('retailPrice'), $m('retailAmount'), $m('retailPriceWithDisc'), isset($r['salePercent']) ? (float) $r['salePercent'] : null,
            isset($r['commissionPercent']) ? (float) $r['commissionPercent'] : null, isset($r['spp']) ? (float) $r['spp'] : null,
            $m('forPay'), $m('acquiringFee'), (int) ($r['deliveryAmount'] ?? 0), (int) ($r['returnAmount'] ?? 0), $m('deliveryService'),
            $s('bonusTypeName', 255), $m('penalty'), $m('additionalPayment'), $m('paidStorage'), $m('deduction'), $m('paidAcceptance'),
            $m('cashbackAmount'), $m('cashbackDiscount'), $m('cashbackCommissionChange'), $s('officeName', 160), $s('ppvzOfficeName', 255),
            self::dt($r['orderDt'] ?? null), self::dt($r['saleDt'] ?? null), !empty($r['shkId']) ? (int) $r['shkId'] : null,
            $s('srid', 80), $s('deliveryMethod', 80), !empty($r['isB2b']) ? 1 : 0,
        ];
    }

    private function insertRows(array $rows): void
    {
        $cols = self::ROW_COLUMNS;
        $one = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $update = implode(', ', array_map(static fn($c) => "$c = VALUES($c)", array_diff($cols, ['store_id', 'rrd_id'])));
        $sql = 'INSERT INTO wb_report_rows (' . implode(',', $cols) . ') VALUES ' . implode(',', array_fill(0, count($rows), $one))
            . ' ON DUPLICATE KEY UPDATE ' . $update;
        DB::pdo()->prepare($sql)->execute(array_merge(...$rows));
    }

    // ------------------------------------------------------------------ платное хранение

    public function storage(): int
    {
        $state = $this->state('storage_state');
        $storeId = (int) $this->store['id'];
        $yesterday = date('Y-m-d', time() - 86400);

        if (empty($state['task'])) {
            $from = (string) ($state['cursor'] ?? $this->historyFrom($this->state('fin_state')));
            if ($from > $yesterday) {
                // Всё загружено: раз в сутки обновляем последние 8 дней (WB уточняет данные задним числом)
                $state['cursor'] = date('Y-m-d', time() - 8 * 86400);
                $this->save('storage_state', $state, 'storage_next_at', 12 * 3600, 'storage_ok_at');
                return 0;
            }
            $to = min($yesterday, date('Y-m-d', strtotime($from) + 7 * 86400));
            $state['task'] = $this->wb->paidStorageCreate($from, $to);
            $state['from'] = $from;
            $state['to'] = $to;
            $state['at'] = time();
            $this->save('storage_state', $state, 'storage_next_at', 61);
            return 0;
        }

        $status = $this->wb->paidStorageStatus((string) $state['task']);
        if (in_array($status, ['new', 'processing'], true) && time() - (int) $state['at'] < 1800) {
            $this->save('storage_state', $state, 'storage_next_at', 61);
            return 0;
        }
        if ($status !== 'done') {
            unset($state['task']);
            $this->save('storage_state', $state, 'storage_next_at', 600);
            return 0;
        }
        $agg = [];
        foreach ($this->wb->paidStorageDownload((string) $state['task']) as $r) {
            if (empty($r['nmId']) || empty($r['date'])) {
                continue;
            }
            $key = substr((string) $r['date'], 0, 10) . '|' . (int) $r['nmId'];
            $a = $agg[$key] ?? ['vendor' => $r['vendorCode'] ?? null, 'subject' => $r['subject'] ?? null, 'volume' => null, 'qty' => 0, 'amount' => 0.0];
            $a['qty'] += (int) ($r['barcodesCount'] ?? 0);
            $a['amount'] += self::num($r['warehousePrice'] ?? 0);
            if (isset($r['volume']) && is_numeric($r['volume'])) {
                $a['volume'] = (float) $r['volume'];
            }
            $agg[$key] = $a;
        }
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec('DELETE FROM wb_storage WHERE store_id = ? AND day BETWEEN ? AND ?', [$storeId, $state['from'], $state['to']]);
            $stmt = $pdo->prepare('INSERT INTO wb_storage (store_id, day, nm_id, vendor_code, subject, volume, qty, amount) VALUES (?,?,?,?,?,?,?,?)');
            foreach ($agg as $key => $a) {
                [$day, $nm] = explode('|', $key);
                $stmt->execute([$storeId, $day, (int) $nm, $a['vendor'] !== null ? mb_substr((string) $a['vendor'], 0, 120) : null,
                    $a['subject'] !== null ? mb_substr((string) $a['subject'], 0, 160) : null, $a['volume'], $a['qty'], round($a['amount'], 4)]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $state = ['cursor' => date('Y-m-d', strtotime((string) $state['to']) + 86400)];
        $this->save('storage_state', $state, 'storage_next_at', 61, 'storage_ok_at');
        return count($agg);
    }

    // ------------------------------------------------------------------ реклама

    public function ads(): int
    {
        $state = $this->state('ads_state');
        $storeId = (int) $this->store['id'];
        $today = date('Y-m-d');
        $from = (string) ($state['cursor'] ?? $this->historyFrom($this->state('fin_state')));
        $to = min($today, date('Y-m-d', strtotime($from) + 30 * 86400));

        $count = 0;
        foreach ($this->wb->adCosts($from, $to) as $u) {
            if (empty($u['advertId'])) {
                continue;
            }
            $time = self::dt($u['updTime'] ?? null);
            $uid = md5(implode('|', [$u['advertId'], $u['updTime'] ?? '', $u['updNum'] ?? '', $u['updSum'] ?? '', $time ? '' : $to]));
            DB::exec('INSERT INTO wb_ad_costs (store_id, uid, advert_id, camp_name, advert_type, payment_type, upd_num, upd_time, day, amount)
                VALUES (?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE camp_name = VALUES(camp_name), amount = VALUES(amount)', [
                $storeId, $uid, (int) $u['advertId'], mb_substr((string) ($u['campName'] ?? ''), 0, 255), isset($u['advertType']) ? (int) $u['advertType'] : null,
                mb_substr((string) ($u['paymentType'] ?? ''), 0, 40), isset($u['updNum']) ? (int) $u['updNum'] : null, $time,
                $time ? substr($time, 0, 10) : $to, round(self::num($u['updSum'] ?? 0), 2),
            ]);
            $count++;
        }

        // Товары кампаний — чтобы разнести расходы на рекламу по артикулам (до 50 кампаний за запрос)
        $unknown = array_column(DB::all('SELECT DISTINCT c.advert_id FROM wb_ad_costs c
            LEFT JOIN wb_ad_nms n ON n.store_id = c.store_id AND n.advert_id = c.advert_id
            WHERE c.store_id = ? AND n.advert_id IS NULL LIMIT 50', [$storeId]), 'advert_id');
        if ($unknown) {
            usleep(300000);
            $found = [];
            foreach ($this->wb->adverts($unknown) as $a) {
                foreach ((array) ($a['nm_settings'] ?? []) as $nm) {
                    if (!empty($nm['nm_id'])) {
                        DB::exec('INSERT IGNORE INTO wb_ad_nms (store_id, advert_id, nm_id) VALUES (?,?,?)', [$storeId, (int) $a['id'], (int) $nm['nm_id']]);
                        $found[(int) $a['id']] = true;
                    }
                }
            }
            // Кампании без товаров (удалены/архив) помечаем nm_id = 0, чтобы не запрашивать их снова
            foreach ($unknown as $id) {
                if (empty($found[(int) $id])) {
                    DB::exec('INSERT IGNORE INTO wb_ad_nms (store_id, advert_id, nm_id) VALUES (?,?,0)', [$storeId, (int) $id]);
                }
            }
        }

        if ($to >= $today) {
            // Догнали сегодняшний день: дальше раз в 6 часов перечитываем последние 31 день
            $this->save('ads_state', ['cursor' => date('Y-m-d', time() - 30 * 86400)], 'ads_next_at', 6 * 3600, 'ads_ok_at');
        } else {
            $this->save('ads_state', ['cursor' => date('Y-m-d', strtotime($to) + 86400)], 'ads_next_at', 5, 'ads_ok_at');
        }
        return $count;
    }
}
