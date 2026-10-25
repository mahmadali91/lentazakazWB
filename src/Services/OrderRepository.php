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
    public static function upsertFromFeed(int $storeId, array $o, bool $silent): int
    {
        $isMp = !empty($o['isMp']);
        $status = in_array($o['status'] ?? '', self::STATUSES, true) ? $o['status'] : 'created';
        $sql = 'INSERT INTO orders
            (store_id, srid, scheme, status, cancel_type, nm_id, chrt_id, warehouse, warehouse_region, is_mp,
             dest_city, dest_district, seller_price, is_b2b, order_created_at, status_at, first_seen_at, notified_status)
            VALUES (:store, :srid, :scheme, :status, :cancel, :nm, :chrt, :wh, :whr, :ismp,
             :city, :district, :price, :b2b, :created, :status_at, :seen, :notified)
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
              seller_price = VALUES(seller_price),
              is_b2b = VALUES(is_b2b),
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
             warehouse, is_mp, region, dest_district, finished_price, total_price, spp, seller_price,
             order_created_at, status_at, first_seen_at, notified_status)
            VALUES (:store, :srid, :scheme, :status, :nm, :article, :barcode, :brand, :subject, :category, :size,
             :wh, :ismp, :region, :district, :finished, :total, :spp, :price,
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
              seller_price = COALESCE(seller_price, VALUES(seller_price))';
        $created = wb_time($o['date'] ?? null) ?? now();
        $total = isset($o['totalPrice']) ? (float) $o['totalPrice'] : null;
        $sellerPrice = $total !== null ? round($total * (100 - (float) ($o['discountPercent'] ?? 0)) / 100, 2) : null;
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
            'price'     => $sellerPrice,
            'created'   => $created,
            'status_at' => wb_time($o['cancelDate'] ?? null) ?? $created,
            'seen'      => now(),
            'notified'  => $silent ? $status : null,
        ]);
    }

    /** Новое сборочное задание из API Маркетплейса — точно определяет схему (FBS/DBS/DBW/CC). */
    public static function upsertFromAssembly(int $storeId, string $scheme, array $o, bool $silent): int
    {
        $price = $o['salePrice'] ?? $o['price'] ?? null;
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
        DB::exec(
            'INSERT INTO products (store_id, nm_id, vendor_code, title, brand, subject, photo, updated_at)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE vendor_code = VALUES(vendor_code), title = VALUES(title), brand = VALUES(brand),
               subject = VALUES(subject), photo = VALUES(photo), updated_at = VALUES(updated_at)',
            [
                $storeId,
                (int) $card['nmID'],
                $card['vendorCode'] ?? null,
                mb_substr((string) ($card['title'] ?? ''), 0, 255) ?: null,
                $card['brand'] ?? null,
                $card['subjectName'] ?? null,
                $photo,
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
        if (!empty($f['from'])) {
            $where[] = 'o.order_created_at >= :from';
            $params['from'] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'o.order_created_at <= :to';
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

    private const SELECT = 'SELECT o.*, s.name AS store_name, p.title AS p_title, p.photo AS p_photo,
            p.brand AS p_brand, p.subject AS p_subject, p.vendor_code AS p_vendor,
            p.cost_price AS p_cost, p.commission_pct AS p_commission
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
        unset($f2['status']);
        [$where, $params] = self::buildWhere($storeIds, $f2);
        $profitSql = '';
        if ($profit !== null) {
            $unit = Profit::unitExpr($profit);
            $loss = Profit::lossExpr($profit);
            $profitSql = ",
                SUM(CASE WHEN o.status = 'buyout' THEN $unit END) AS profit_buyout,
                SUM(CASE WHEN o.status IN ('created','buyout') THEN $unit END) AS profit_orders,
                SUM(CASE WHEN p.cost_price IS NOT NULL THEN $loss ELSE 0 END) AS profit_loss,
                SUM(o.status IN ('created','buyout') AND p.cost_price IS NULL) AS no_cost,
                COUNT(DISTINCT CASE WHEN o.status IN ('created','buyout') AND p.cost_price IS NULL THEN o.nm_id END) AS no_cost_items";
        }
        $r = DB::one("SELECT
                COUNT(*) AS orders_count,
                COALESCE(SUM(o.seller_price),0) AS orders_sum,
                SUM(o.status = 'buyout') AS buyout_count,
                COALESCE(SUM(CASE WHEN o.status = 'buyout' THEN o.seller_price END),0) AS buyout_sum,
                SUM(o.status = 'cancel') AS cancel_count,
                SUM(o.status IN ('return','returnDefective')) AS return_count
                $profitSql
            FROM orders o LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE $where", $params) ?? [];
        $count = (int) ($r['orders_count'] ?? 0);
        $out = [
            'orders'      => $count,
            'orders_sum'  => (float) ($r['orders_sum'] ?? 0),
            'buyout'      => (int) ($r['buyout_count'] ?? 0),
            'buyout_sum'  => (float) ($r['buyout_sum'] ?? 0),
            'cancel'      => (int) ($r['cancel_count'] ?? 0),
            'returns'     => (int) ($r['return_count'] ?? 0),
            'avg'         => $count ? round((float) $r['orders_sum'] / $count) : 0,
        ];
        if ($profit !== null) {
            $loss = (float) ($r['profit_loss'] ?? 0);
            $out['profit'] = [
                'orders'        => round((float) ($r['profit_orders'] ?? 0) - $loss),
                'buyout'        => round((float) ($r['profit_buyout'] ?? 0)),
                'loss'          => round($loss),
                'no_cost'       => (int) ($r['no_cost'] ?? 0),
                'no_cost_items' => (int) ($r['no_cost_items'] ?? 0),
            ];
        }
        return $out;
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
            'finished_price' => $r['finished_price'] !== null ? (float) $r['finished_price'] : null,
            'spp'         => $r['spp'] !== null ? (float) $r['spp'] : null,
            'is_b2b'      => (bool) $r['is_b2b'],
            'created_at'  => $r['order_created_at'],
            'created_iso' => date('c', strtotime($r['order_created_at'])),
            'status_at'   => $r['status_at'],
            'status_iso'  => $r['status_at'] ? date('c', strtotime($r['status_at'])) : null,
            'updated'     => $r['row_updated_at'],
            'cost'        => $profit !== null && $r['p_cost'] !== null ? (float) $r['p_cost'] : null,
            'profit'      => $profit !== null ? Profit::forOrder($r, $profit) : null,
        ];
    }
}
