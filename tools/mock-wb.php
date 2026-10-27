<?php
/**
 * Мок WB API для локальной разработки и тестов (форматы ответов — по спецификациям dev.wildberries.ru).
 *
 * Запуск:  php -S 127.0.0.1:8090 tools/mock-wb.php
 * Можно поднять один порт на все сервисы или по порту на сервис (тогда ping различает категории):
 *   8090 common, 8091 analytics, 8092 statistics, 8093 marketplace, 8094 content, 8095 finance, 8096 advert.
 * Токены: любой, начинающийся с "test" — все категории; "test-nocontent…" — без Контента;
 *         "test-nofeed…" — без Аналитики; иначе — 401.
 * Новые заказы появляются каждые 2 минуты (детерминированно от текущего времени).
 */
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
header('Content-Type: application/json');

function out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Эмуляция ЮKassa (для тестов оплаты): config yookassa.api = 'http://127.0.0.1:8090/v3'
// Обложки тестовых товаров (для скриншотов): /img/<nmId>.svg
if (preg_match('#^/img/(\d+)\.svg$#', $path, $m)) {
    $pal = [['#7c3aed', '#ec4899'], ['#0ea5e9', '#6366f1'], ['#f59e0b', '#ef4444'], ['#10b981', '#0ea5e9'], ['#8b5cf6', '#06b6d4'], ['#f43f5e', '#f97316']];
    [$a, $b] = $pal[(int) $m[1] % count($pal)];
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="400" viewBox="0 0 300 400"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
        . '<stop offset="0" stop-color="' . $a . '"/><stop offset="1" stop-color="' . $b . '"/></linearGradient></defs><rect width="300" height="400" fill="url(#g)"/>'
        . '<circle cx="150" cy="150" r="70" fill="#fff" opacity=".18"/><rect x="70" y="250" width="160" height="16" rx="8" fill="#fff" opacity=".35"/>'
        . '<rect x="100" y="280" width="100" height="12" rx="6" fill="#fff" opacity=".25"/></svg>';
    exit;
}
if ($path === '/v3/me') {
    out(['account_id' => '123456', 'test' => true, 'status' => 'enabled']);
}
if (str_starts_with($path, '/v3/payments')) {
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = 'pay-' . str_replace('.', '_', $body['amount']['value']) . '-' . bin2hex(random_bytes(3));
        out(['id' => $id, 'status' => 'pending', 'paid' => false, 'amount' => $body['amount'],
             'confirmation' => ['type' => 'redirect', 'confirmation_url' => $body['confirmation']['return_url']]]);
    }
    $id = basename($path);
    $value = str_replace('_', '.', explode('-', $id)[1] ?? '0');
    out(['id' => $id, 'status' => 'succeeded', 'paid' => true, 'amount' => ['value' => $value, 'currency' => 'RUB']]);
}

if (!str_starts_with($token, 'test')) {
    out(['title' => 'unauthorized', 'detail' => 'token is invalid', 'status' => 401], 401);
}

if (str_contains($token, 'nofin') && in_array($_SERVER['SERVER_PORT'], ['8095', '8096'], true)) {
    out(['title' => 'token scope not allowed', 'detail' => 'нет категории', 'status' => 401], 401);
}

$products = [
    [186452311, 'DR-2041', 'Платье летнее миди', 'Lumiere', 'Платья', 'Одежда'],
    [154422190, 'RUN-77', 'Кроссовки беговые сетка', 'Stride', 'Кроссовки', 'Обувь'],
    [99814522, 'KIT-12', 'Набор посуды 12 предметов', 'HomeChef', 'Наборы посуды', 'Дом'],
    [120987345, 'BP-9', 'Рюкзак городской 20 л', 'Urbanix', 'Рюкзаки', 'Аксессуары'],
    [173300812, 'TS-OVR', 'Футболка оверсайз хлопок', 'Lumiere', 'Футболки', 'Одежда'],
];
$cities = [['Москва', 'Центральный'], ['Санкт-Петербург', 'Северо-Западный'], ['Казань', 'Приволжский'], ['Екатеринбург', 'Уральский'], ['Новосибирск', 'Сибирский']];
$whs = ['Коледино', 'Электросталь', 'Казань', 'Тула'];

