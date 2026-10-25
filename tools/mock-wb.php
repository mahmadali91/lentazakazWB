<?php
/**
 * Мок WB API для локальной разработки и тестов (форматы ответов — по спецификациям dev.wildberries.ru).
 *
 * Запуск:  php -S 127.0.0.1:8090 tools/mock-wb.php
 * Можно поднять один порт на все сервисы или по порту на сервис (тогда ping различает категории):
 *   8090 common, 8091 analytics, 8092 statistics, 8093 marketplace, 8094 content.
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

switch ($path) {
    case '/ping':
        $service = ['8091' => 'analytics', '8092' => 'statistics', '8093' => 'marketplace', '8094' => 'content'][$_SERVER['SERVER_PORT']] ?? 'common';
        if ((str_contains($token, 'nocontent') && $service === 'content') || (str_contains($token, 'nofeed') && $service === 'analytics')) {
            out(['title' => 'token scope not allowed'], 401);
        }
        out(['TS' => date('c'), 'Status' => 'OK']);

    case '/api/v1/seller-info':
        out(['name' => 'ИП Тестов Тест Тестович', 'sid' => 'e8923014-e233-47q8-898e-3ad63ab4a8f7', 'tin' => '7700000000', 'tradeMark' => 'Тестовый бренд', 'country' => 'RU']);

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

    case '/content/v2/get/cards/list':
        out(['cards' => array_map(static fn($p) => [
            'nmID' => $p[0], 'vendorCode' => $p[1], 'title' => $p[2], 'brand' => $p[3], 'subjectName' => $p[4],
            'photos' => [['c246x328' => 'https://placehold.co/246x328/png?text=' . urlencode($p[1])]],
        ], $products), 'cursor' => ['updatedAt' => date('c'), 'nmID' => end($products)[0], 'total' => count($products)]]);
}

out(['title' => 'not found', 'detail' => $path], 404);
