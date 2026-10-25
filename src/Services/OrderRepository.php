<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Запись заказов из разных источников WB и выборки для ленты. */
final class OrderRepository
{
    public const STATUSES = ['created', 'buyout', 'cancel', 'return', 'returnDefective'];
    public const SCHEMES = ['FBO', 'FBS', 'DBS', 'DBW', 'CC', 'MP'];

    /**
     * Заказ из Ленты заказов (Аналитика) — главный источник статуса.
     * @return int 1 — новый заказ, 2 — обновлён, 0 — без изменений
     */
    /**
     * Цена продавца — из Ленты заказов: она в валюте кабинета WB (как кабинет и отчёты реализации).
     * Статистика отдаёт цены в рублях — для продавцов из других стран они хранятся отдельно (stats_price).
     */
    public static function upsertFromFeed(int $storeId, array $o, bool $silent): int
    {
        $isMp = !empty($o['isMp']);
        $status = in_array($o['status'] ?? '', self::STATUSES, true) ? $o['status'] : 'created';
        $sql = 'INSERT INTO orders
            (store_id, srid, scheme, status, cancel_type, nm_id, chrt_id, warehouse, warehouse_region, is_mp,
             dest_city, dest_district, seller_price, feed_price, is_b2b, in_feed, order_created_at, status_at, first_seen_at, notified_status)
            VALUES (:store, :srid, :scheme, :status, :cancel, :nm, :chrt, :wh, :whr, :ismp,
             :city, :district, :price, :price2, :b2b, 1, :created, :status_at, :seen, :notified)
            ON DUPLICATE KEY UPDATE
              scheme = IF(VALUES(scheme) = \'MP\' AND scheme IS NOT NULL, scheme, VALUES(scheme)),
              status = VALUES(status),
              cancel_type = VALUES(cancel_type),
              nm_id = VALUES(nm_id),
              chrt_id = VALUES(chrt_id),
              warehouse = IF(VALUES(warehouse) = \'Склад WB\' AND warehouse IS NOT NULL AND warehouse <> \'\', warehouse, VALUES(warehouse)),
              warehouse_region = IF(VALUES(warehouse_region) = \'\', warehouse_region, VALUES(warehouse_region)),
              is_mp = VALUES(is_mp),
              dest_city = VALUES(dest_city),
              dest_district = VALUES(dest_district),
              seller_price = COALESCE(VALUES(seller_price), seller_price),
              feed_price = COALESCE(VALUES(feed_price), feed_price),
              is_b2b = VALUES(is_b2b),
              in_feed = 1,
              order_created_at = VALUES(order_created_at),
              status_at = VALUES(status_at)';
        $created = wb_time($o['createdAt'] ?? null) ?? now();
        return DB::exec($sql, [
            'store'     => $storeId,
            'srid'      => (string) $o['srid'],
            'scheme'    => $isMp ? 'MP' : 'FBO',
            'status'    => $status,
            'cancel'    => $o['cancelType'] ?? null,
            'nm'        => $o['nmId'] ?? null,
            'chrt'      => $o['chrtId'] ?? null,
            'wh'        => $o['warehouseName'] ?? null,
            'whr'       => $o['warehouseRegion'] ?? '',
            'ismp'      => $isMp ? 1 : 0,
            'city'      => $o['destinationCity'] ?? null,
            'district'  => $o['destinationDistrict'] ?? null,
            'price'     => isset($o['sellerPrice']) ? (float) $o['sellerPrice'] : null,
            'price2'    => isset($o['sellerPrice']) ? (float) $o['sellerPrice'] : null,
            'b2b'       => !empty($o['isB2b']) ? 1 : 0,
            'created'   => $created,
            'status_at' => wb_time($o['updatedAt'] ?? null) ?? $created,
            'seen'      => now(),
            'notified'  => $silent ? $status : null,
        ]);
    }

    /**
     * Заказ из Статистики (supplier/orders) — дополняет артикул, бренд, предмет, реальный склад WB, цены.
     * Статус не перезаписывает (кроме отмены), чтобы не затереть выкуп из Ленты.
     */
    public static function upsertFromStats(int $storeId, array $o, bool $silent): int
    {
        $isMp = ($o['warehouseType'] ?? '') === 'Склад продавца';
        $status = !empty($o['isCancel']) ? 'cancel' : 'created';
        $sql = 'INSERT INTO orders
            (store_id, srid, scheme, status, nm_id, article, barcode, brand, subject, category, tech_size,
             warehouse, is_mp, region, dest_district, finished_price, total_price, spp, seller_price, stats_price,
             order_created_at, status_at, first_seen_at, notified_status)
            VALUES (:store, :srid, :scheme, :status, :nm, :article, :barcode, :brand, :subject, :category, :size,
             :wh, :ismp, :region, :district, :finished, :total, :spp, :price, :sprice,
             :created, :status_at, :seen, :notified)
            ON DUPLICATE KEY UPDATE
              status = IF(VALUES(status) = \'cancel\' AND status = \'created\', \'cancel\', status),
              article = VALUES(article),
              barcode = VALUES(barcode),
              brand = VALUES(brand),
              subject = VALUES(subject),
              category = VALUES(category),
              tech_size = VALUES(tech_size),
              warehouse = IF(warehouse IS NULL OR warehouse = \'\' OR warehouse = \'Склад WB\', VALUES(warehouse), warehouse),
              region = VALUES(region),
              finished_price = VALUES(finished_price),
              total_price = VALUES(total_price),
              spp = VALUES(spp),
              stats_price = VALUES(stats_price),
              seller_price = COALESCE(seller_price, VALUES(seller_price))';
        $created = wb_time($o['date'] ?? null) ?? now();
        $total = isset($o['totalPrice']) ? (float) $o['totalPrice'] : null;
        // Цена продавца со скидкой продавца — как в отчётах WB о продажах
        $sellerPrice = isset($o['priceWithDisc']) && (float) $o['priceWithDisc'] > 0
            ? round((float) $o['priceWithDisc'], 2)
            : ($total !== null ? round($total * (100 - (float) ($o['discountPercent'] ?? 0)) / 100, 2) : null);
        return DB::exec($sql, [
            'store'     => $storeId,
            'srid'      => (string) $o['srid'],
            'scheme'    => $isMp ? 'MP' : 'FBO',
            'status'    => $status,
            'nm'        => $o['nmId'] ?? null,
            'article'   => $o['supplierArticle'] ?? null,
            'barcode'   => $o['barcode'] ?? null,
            'brand'     => $o['brand'] ?? null,
            'subject'   => $o['subject'] ?? null,
            'category'  => $o['category'] ?? null,
            'size'      => $o['techSize'] ?? null,
            'wh'        => $o['warehouseName'] ?? null,
            'ismp'      => $isMp ? 1 : 0,
            'region'    => $o['regionName'] ?? null,
            'district'  => $o['oblastOkrugName'] ?? null,
            'finished'  => isset($o['finishedPrice']) ? (float) $o['finishedPrice'] : null,
            'total'     => $total,
            'spp'       => isset($o['spp']) ? (float) $o['spp'] : null,
            // Пока нет цены из Ленты — цена из Статистики, пересчитанная в валюту кабинета по курсу магазина
            'price'     => $sellerPrice !== null ? round($sellerPrice * self::statsRate($storeId), 2) : null,
            'sprice'    => $sellerPrice,
            'created'   => $created,
            'status_at' => wb_time($o['cancelDate'] ?? null) ?? $created,
            'seen'      => now(),
            'notified'  => $silent ? $status : null,
        ]);
    }

    /** Курс «валюта кабинета / рубли» для цен из Статистики (1 — магазин в рублях или курс ещё не известен). */
    public static function statsRate(int $storeId): float
    {
        static $cache = [];
        if (!isset($cache[$storeId])) {
            $s = DB::one('SELECT currency, stats_rate FROM stores WHERE id = ?', [$storeId]);
            $cache[$storeId] = $s && strtoupper((string) $s['currency']) !== 'RUB' && (float) $s['stats_rate'] > 0 ? (float) $s['stats_rate'] : 1.0;
        }
        return $cache[$storeId];
    }

    /**
     * Курс между ценой Ленты (валюта кабинета) и priceWithDisc Статистики (рубли) по одним и тем же заказам:
     * самое частое отношение за 14 дней. Для магазинов в рублях не нужен.
     */
    public static function updateStatsRate(int $storeId): void
    {
        $cur = strtoupper((string) DB::value('SELECT currency FROM stores WHERE id = ?', [$storeId]));
        if ($cur === '' || $cur === 'RUB') {
            DB::exec('UPDATE stores SET stats_rate = NULL WHERE id = ?', [$storeId]);
            return;
        }
        $rate = DB::value('SELECT ROUND(feed_price / stats_price, 4) r FROM orders
            WHERE store_id = ? AND feed_price > 0 AND stats_price > 0 AND order_created_at >= ?
            GROUP BY r ORDER BY COUNT(*) DESC LIMIT 1', [$storeId, date('Y-m-d H:i:s', time() - 14 * 86400)]);
        if ($rate !== null && (float) $rate > 0.001) {
            DB::exec('UPDATE stores SET stats_rate = ? WHERE id = ?', [$rate, $storeId]);
        }
    }

    /** Новое сборочное задание из API Маркетплейса — точно определяет схему (FBS/DBS/DBW/CC). */
    public static function upsertFromAssembly(int $storeId, string $scheme, array $o, bool $silent): int
    {
        // convertedPrice — в валюте продавца (для продавцов не из РФ), price/salePrice — в валюте покупателя
        $price = $o['convertedPrice'] ?? $o['salePrice'] ?? $o['price'] ?? null;
        $sql = 'INSERT INTO orders
            (store_id, srid, scheme, status, nm_id, chrt_id, mp_order_id, article, barcode, is_mp, seller_price,
             is_b2b, order_created_at, status_at, first_seen_at, notified_status)
            VALUES (:store, :srid, :scheme, \'created\', :nm, :chrt, :mpid, :article, :barcode, 1, :price,
             :b2b, :created, :created2, :seen, :notified)
            ON DUPLICATE KEY UPDATE
              scheme = VALUES(scheme),
              mp_order_id = VALUES(mp_order_id),
              article = COALESCE(VALUES(article), article),
              barcode = COALESCE(VALUES(barcode), barcode),
              chrt_id = COALESCE(chrt_id, VALUES(chrt_id)),
              is_mp = 1';
        $created = wb_time($o['createdAt'] ?? null) ?? now();
        return DB::exec($sql, [
            'store'    => $storeId,
            'srid'     => (string) $o['rid'],
            'scheme'   => $scheme,
            'nm'       => $o['nmId'] ?? null,
            'chrt'     => $o['chrtId'] ?? null,
            'mpid'     => $o['id'] ?? null,
            'article'  => $o['article'] ?? null,
            'barcode'  => is_array($o['skus'] ?? null) ? ($o['skus'][0] ?? null) : null,
            'price'    => $price !== null ? round($price / 100, 2) : null,
            'b2b'      => !empty($o['options']['isB2B']) ? 1 : 0,
            'created'  => $created,
            'created2' => $created,
            'seen'     => now(),
            'notified' => $silent ? 'created' : null,
        ]);
    }

    /** Минимальная карточка товара из Статистики/сборочных заданий (если нет доступа к Контенту). */
    public static function rememberProduct(int $storeId, ?int $nmId, ?string $vendorCode, ?string $brand = null, ?string $subject = null): void
    {
        if (!$nmId) {
            return;
        }
        DB::exec(
            'INSERT INTO products (store_id, nm_id, vendor_code, brand, subject, updated_at) VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE vendor_code = COALESCE(vendor_code, VALUES(vendor_code)),
               brand = COALESCE(brand, VALUES(brand)), subject = COALESCE(subject, VALUES(subject))',
            [$storeId, $nmId, $vendorCode, $brand, $subject, now()]
        );
    }

    public static function upsertProduct(int $storeId, array $card): void
    {
        $photo = null;
        if (!empty($card['photos'][0]) && is_array($card['photos'][0])) {
            $p = $card['photos'][0];
            $photo = $p['c246x328'] ?? $p['tm'] ?? $p['square'] ?? $p['big'] ?? null;
        }
        // Объём из габаритов карточки (см → л); отчёт остатков WB, если есть, важнее
        $d = $card['dimensions'] ?? [];
        $volume = null;
        $dims = null;
        if (!empty($d['length']) && !empty($d['width']) && !empty($d['height'])) {
            $volume = round($d['length'] * $d['width'] * $d['height'] / 1000, 3);
            $dims = (int) $d['length'] . '×' . (int) $d['width'] . '×' . (int) $d['height'];
        }
        DB::exec(
            'INSERT INTO products (store_id, nm_id, vendor_code, title, brand, subject, subject_id, photo, volume_l, volume_src, dims, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE vendor_code = VALUES(vendor_code), title = VALUES(title), brand = VALUES(brand),
               subject = VALUES(subject), subject_id = VALUES(subject_id), photo = VALUES(photo), dims = VALUES(dims),
               volume_l = IF(volume_src = \'remains\', volume_l, VALUES(volume_l)),
               volume_src = IF(volume_src = \'remains\', volume_src, VALUES(volume_src)),
               updated_at = VALUES(updated_at)',
            [
                $storeId,
                (int) $card['nmID'],
                $card['vendorCode'] ?? null,
                mb_substr((string) ($card['title'] ?? ''), 0, 255) ?: null,
                $card['brand'] ?? null,
                $card['subjectName'] ?? null,
                isset($card['subjectID']) ? (int) $card['subjectID'] : null,
                $photo,
                $volume,
                $volume !== null ? 'card' : null,
                $dims,
                now(),
            ]
        );
    }

    // ---------------------------------------------------------------- выборки для ленты

    /**
     * @param int[] $storeIds
     * @return array{0:string,1:array}
     */
    public static function buildWhere(array $storeIds, array $f): array
    {
        $where = [];
        $params = [];
        $in = [];
        foreach (array_values($storeIds) as $i => $id) {
            $in[] = ':s' . $i;
            $params['s' . $i] = $id;
        }
        $where[] = $in ? 'o.store_id IN (' . implode(',', $in) . ')' : '1 = 0';
        // Выкупы, отмены и возвраты считаем по дате события (как в кабинете WB), заказы — по дате оформления
        $dateCol = ($f['date_by'] ?? '') === 'status' ? 'COALESCE(o.status_at, o.order_created_at)' : 'o.order_created_at';
        if (!empty($f['from'])) {
            $where[] = "$dateCol >= :from";
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = "$dateCol <= :to";
            $params['to'] = $f['to'];
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'return') {
                $where[] = "o.status IN ('return','returnDefective')";
            } elseif (in_array($f['status'], self::STATUSES, true)) {
                $where[] = 'o.status = :status';
                $params['status'] = $f['status'];
            }
        }
        if (!empty($f['scheme'])) {
            if ($f['scheme'] === 'SELLER') {
                $where[] = 'o.is_mp = 1';
            } elseif (in_array($f['scheme'], self::SCHEMES, true)) {
                $where[] = 'o.scheme = :scheme';
                $params['scheme'] = $f['scheme'];
            }
        }
        if (!empty($f['q'])) {
            $q = (string) $f['q'];
            if (ctype_digit($q)) {
                $where[] = '(o.nm_id = :qn OR o.mp_order_id = :qn2 OR o.article LIKE :ql OR o.barcode = :qb)';
                $params['qn'] = (int) $q;
                $params['qn2'] = (int) $q;
                $params['qb'] = $q;
                $params['ql'] = '%' . $q . '%';
            } else {
                $where[] = '(o.article LIKE :ql OR o.brand LIKE :ql2 OR o.subject LIKE :ql3 OR p.title LIKE :ql4 OR o.dest_city LIKE :ql5 OR o.srid = :qs)';
                $like = '%' . $q . '%';
                $params += ['ql' => $like, 'ql2' => $like, 'ql3' => $like, 'ql4' => $like, 'ql5' => $like, 'qs' => $q];
            }
        }
        return [implode(' AND ', $where), $params];
    }

    private const SELECT = 'SELECT o.*, s.name AS store_name, s.currency AS store_currency, p.title AS p_title, p.photo AS p_photo,
            p.brand AS p_brand, p.subject AS p_subject, p.vendor_code AS p_vendor,
            p.cost_price AS p_cost, p.commission_pct AS p_commission, p.subject_id AS p_subject_id,
            p.volume_l AS p_volume, p.volume_src AS p_volume_src, p.dims AS p_dims,
            p.stock_wb AS p_stock, p.stock_to_client AS p_stock_to, p.stock_from_client AS p_stock_from, p.stock_at AS p_stock_at
        FROM orders o
        JOIN stores s ON s.id = o.store_id
        LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id';

    /** @param array|null $profit настройки прибыли кабинета (Profit::settings) или null, если расчёт недоступен по тарифу */
    public static function list(array $storeIds, array $f, int $limit = 50, ?int $beforeId = null, ?string $beforeDate = null, ?array $profit = null): array
    {
        [$where, $params] = self::buildWhere($storeIds, $f);
        if ($beforeId !== null && $beforeDate !== null) {
            $where .= ' AND (o.order_created_at < :bd OR (o.order_created_at = :bd2 AND o.id < :bid))';
            $params += ['bd' => $beforeDate, 'bd2' => $beforeDate, 'bid' => $beforeId];
        }
        $rows = DB::all(self::SELECT . " WHERE $where ORDER BY o.order_created_at DESC, o.id DESC LIMIT " . (int) $limit, $params);
        return array_map(static fn($r) => self::present($r, $profit), $rows);
    }

    /** Заказы, изменившиеся после отметки времени (для живого обновления ленты). */
    public static function changedSince(array $storeIds, array $f, string $since, ?array $profit = null): array
    {
        [$where, $params] = self::buildWhere($storeIds, $f);
        $where .= ' AND o.row_updated_at > :since';
        $params['since'] = $since;
        $rows = DB::all(self::SELECT . " WHERE $where ORDER BY o.order_created_at DESC LIMIT 200", $params);
        return array_map(static fn($r) => self::present($r, $profit), $rows);
    }

    public static function all(array $storeIds, array $f, int $max = 50000, ?array $profit = null): \Generator
    {
        [$where, $params] = self::buildWhere($storeIds, $f);
        $stmt = DB::query(self::SELECT . " WHERE $where ORDER BY o.order_created_at DESC LIMIT " . (int) $max, $params);
        while ($row = $stmt->fetch()) {
            yield self::present($row, $profit);
        }
    }

    public static function kpi(array $storeIds, array $f, ?array $profit = null): array
    {
        $f2 = $f;
        unset($f2['status'], $f2['date_by']);
        [$where, $params] = self::buildWhere($storeIds, $f2);
        // Как в кабинете WB: не считаем заказы, которые есть только в Статистике (неоплаченные, в Ленте заказов их нет);
        // заказы из сборочных заданий без Ленты пока считаем — они появятся в Ленте в течение минуты
        $counted = "(o.in_feed = 1 OR o.finished_price IS NULL OR o.mp_order_id IS NOT NULL)";
        $r = DB::one("SELECT COUNT(*) AS orders_all, SUM($counted) AS orders_count,
                COALESCE(SUM(CASE WHEN $counted THEN o.seller_price END),0) AS orders_sum
            FROM orders o LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE $where", $params) ?? [];
        // Выкупы, отмены, возвраты — произошедшие в этом периоде (по дате статуса), даже если заказ оформлен раньше
        [$whereS, $paramsS] = self::buildWhere($storeIds, $f2 + ['date_by' => 'status']);
        $r += DB::one("SELECT
                SUM(o.status = 'buyout') AS buyout_count,
                COALESCE(SUM(CASE WHEN o.status = 'buyout' THEN o.seller_price END),0) AS buyout_sum,
                SUM(o.status = 'cancel') AS cancel_count,
                SUM(o.status IN ('return','returnDefective')) AS return_count,
                COALESCE(SUM(CASE WHEN o.status IN ('return','returnDefective') THEN o.seller_price END),0) AS return_sum
            FROM orders o LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE $whereS AND o.status <> 'created'", $paramsS) ?? [];
        $count = (int) ($r['orders_count'] ?? 0);
        $out = [
            'orders'      => $count,
            'orders_sum'  => (float) ($r['orders_sum'] ?? 0),
            'orders_all'  => (int) ($r['orders_all'] ?? 0),
            'buyout'      => (int) ($r['buyout_count'] ?? 0) - (int) ($r['return_count'] ?? 0),
            'buyout_sum'  => round((float) ($r['buyout_sum'] ?? 0) - (float) ($r['return_sum'] ?? 0), 2),
            'buyout_gross' => (int) ($r['buyout_count'] ?? 0),
            'cancel'      => (int) ($r['cancel_count'] ?? 0),
            'returns'     => (int) ($r['return_count'] ?? 0),
            'avg'         => $count ? round((float) $r['orders_sum'] / $count) : 0,
        ];
        if ($sales = self::salesKpi($storeIds, $f2)) {
            $out = array_merge($out, $sales);
        }
        if ($profit !== null) {
            $out['profit'] = self::profitTotals($storeIds, $f2, $profit);
            $out['profit']['buyout'] = self::profitTotals($storeIds, $f2 + ['date_by' => 'status', 'status' => 'buyout'], $profit)['buyout'];
        }
        return $out;
    }

    /**
     * Выкупы и возвраты по отчёту продаж WB (wb_sales) — так считает кабинет: выкуп в день выкупа, возврат в день возврата,
     * «Выкупы» = выкупы − возвраты. Свежие выкупы из Ленты, которых ещё нет в отчёте (он обновляется раз в 30 минут), досчитываем.
     * null — отчёта продаж нет или выбран фильтр, который по нему не посчитать (схема FBS/DBS…, поиск).
     */
    private static function salesKpi(array $storeIds, array $f): ?array
    {
        if (!$storeIds || !empty($f['q']) || !in_array($f['scheme'] ?? '', ['', 'FBO', 'SELLER'], true)) {
            return null;
        }
        $in = implode(',', array_map('intval', $storeIds));
        if (!DB::value("SELECT 1 FROM wb_sales WHERE store_id IN ($in) LIMIT 1")) {
            return null;
        }
        $mp = ($f['scheme'] ?? '') === 'FBO' ? ' AND s.is_mp = 0' : (($f['scheme'] ?? '') === 'SELLER' ? ' AND s.is_mp = 1' : '');
        $mpO = ($f['scheme'] ?? '') === 'FBO' ? ' AND o.is_mp = 0' : (($f['scheme'] ?? '') === 'SELLER' ? ' AND o.is_mp = 1' : '');
        $range = [$f['from'], $f['to']];
        // Суммы — в валюте кабинета: цена заказа из Ленты, иначе цена Статистики (рубли) × курс магазина
        $price = "COALESCE(o.seller_price, s.price_with_disc * CASE WHEN st.currency IS NOT NULL AND st.currency <> 'RUB' AND st.stats_rate > 0 THEN st.stats_rate ELSE 1 END)";
        $s = DB::one("SELECT SUM(s.is_return = 0) buy, SUM(s.is_return = 1) ret,
                COALESCE(SUM(CASE WHEN s.is_return = 0 THEN $price END), 0) buy_sum,
                COALESCE(SUM(CASE WHEN s.is_return = 1 THEN $price END), 0) ret_sum
            FROM wb_sales s JOIN stores st ON st.id = s.store_id
            LEFT JOIN orders o ON o.store_id = s.store_id AND o.srid = s.srid
            WHERE s.store_id IN ($in) AND s.sale_dt BETWEEN ? AND ?$mp", $range);
        // Свежее, чем последний отчёт продаж: события из Ленты заказов
        $fresh = DB::one("SELECT SUM(o.status = 'buyout') buy, SUM(o.status IN ('return','returnDefective')) ret,
                COALESCE(SUM(CASE WHEN o.status = 'buyout' THEN o.seller_price END), 0) buy_sum,
                COALESCE(SUM(CASE WHEN o.status IN ('return','returnDefective') THEN o.seller_price END), 0) ret_sum
            FROM orders o WHERE o.store_id IN ($in) AND o.status IN ('buyout','return','returnDefective')
              AND o.status_at BETWEEN ? AND ?$mpO
              AND o.status_at > COALESCE((SELECT MAX(x.sale_dt) FROM wb_sales x WHERE x.store_id = o.store_id), '1970-01-01')", $range);
        $buy = (int) $s['buy'] + (int) $fresh['buy'];
        $ret = (int) $s['ret'] + (int) $fresh['ret'];
        return [
            'buyout'       => $buy - $ret,
            'buyout_sum'   => round((float) $s['buy_sum'] + (float) $fresh['buy_sum'] - (float) $s['ret_sum'] - (float) $fresh['ret_sum'], 2),
            'buyout_gross' => $buy,
            'returns'      => $ret,
            'buyout_src'   => 'wb_sales',
        ];
    }

    /** Суммарная прибыль за период: считается по каждому заказу той же формулой, что и в карточке заказа. */
    private static function profitTotals(array $storeIds, array $f, array $profit): array
    {
        [$where, $params] = self::buildWhere($storeIds, $f);
        $ctx = Profit::context($storeIds);
        $stmt = DB::query("SELECT o.store_id, o.status, o.cancel_type, o.scheme, o.is_mp, o.seller_price, o.warehouse, o.nm_id,
                COALESCE(o.subject, p.subject) AS subject, p.cost_price AS p_cost, p.commission_pct AS p_commission,
                p.subject_id AS p_subject_id, p.volume_l AS p_volume
            FROM orders o LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE $where", $params);
        $t = ['orders' => 0.0, 'buyout' => 0.0, 'loss' => 0.0, 'commission' => 0.0, 'logistics' => 0.0, 'no_cost' => 0, 'no_cost_items' => 0];
        $noCostNm = [];
        while ($r = $stmt->fetch()) {
            $b = Profit::breakdown($r, $profit, $ctx);
            if (!$b) {
                continue;
            }
            if ($b['kind'] === 'loss') {
                $t['loss'] += -$b['profit'];
            } elseif ($b['kind'] === 'sale') {
                if ($b['profit'] === null) {
                    $t['no_cost']++;
                    $noCostNm[$r['store_id'] . '_' . $r['nm_id']] = true;
                    continue;
                }
                $t['orders'] += $b['profit'];
                $t['commission'] += $b['commission'];
                $t['logistics'] += $b['logistics'];
                if ($r['status'] === 'buyout') {
                    $t['buyout'] += $b['profit'];
                }
            }
        }
        $t['orders'] = round($t['orders'] - $t['loss']);
        $t['no_cost_items'] = count($noCostNm);
        foreach (['buyout', 'loss', 'commission', 'logistics'] as $k) {
            $t[$k] = round($t[$k]);
        }
        return $t;
    }

    /** Данные для графика: по часам (если период ≤ 2 суток) или по дням. */
    public static function chart(array $storeIds, array $f): array
    {
        $f2 = $f;
        unset($f2['status']);
        [$where, $params] = self::buildWhere($storeIds, $f2);
        $from = strtotime((string) ($f['from'] ?? date('Y-m-d')));
        $to = strtotime((string) ($f['to'] ?? now()));
        $hourly = ($to - $from) <= 2 * 86400;
        $fmt = $hourly ? '%Y-%m-%d %H:00' : '%Y-%m-%d';
        $rows = DB::all("SELECT DATE_FORMAT(o.order_created_at, '$fmt') AS bucket, COUNT(*) AS cnt, COALESCE(SUM(o.seller_price),0) AS total
            FROM orders o LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE $where GROUP BY bucket ORDER BY bucket", $params);
        $map = [];
        foreach ($rows as $r) {
            $map[$r['bucket']] = ['count' => (int) $r['cnt'], 'sum' => (float) $r['total']];
        }
        $out = [];
        $step = $hourly ? 3600 : 86400;
        $start = $hourly ? strtotime(date('Y-m-d H:00:00', $from)) : strtotime(date('Y-m-d', $from));
        $end = min($to, time());
        for ($t = $start; $t <= $end && count($out) < 100; $t += $step) {
            $key = $hourly ? date('Y-m-d H:00', $t) : date('Y-m-d', $t);
            $out[] = [
                'label' => $hourly ? date('H:00', $t) : date('d.m', $t),
                'count' => $map[$key]['count'] ?? 0,
                'sum'   => $map[$key]['sum'] ?? 0,
            ];
        }
        return ['hourly' => $hourly, 'points' => $out];
    }

    public static function topProducts(array $storeIds, array $f, int $limit = 5): array
    {
        $f2 = $f;
        unset($f2['status']);
        [$where, $params] = self::buildWhere($storeIds, $f2);
        return DB::all("SELECT o.nm_id, MAX(COALESCE(p.title, o.subject)) AS title, MAX(COALESCE(o.article, p.vendor_code)) AS article,
                MAX(p.photo) AS photo, COUNT(*) AS cnt, COALESCE(SUM(o.seller_price),0) AS total
            FROM orders o LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE $where AND o.nm_id IS NOT NULL
            GROUP BY o.nm_id ORDER BY cnt DESC LIMIT " . (int) $limit, $params);
    }

    /** Приводит строку БД к виду для фронтенда. */
    public static function present(array $r, ?array $profit = null): array
    {
        $calc = $profit !== null ? Profit::breakdown($r, $profit, Profit::context([(int) $r['store_id']])) : null;
        $title = $r['p_title'] ?: trim(($r['subject'] ?: $r['p_subject'] ?: '') . ' ' . ($r['brand'] ?: $r['p_brand'] ?: ''));
        return [
            'id'          => (int) $r['id'],
            'srid'        => $r['srid'],
            'store'       => $r['store_name'],
            'store_id'    => (int) $r['store_id'],
            'scheme'      => $r['scheme'],
            'scheme_label'=> scheme_label($r['scheme']),
            'status'      => $r['status'],
            'status_label'=> status_label($r['status']),
            'cancel_type' => $r['cancel_type'],
            'nm_id'       => $r['nm_id'] !== null ? (int) $r['nm_id'] : null,
            'mp_order_id' => $r['mp_order_id'] !== null ? (int) $r['mp_order_id'] : null,
            'article'     => $r['article'] ?: $r['p_vendor'],
            'title'       => $title !== '' ? $title : ('Товар ' . ($r['nm_id'] ?? '')),
            'brand'       => $r['brand'] ?: $r['p_brand'],
            'subject'     => $r['subject'] ?: $r['p_subject'],
            'size'        => $r['tech_size'],
            'photo'       => $r['p_photo'],
            'warehouse'   => $r['warehouse'],
            'city'        => $r['dest_city'] ?: $r['region'],
            'district'    => $r['dest_district'],
            'price'       => $r['seller_price'] !== null ? (float) $r['seller_price'] : null,
            'finished_price' => $r['finished_price'] !== null ? round((float) $r['finished_price'] * self::statsRate((int) $r['store_id']), 2) : null,
            'spp'         => $r['spp'] !== null ? (float) $r['spp'] : null,
            'is_b2b'      => (bool) $r['is_b2b'],
            'created_at'  => $r['order_created_at'],
            'created_iso' => date('c', strtotime($r['order_created_at'])),
            'status_at'   => $r['status_at'],
            'cur'         => currency_sign((string) ($r['store_currency'] ?? '') ?: 'RUB'),
            'status_iso'  => $r['status_at'] ? date('c', strtotime($r['status_at'])) : null,
            'updated'     => $r['row_updated_at'],
            'cost'        => $calc['cost'] ?? null,
            'profit'      => $calc['profit'] ?? null,
            'calc'        => $calc,
            'barcode'     => $r['barcode'],
            'category'    => $r['category'],
            'region'      => $r['region'],
            'volume'      => $r['p_volume'] !== null ? (float) $r['p_volume'] : null,
            'volume_src'  => $r['p_volume_src'],
            'dims'        => $r['p_dims'],
            'stock'       => $r['p_stock'] !== null ? (int) $r['p_stock'] : null,
            'stock_to'    => $r['p_stock_to'] !== null ? (int) $r['p_stock_to'] : null,
            'stock_from'  => $r['p_stock_from'] !== null ? (int) $r['p_stock_from'] : null,
            'stock_at'    => $r['p_stock_at'],
        ];
    }
}