/** Генерирует заказы за последние N часов: один заказ каждые 2 минуты, детерминированно. */
function orders(int $hours): array
{
    global $products, $cities, $whs;
    $list = [];
    $now = time();
    $start = intdiv($now - $hours * 3600, 120) * 120;
    for ($t = $start; $t <= $now; $t += 120) {
        $i = intdiv($t, 120);
        if ($i % 3 === 1) {
            continue; // не каждый слот — заказ
        }
        $p = $products[$i % count($products)];
        $c = $cities[$i % count($cities)];
        $schemeIdx = $i % 5; // 0,1,2 — FBO; 3 — FBS; 4 — DBS
        $isMp = $schemeIdx >= 3;
        $age = $now - $t;
        $status = 'created';
        $updated = $t;
        if ($age > 3 * 3600 && $i % 4 === 0) { $status = 'buyout'; $updated = $t + 3 * 3600; }
        if ($age > 1800 && $i % 7 === 0) { $status = 'cancel'; $updated = $t + 1800; }
        if ($age > 6 * 3600 && $i % 11 === 0) { $status = 'return'; $updated = $t + 6 * 3600; }
        $list[] = [
            'i' => $i, 't' => $t, 'updated' => $updated, 'product' => $p, 'city' => $c, 'isMp' => $isMp,
            'scheme' => $isMp ? ($schemeIdx === 3 ? 'FBS' : 'DBS') : 'FBO', 'status' => $status,
            'price' => 990 + ($i % 9) * 350, 'wh' => $whs[$i % count($whs)],
        ];
    }
    return $list;
}

function iso(int $ts): string
{
    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d\TH:i:sP');
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

/** Детализация недельного отчёта реализации (синтетика): продажи, логистика, возвраты, хранение, приёмка, штрафы, компенсации. */
function reportRows(string $monday): array
{
    global $products;
    $reportId = (int) date('Ymd', strtotime($monday));
    $rows = [];
    $i = 0;
    $base = ['reportId' => $reportId, 'dateFrom' => $monday, 'dateTo' => date('Y-m-d', strtotime($monday) + 6 * 86400), 'currency' => 'RUB', 'reportType' => 1,
        'docTypeName' => '', 'sellerOperName' => '', 'quantity' => 0, 'retailPrice' => '0', 'retailAmount' => '0', 'retailPriceWithDisc' => '0', 'forPay' => '0',
        'acquiringFee' => '0', 'deliveryAmount' => 0, 'returnAmount' => 0, 'deliveryService' => '0', 'bonusTypeName' => '', 'penalty' => '0',
        'additionalPayment' => '0', 'paidStorage' => '0', 'deduction' => '0', 'paidAcceptance' => '0', 'cashbackAmount' => '0', 'cashbackDiscount' => '0',
        'cashbackCommissionChange' => '0', 'officeName' => 'Коледино', 'srid' => '', 'nmId' => 0, 'shkId' => 0];
    $prices = [186452311 => 2490, 154422190 => 3990, 99814522 => 5490, 120987345 => 1990, 173300812 => 990];
    for ($d = 0; $d < 7; $d++) {
        $day = date('Y-m-d', strtotime($monday) + $d * 86400);
        foreach ($products as $k => $p) {
            $n = (crc32($day . $p[0]) % 4);
            for ($j = 0; $j < $n; $j++) {
                $price = $prices[$p[0]];
                $disc = round($price * 0.8, 2);
                $retail = round($disc * 0.75, 2);
                $acq = round($retail * 0.015, 2);
                $forPay = round($disc * (1 - 0.245) - $acq, 2);
                $srid = 'sr' . $reportId . '-' . $k . '-' . $d . '-' . $j;
                $item = ['nmId' => $p[0], 'vendorCode' => $p[1], 'title' => $p[2], 'brandName' => $p[3], 'subjectName' => $p[4], 'techSize' => '0',
                    'sku' => '20' . $p[0], 'srid' => $srid, 'shkId' => crc32($srid), 'orderDt' => $day . 'T09:00:00Z', 'saleDt' => $day . 'T18:00:00Z', 'giId' => 1000 + $k];
                $rows[] = array_merge($base, $item, ['rrdId' => $reportId * 10000 + ++$i, 'docTypeName' => 'Продажа', 'sellerOperName' => 'Продажа', 'quantity' => 1,
                    'retailPrice' => (string) $price, 'retailAmount' => (string) $retail, 'retailPriceWithDisc' => (string) $disc, 'forPay' => (string) $forPay,
                    'acquiringFee' => (string) $acq, 'commissionPercent' => 24.5, 'spp' => 25, 'salePercent' => 20]);
                $rows[] = array_merge($base, $item, ['rrdId' => $reportId * 10000 + ++$i, 'sellerOperName' => 'Логистика', 'deliveryAmount' => 1,
                    'deliveryService' => (string) (60 + $k * 15), 'bonusTypeName' => 'К клиенту при продаже']);
            }
            if (crc32('ret' . $day . $p[0]) % 9 === 0) {
                $disc = round($prices[$p[0]] * 0.8, 2);
                $retail = round($disc * 0.75, 2);
                $acq = round($retail * 0.015, 2);
                $item = ['nmId' => $p[0], 'vendorCode' => $p[1], 'title' => $p[2], 'brandName' => $p[3], 'subjectName' => $p[4], 'techSize' => '0', 'srid' => 'ret' . $reportId . $k . $d];
                $rows[] = array_merge($base, $item, ['rrdId' => $reportId * 10000 + ++$i, 'docTypeName' => 'Возврат', 'sellerOperName' => 'Возврат', 'quantity' => 1,
                    'retailAmount' => (string) $retail, 'retailPriceWithDisc' => (string) $disc, 'forPay' => (string) round($disc * 0.755 - $acq, 2), 'acquiringFee' => (string) $acq]);
                $rows[] = array_merge($base, $item, ['rrdId' => $reportId * 10000 + ++$i, 'sellerOperName' => 'Логистика', 'returnAmount' => 1,
                    'deliveryService' => '50', 'bonusTypeName' => 'От клиента при возврате']);
            }
        }
    }
    $rows[] = array_merge($base, ['rrdId' => $reportId * 10000 + ++$i, 'sellerOperName' => 'Хранение', 'paidStorage' => '812.40']);
    $rows[] = array_merge($base, ['rrdId' => $reportId * 10000 + ++$i, 'sellerOperName' => 'Услуга по обработке Товара', 'paidAcceptance' => '310.00']);
    if ((int) date('W', strtotime($monday)) % 2 === 0) {
        $rows[] = array_merge($base, ['rrdId' => $reportId * 10000 + ++$i, 'sellerOperName' => 'Штраф', 'penalty' => '300.00', 'bonusTypeName' => 'Штраф за нарушение упаковки']);
        $p = $products[1];
        $rows[] = array_merge($base, ['rrdId' => $reportId * 10000 + ++$i, 'docTypeName' => 'Продажа', 'sellerOperName' => 'Компенсация брака', 'quantity' => 1,
            'nmId' => $p[0], 'vendorCode' => $p[1], 'subjectName' => $p[4], 'forPay' => '2100.00', 'srid' => 'def' . $reportId, 'shkId' => 77]);
    }
    return $rows;
}

/** Завершённые недели (понедельники) в периоде: отчёт формируется в понедельник после недели. */
function reportWeeks(string $from, string $to): array
{
    $weeks = [];
    $t = strtotime('monday this week', strtotime($from));
    $limit = min(strtotime($to), strtotime('monday this week') - 7 * 86400);
    for (; $t <= $limit; $t += 7 * 86400) {
        if ($t >= strtotime($from) - 6 * 86400) {
            $weeks[] = date('Y-m-d', $t);
        }
    }
    return $weeks;
}

switch ($path) {
    case '/api/finance/v1/sales-reports/list':
        $list = [];
        foreach (reportWeeks((string) $body['dateFrom'], (string) $body['dateTo']) as $w) {
            $rows = reportRows($w);
            $sum = static fn($k, $doc = null) => array_sum(array_map(static fn($r) => $doc === null || $r['docTypeName'] === $doc ? (float) $r[$k] : 0, $rows));
            $forPay = $sum('forPay', 'Продажа') - $sum('forPay', 'Возврат');
            $list[] = ['reportId' => (int) date('Ymd', strtotime($w)), 'sellerFinanceName' => 'ИП Тестов', 'dateFrom' => $w, 'dateTo' => date('Y-m-d', strtotime($w) + 6 * 86400),
                'createDate' => date('Y-m-d', strtotime($w) + 7 * 86400), 'currency' => 'RUB', 'reportType' => 1,
                'retailAmountSum' => (string) round($sum('retailAmount', 'Продажа') - $sum('retailAmount', 'Возврат'), 2), 'forPaySum' => (string) round($forPay, 2),
                'avgSalePercent' => 20, 'deliveryServiceSum' => (string) $sum('deliveryService'), 'paidStorageSum' => (string) $sum('paidStorage'),
                'paidAcceptanceSum' => (string) $sum('paidAcceptance'), 'deductionSum' => '0', 'penaltySum' => (string) $sum('penalty'), 'additionalPaymentSum' => '0',
                'cashbackAmountSum' => '0', 'cashbackDiscountSum' => '0', 'cashbackCommissionChangeSum' => '0', 'paymentSchedule' => '',
                'bankPaymentSum' => (string) round($forPay - $sum('deliveryService') - $sum('paidStorage') - $sum('paidAcceptance') - $sum('penalty'), 2)];
        }
        if (!$list) {
            http_response_code(204);
            exit;
        }
        out($list);

    case '/adv/v1/upd':
        $list = [];
        for ($t = strtotime((string) $_GET['from']); $t <= strtotime((string) $_GET['to']); $t += 86400) {
            $list[] = ['updNum' => 0, 'updTime' => date('Y-m-d', $t) . 'T12:00:00+03:00', 'updSum' => 350 + date('j', $t) * 3, 'advertId' => 5001,
                'campName' => 'Платья — поиск', 'advertType' => 9, 'paymentType' => 'Баланс', 'advertStatus' => 9];
            if (date('N', $t) <= 5) {
                $list[] = ['updNum' => 0, 'updTime' => date('Y-m-d', $t) . 'T13:00:00+03:00', 'updSum' => 120, 'advertId' => 5002,
                    'campName' => 'Обувь и рюкзаки', 'advertType' => 8, 'paymentType' => 'Счёт', 'advertStatus' => 11];
            }
        }
        out($list);

    case '/api/advert/v2/adverts':
        $map = [5001 => [186452311], 5002 => [154422190, 120987345]];
        $adverts = [];
        foreach (explode(',', (string) ($_GET['ids'] ?? '')) as $id) {
            if (isset($map[(int) $id])) {
                $adverts[] = ['id' => (int) $id, 'settings' => ['name' => 'Кампания ' . $id], 'status' => 9,
                    'nm_settings' => array_map(static fn($nm) => ['nm_id' => $nm], $map[(int) $id])];
            }
        }
        out(['adverts' => $adverts]);

    case '/api/v1/paid_storage':
        out(['data' => ['taskId' => 'st-' . $_GET['dateFrom'] . '-' . $_GET['dateTo']]]);

    case '/ping':
        $service = ['8091' => 'analytics', '8092' => 'statistics', '8093' => 'marketplace', '8094' => 'content', '8095' => 'finance', '8096' => 'advert'][$_SERVER['SERVER_PORT']] ?? 'common';
        if ((str_contains($token, 'nocontent') && $service === 'content') || (str_contains($token, 'nofeed') && $service === 'analytics')
            || (str_contains($token, 'nofin') && in_array($service, ['finance', 'advert'], true))) {
            out(['title' => 'token scope not allowed'], 401);
        }
        out(['TS' => date('c'), 'Status' => 'OK']);

    case '/api/v1/seller-info':
        // Разные тестовые кабинеты: токен с «seller-N» — свой sid
        $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        $sid = preg_match('/seller-(\w+)/', $auth, $mm) ? substr(md5($mm[1]), 0, 8) . '-0000-4000-8000-' . substr(md5($mm[1]), 8, 12) : 'e8923014-e233-47q8-898e-3ad63ab4a8f7';
        out(['name' => 'ИП Тестов Тест Тестович', 'sid' => $sid, 'tin' => '7700000000', 'tradeMark' => 'Тестовый бренд', 'country' => 'RU']);

    case '/api/analytics/v1/order-feed':
        if (str_contains($token, 'nofeed')) {
            out(['title' => 'forbidden', 'detail' => 'token scope not allowed for this API route'], 403);
        }
        $start = strtotime($body['selectedPeriod']['start'] ?? '-1 day');
        if ($start < time() - 31 * 86400) {
            out(['title' => 'bad request', 'detail' => 'start must be within 31 days'], 400);
        }
        $all = array_filter(orders(72), static fn($o) => $o['updated'] >= $start);
        usort($all, static fn($a, $b) => $b['updated'] <=> $a['updated']);
        $offset = (int) ($body['pagination']['offset'] ?? 0);
        $limit = (int) ($body['pagination']['limit'] ?? 50);
        $page = array_slice($all, $offset, $limit);
        out(['data' => [
            'snapshotTime' => $body['pagination']['snapshotTime'] ?? gmdate('Y-m-d\TH:i:s\Z'),
            'currency' => 'RUB',
            'orders' => array_map(static fn($o) => [
                'nmId' => $o['product'][0], 'chrtId' => $o['product'][0] + 7, 'srid' => sprintf('%d.0.0', 7500000000 + $o['i']),
                'createdAt' => iso($o['t']), 'updatedAt' => iso($o['updated']), 'status' => $o['status'],
                'cancelType' => $o['status'] === 'cancel' ? 'app' : null,
                'warehouseName' => $o['isMp'] ? 'Мой склад Подольск' : 'Склад WB', 'warehouseRegion' => $o['isMp'] ? 'Центральный' : '',
                'isMp' => $o['isMp'], 'destinationCity' => $o['city'][0], 'destinationDistrict' => $o['city'][1],
                'sellerPrice' => $o['price'], 'isB2b' => $o['i'] % 13 === 0,
            ], $page),
        ]]);

    case '/api/v1/supplier/orders':
        $from = strtotime((string) ($_GET['dateFrom'] ?? '-1 day'));
        $rows = array_filter(orders(72), static fn($o) => $o['t'] < time() - 1800 && $o['t'] > $from);
        out(array_values(array_map(static fn($o) => [
            'date' => date('Y-m-d\TH:i:s', $o['t']), 'lastChangeDate' => date('Y-m-d\TH:i:s', $o['t'] + 1800),
            'warehouseName' => $o['isMp'] ? 'Мой склад Подольск' : $o['wh'], 'warehouseType' => $o['isMp'] ? 'Склад продавца' : 'Склад WB',
            'countryName' => 'Россия', 'oblastOkrugName' => $o['city'][1], 'regionName' => $o['city'][0],
            'supplierArticle' => $o['product'][1], 'nmId' => $o['product'][0], 'barcode' => '20' . $o['product'][0],
            'category' => $o['product'][5], 'subject' => $o['product'][4], 'brand' => $o['product'][3], 'techSize' => '0',
            'incomeID' => 0, 'isSupply' => false, 'isRealization' => true,
            'totalPrice' => round($o['price'] / 0.7), 'discountPercent' => 30, 'spp' => 18,
            'finishedPrice' => round($o['price'] * 0.82), 'priceWithDisc' => $o['price'],
            'isCancel' => $o['status'] === 'cancel', 'cancelDate' => $o['status'] === 'cancel' ? date('Y-m-d\TH:i:s', $o['updated']) : '0001-01-01T00:00:00',
            'sticker' => '', 'gNumber' => 'g' . $o['i'], 'srid' => sprintf('%d.0.0', 7500000000 + $o['i']),
        ], $rows)));

    case '/api/v1/supplier/sales':
        // Выкупы (S…) и возвраты (R…) — у возврата своя дата; возвращённый заказ раньше был выкуплен
        $flagDay = (int) ($_GET['flag'] ?? 0) === 1;
        $from = strtotime((string) ($_GET['dateFrom'] ?? '-1 day'));
        $list = [];
        foreach (orders(72) as $o) {
            $events = [];
            if ($o['status'] === 'buyout' || $o['status'] === 'return') {
                $events[] = ['S', $o['t'] + 3 * 3600];
            }
            if ($o['status'] === 'return') {
                $events[] = ['R', $o['updated']];
            }
            foreach ($events as [$type, $ts]) {
                if ($ts > time() - 1800 || ($flagDay ? date('Y-m-d', $ts) !== date('Y-m-d', $from) : $ts + 1800 <= $from)) {
                    continue;
                }
                $list[] = ['date' => date('Y-m-d\TH:i:s', $ts), 'lastChangeDate' => date('Y-m-d\TH:i:s', $ts + 1800), 'saleID' => $type . (900000 + $o['i']),
                    'srid' => sprintf('%d.0.0', 7500000000 + $o['i']), 'nmId' => $o['product'][0], 'priceWithDisc' => $type === 'R' ? -$o['price'] : $o['price'],
                    'finishedPrice' => round($o['price'] * 0.82), 'forPay' => round($o['price'] * 0.7), 'warehouseType' => $o['isMp'] ? 'Склад продавца' : 'Склад WB'];
            }
        }
        out($list);

    case '/api/v3/orders/new':
    case '/api/v3/dbs/orders/new':
        $scheme = $path === '/api/v3/orders/new' ? 'FBS' : 'DBS';
        $recent = array_filter(orders(1), static fn($o) => $o['scheme'] === $scheme && $o['status'] === 'created');
        out(['orders' => array_values(array_map(static fn($o) => [
            'id' => 4000000 + $o['i'], 'rid' => sprintf('%d.0.0', 7500000000 + $o['i']), 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $o['t']),
            'nmId' => $o['product'][0], 'chrtId' => $o['product'][0] + 7, 'article' => $o['product'][1], 'skus' => ['20' . $o['product'][0]],
            'price' => $o['price'] * 100, 'salePrice' => $o['price'] * 100, 'warehouseId' => 1, 'deliveryType' => strtolower($scheme),
            'cargoType' => 1, 'options' => ['isB2B' => false],
        ], $recent))]);

    case '/api/v3/dbw/orders/new':
        out(['title' => 'not found'], 404);

    case '/api/v3/click-collect/orders/new':
        out(['orders' => []]);

    case '/api/v1/tariffs/commission':
        $subj = [['Платья', 101, 'Одежда', 25.5, 27.5], ['Кроссовки', 102, 'Обувь', 24.5, 26.5], ['Наборы посуды', 103, 'Дом', 19.5, 21.5],
                 ['Рюкзаки', 104, 'Аксессуары', 23.0, 25.0], ['Футболки', 105, 'Одежда', 25.5, 27.5]];
        out(['report' => array_map(static fn($x) => ['subjectName' => $x[0], 'subjectID' => $x[1], 'parentName' => $x[2], 'parentID' => 1,
            'paidStorageKgvp' => $x[3], 'kgvpMarketplace' => $x[4], 'kgvpSupplier' => $x[4] - 3, 'kgvpSupplierExpress' => 3, 'kgvpPickup' => $x[4] - 4, 'kgvpBooking' => $x[4] - 4], $subj)]);

    case '/api/v1/tariffs/box':
        out(['response' => ['data' => ['dtNextBox' => '', 'dtTillMax' => '2026-12-31', 'warehouseList' => [
            ['warehouseName' => 'Коледино', 'geoName' => 'Центральный федеральный округ', 'boxDeliveryBase' => '78,2', 'boxDeliveryLiter' => '23,8', 'boxDeliveryCoefExpr' => '170',
             'boxDeliveryMarketplaceBase' => '78,2', 'boxDeliveryMarketplaceLiter' => '23,8', 'boxDeliveryMarketplaceCoefExpr' => '170', 'boxStorageBase' => '0,14', 'boxStorageLiter' => '0,14', 'boxStorageCoefExpr' => '100'],
            ['warehouseName' => 'Электросталь', 'geoName' => 'Центральный федеральный округ', 'boxDeliveryBase' => '82,8', 'boxDeliveryLiter' => '25,2', 'boxDeliveryCoefExpr' => '180',
             'boxDeliveryMarketplaceBase' => '82,8', 'boxDeliveryMarketplaceLiter' => '25,2', 'boxDeliveryMarketplaceCoefExpr' => '180', 'boxStorageBase' => '0,14', 'boxStorageLiter' => '0,14', 'boxStorageCoefExpr' => '100'],
        ]]]]);

    case '/adv/v1/payments':
        // Пополнения счёта продвижения: картой (учитывается в рекламе) и с баланса (уже удержано в отчёте)
        out([['id' => 9001, 'date' => date('Y-m-d', strtotime('monday last week') + 86400) . 'T10:00:00', 'sum' => 1500, 'type' => 3, 'statusId' => 1, 'cardStatus' => 'success', 'currency' => 'RUB'],
            ['id' => 9002, 'date' => date('Y-m-d', strtotime('monday last week') + 2 * 86400) . 'T10:00:00', 'sum' => 5000, 'type' => 1, 'statusId' => 1, 'cardStatus' => '', 'currency' => 'RUB']]);

    case '/api/v1/warehouse_remains':
        out(['data' => ['taskId' => 'task-' . date('His')]]);

    default:
        if (preg_match('#^/api/finance/v1/sales-reports/detailed/(\d+)$#', $path, $m)) {
            $rows = array_values(array_filter(reportRows(date('Y-m-d', strtotime((string) $m[1]))), static fn($r) => $r['rrdId'] > (int) ($body['rrdId'] ?? 0)));
            $rows = array_slice($rows, 0, (int) ($body['limit'] ?? 100000));
            if (!$rows) {
                http_response_code(204);
                exit;
            }
            out($rows);
        }
        if (preg_match('#^/api/v1/paid_storage/tasks/[^/]+/status$#', $path)) {
            out(['data' => ['id' => 'task', 'status' => 'done']]);
        }
        if (preg_match('#^/api/v1/paid_storage/tasks/st-(\d{4}-\d\d-\d\d)-(\d{4}-\d\d-\d\d)/download$#', $path, $m)) {
            $list = [];
            for ($t = strtotime($m[1]); $t <= strtotime($m[2]); $t += 86400) {
                foreach ($products as $k => $p) {
                    foreach (['Коледино', 'Электросталь'] as $wh) {
                        $list[] = ['date' => date('Y-m-d', $t), 'warehouse' => $wh, 'warehouseCoef' => 1.7, 'nmId' => $p[0], 'vendorCode' => $p[1], 'subject' => $p[4],
                            'brand' => $p[3], 'barcode' => '20' . $p[0], 'size' => '0', 'volume' => 2.5 + $k, 'calcType' => 'короба: без габаритов',
                            'warehousePrice' => round(3.2 + $k * 1.7, 2), 'barcodesCount' => 20 + $k * 5];
                    }
                }
            }
            out($list);
        }
        if (preg_match('#^/api/v1/warehouse_remains/tasks/[^/]+/status$#', $path)) {
            out(['data' => ['id' => 'task', 'status' => 'done']]);
        }
        if (preg_match('#^/api/v1/warehouse_remains/tasks/[^/]+/download$#', $path)) {
            $vol = [186452311 => 1.8, 154422190 => 6.47, 99814522 => 12.0, 120987345 => 4.37, 173300812 => 0.55];
            out(array_map(static fn($p) => ['brand' => $p[3], 'subjectName' => $p[4], 'vendorCode' => $p[1], 'nmId' => $p[0], 'barcode' => '', 'techSize' => '0',
                'volume' => $vol[$p[0]], 'warehouses' => [
                    ['warehouseName' => 'Коледино', 'quantity' => 40 + $p[0] % 30],
                    ['warehouseName' => 'Электросталь', 'quantity' => 10 + $p[0] % 7],
                    ['warehouseName' => 'В пути до получателей', 'quantity' => 5],
                    ['warehouseName' => 'В пути возвраты на склад WB', 'quantity' => 2],
                    ['warehouseName' => 'Всего находится на складах', 'quantity' => 50 + $p[0] % 30 + $p[0] % 7],
                ]], $products));
        }
        break;

    case '/content/v2/get/cards/list':
        out(['cards' => array_map(static fn($p) => [
            'nmID' => $p[0], 'vendorCode' => $p[1], 'title' => $p[2], 'brand' => $p[3], 'subjectName' => $p[4],
            'subjectID' => ['Платья' => 101, 'Кроссовки' => 102, 'Наборы посуды' => 103, 'Рюкзаки' => 104, 'Футболки' => 105][$p[4]],
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 5, 'weightBrutto' => 0.5, 'isValid' => true],
            'photos' => [['c246x328' => 'http://127.0.0.1:8090/img/' . $p[0] . '.svg', 'c516x688' => 'http://127.0.0.1:8090/img/' . $p[0] . '.svg']],
        ], $products), 'cursor' => ['updatedAt' => date('c'), 'nmID' => end($products)[0], 'total' => count($products)]]);
}

out(['title' => 'not found', 'detail' => $path], 404);
