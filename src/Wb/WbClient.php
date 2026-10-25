<?php
declare(strict_types=1);

namespace App\Wb;

use App\Core\Config;

/**
 * Клиент WB API. Описание методов — docs/WB_API.md и https://dev.wildberries.ru/openapi
 *
 * Токен передаётся в заголовке Authorization. Категория токена должна совпадать с сервисом:
 *  - Аналитика  → seller-analytics-api (Лента заказов, реальное время, все схемы)
 *  - Статистика → statistics-api (заказы с брендом/категорией/складом, обновление раз в 30 мин)
 *  - Маркетплейс → marketplace-api (новые сборочные задания FBS / DBS / DBW / Самовывоз)
 *  - Контент    → content-api (названия и фото товаров)
 *  - Финансы    → finance-api (еженедельные отчёты реализации и их детализация)
 *  - Продвижение → advert-api (расходы на рекламу)
 */
final class WbClient
{
    /** Домены по умолчанию — для сервисов, которых нет в config.php, созданном старой версией установщика. */
    private const DEFAULT_HOSTS = [
        'common'      => 'https://common-api.wildberries.ru',
        'analytics'   => 'https://seller-analytics-api.wildberries.ru',
        'statistics'  => 'https://statistics-api.wildberries.ru',
        'marketplace' => 'https://marketplace-api.wildberries.ru',
        'content'     => 'https://content-api.wildberries.ru',
        'finance'     => 'https://finance-api.wildberries.ru',
        'advert'      => 'https://advert-api.wildberries.ru',
    ];

    public function __construct(private readonly string $token)
    {
    }

    private function host(string $service): string
    {
        $host = Config::get('wb.hosts.' . $service) ?: (self::DEFAULT_HOSTS[$service] ?? null);
        if (!$host) {
            throw new \InvalidArgumentException("Неизвестный сервис WB: $service");
        }
        return rtrim((string) $host, '/');
    }

    /**
     * @return array<mixed>
     */
    public function request(string $service, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->host($service) . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $headers = [
            'Authorization: ' . $this->token,
            'Accept: application/json',
            'User-Agent: LentaZakazWB/' . APP_VERSION,
        ];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => (int) Config::get('wb.timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADER         => true,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new WbException($err, 0);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $content = substr($raw, $headerSize);
        $data = $content === '' ? [] : json_decode($content, true);

        if ($status >= 400) {
            $retry = 0;
            if (preg_match('/^X-Ratelimit-Retry:\s*(\d+)/mi', $rawHeaders, $m)) {
                $retry = (int) $m[1];
            } elseif (preg_match('/^Retry-After:\s*(\d+)/mi', $rawHeaders, $m)) {
                $retry = (int) $m[1];
            }
            $msg = is_array($data)
                ? (string) ($data['detail'] ?? $data['message'] ?? $data['title'] ?? $data['errorText'] ?? '')
                : '';
            throw new WbException($msg !== '' ? $msg : mb_substr(strip_tags($content), 0, 200), $status, $retry);
        }
        if (!is_array($data)) {
            throw new WbException('Некорректный ответ WB API', $status);
        }
        return $data;
    }

    /** Проверка токена на конкретном сервисе (GET /ping). true — категория токена подходит. */
    public function ping(string $service): bool
    {
        try {
            $this->request($service, 'GET', '/ping');
            return true;
        } catch (WbException $e) {
            if ($e->isAuthError()) {
                return false;
            }
            throw $e;
        }
    }

    /** Информация о продавце: name, sid, tin, tradeMark. Доступна с токеном любой категории. */
    public function sellerInfo(): array
    {
        return $this->request('common', 'GET', '/api/v1/seller-info');
    }

    /**
     * Лента заказов (Аналитика): POST /api/analytics/v1/order-feed
     * Реальное время, все схемы (isMp: true — склад продавца, false — склад WB), максимум 31 день.
     * Лимит: 1 запрос в минуту (для «Базового» токена — 1 запрос в 3 часа).
     */
    public function orderFeed(string $startIso, int $offset = 0, int $limit = 5000, ?string $snapshotTime = null): array
    {
        $pagination = ['offset' => $offset, 'limit' => $limit];
        if ($snapshotTime !== null && $offset > 0) {
            $pagination['snapshotTime'] = $snapshotTime;
        }
        $resp = $this->request('analytics', 'POST', '/api/analytics/v1/order-feed', [], [
            'selectedPeriod' => ['start' => $startIso],
            'nmIds'          => [],
            'subjectIds'     => [],
            'brandNames'     => [],
            'tagIds'         => [],
            'pagination'     => $pagination,
        ]);
        return $resp['data'] ?? ['orders' => [], 'snapshotTime' => null];
    }

    /**
     * Заказы (Статистика): GET /api/v1/supplier/orders?dateFrom=...
     * Обновление раз в 30 минут, до 80 000 строк. Даёт бренд, предмет, категорию, реальный склад WB.
     */
    public function supplierOrders(string $dateFrom): array
    {
        return $this->request('statistics', 'GET', '/api/v1/supplier/orders', ['dateFrom' => $dateFrom, 'flag' => 0]);
    }

    /** Продажи и возвраты (Статистика) начиная с dateFrom по lastChangeDate; до 80 000 строк, 1 запрос в минуту. */
    public function supplierSales(string $dateFrom): array
    {
        return $this->request('statistics', 'GET', '/api/v1/supplier/sales', ['dateFrom' => $dateFrom, 'flag' => 0]);
    }

    /** Заказы за один день (flag=1 — все заказы с датой dateFrom), для сверки с кабинетом WB. */
    public function supplierOrdersDay(string $date): array
    {
        return $this->request('statistics', 'GET', '/api/v1/supplier/orders', ['dateFrom' => $date, 'flag' => 1]);
    }

    /** Продажи (выкупы, saleID «S…») и возвраты («R…») за один день — как «Выкупили» в кабинете WB. */
    public function supplierSalesDay(string $date): array
    {
        return $this->request('statistics', 'GET', '/api/v1/supplier/sales', ['dateFrom' => $date, 'flag' => 1]);
    }

    /** Новые сборочные задания: FBS, DBS, DBW, Самовывоз (Click & Collect). */
    public function newAssemblyOrders(string $scheme): array
    {
        $path = match ($scheme) {
            'FBS' => '/api/v3/orders/new',
            'DBS' => '/api/v3/dbs/orders/new',
            'DBW' => '/api/v3/dbw/orders/new',
            'CC'  => '/api/v3/click-collect/orders/new',
            default => throw new \InvalidArgumentException("Неизвестная схема $scheme"),
        };
        $resp = $this->request('marketplace', 'GET', $path);
        return $resp['orders'] ?? [];
    }

    /** Карточки товаров (Контент): POST /content/v2/get/cards/list с курсорной пагинацией. */
    public function cardsList(int $limit = 100, ?array $cursor = null): array
    {
        $c = ['limit' => $limit];
        if ($cursor && !empty($cursor['updatedAt']) && !empty($cursor['nmID'])) {
            $c['updatedAt'] = $cursor['updatedAt'];
            $c['nmID'] = $cursor['nmID'];
        }
        return $this->request('content', 'POST', '/content/v2/get/cards/list', [], [
            'settings' => [
                'cursor' => $c,
                'filter' => ['withPhoto' => -1],
            ],
        ]);
    }

    /**
     * Комиссия WB по предметам (Тарифы): GET /api/v1/tariffs/commission.
     * Возвращает ставки этого продавца — у продавцов из разных стран (РФ, Кыргызстан и др.) они свои.
     * Поля: paidStorageKgvp — FBO (склад WB), kgvpMarketplace — FBS, kgvpSupplier — DBS/DBW,
     * kgvpSupplierExpress — EDBS (экспресс), kgvpPickup — самовывоз, kgvpBooking — бронирование.
     */
    public function commissions(): array
    {
        $resp = $this->request('common', 'GET', '/api/v1/tariffs/commission', ['locale' => 'ru']);
        return $resp['report'] ?? [];
    }

    /**
     * Тарифы для коробов по складам (Тарифы): GET /api/v1/tariffs/box?date=ГГГГ-ММ-ДД.
     * boxDeliveryBase / boxDeliveryLiter — логистика за первый / доп. литр с уже учтённым коэффициентом склада
     * boxDeliveryCoefExpr, %. Числа приходят строками с запятой.
     */
    public function boxTariffs(string $date): array
    {
        $resp = $this->request('common', 'GET', '/api/v1/tariffs/box', ['date' => $date]);
        return $resp['response']['data']['warehouseList'] ?? [];
    }

    /** Отчёт «Остатки на складах WB»: создать задание (объём товара в литрах + остатки по складам). */
    public function remainsCreate(): string
    {
        $resp = $this->request('analytics', 'GET', '/api/v1/warehouse_remains', ['locale' => 'ru', 'groupByNm' => 'true']);
        return (string) ($resp['data']['taskId'] ?? '');
    }

    public function remainsStatus(string $taskId): string
    {
        $resp = $this->request('analytics', 'GET', '/api/v1/warehouse_remains/tasks/' . rawurlencode($taskId) . '/status');
        return (string) ($resp['data']['status'] ?? '');
    }

    public function remainsDownload(string $taskId): array
    {
        return $this->request('analytics', 'GET', '/api/v1/warehouse_remains/tasks/' . rawurlencode($taskId) . '/download');
    }

    // ------------------------------------------------------------------ Финансы

    /**
     * Список отчётов реализации (Финансы): POST /api/finance/v1/sales-reports/list.
     * Данные с 29.01.2024, лимит 1 запрос в минуту, только Персональный или Сервисный токен.
     * Суммы приходят строками: retailAmountSum, forPaySum, deliveryServiceSum, paidStorageSum, bankPaymentSum…
     */
    public function salesReports(string $dateFrom, string $dateTo, int $offset = 0): array
    {
        return $this->request('finance', 'POST', '/api/finance/v1/sales-reports/list', [], [
            'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'limit' => 1000, 'offset' => $offset, 'period' => 'weekly',
        ]);
    }

    /**
     * Детализация отчёта реализации: POST /api/finance/v1/sales-reports/detailed/{reportId}.
     * Порциями до 100 000 строк: следующая порция — с rrdId последней строки; пустой ответ (204) — строк больше нет.
     */
    public function salesReportRows(int $reportId, int $rrdId, int $limit = 100000): array
    {
        return $this->request('finance', 'POST', '/api/finance/v1/sales-reports/detailed/' . $reportId, [], [
            'limit' => $limit, 'rrdId' => $rrdId,
        ]);
    }

    /** Отчёт о платном хранении (Аналитика): создать задание за период до 8 дней. */
    public function paidStorageCreate(string $dateFrom, string $dateTo): string
    {
        $resp = $this->request('analytics', 'GET', '/api/v1/paid_storage', ['dateFrom' => $dateFrom, 'dateTo' => $dateTo]);
        return (string) ($resp['data']['taskId'] ?? '');
    }

    public function paidStorageStatus(string $taskId): string
    {
        $resp = $this->request('analytics', 'GET', '/api/v1/paid_storage/tasks/' . rawurlencode($taskId) . '/status');
        return (string) ($resp['data']['status'] ?? '');
    }

    /** Строки: date, nmId, vendorCode, subject, warehouse, volume, barcodesCount, warehousePrice (₽ за день). */
    public function paidStorageDownload(string $taskId): array
    {
        return $this->request('analytics', 'GET', '/api/v1/paid_storage/tasks/' . rawurlencode($taskId) . '/download');
    }

    /** Списания за рекламу (Продвижение): GET /adv/v1/upd?from&to, период до 31 дня. */
    public function adCosts(string $from, string $to): array
    {
        return $this->request('advert', 'GET', '/adv/v1/upd', ['from' => $from, 'to' => $to]);
    }

    /** Кампании с их товарами: GET /api/advert/v2/adverts?ids= (до 50 ID). */
    public function adverts(array $ids): array
    {
        $resp = $this->request('advert', 'GET', '/api/advert/v2/adverts', ['ids' => implode(',', array_map('intval', $ids))]);
        return $resp['adverts'] ?? [];
    }

    /** Срок действия токена из его JWT-части (exp). null — не удалось определить. */
    public static function tokenExpiresAt(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($payload) || empty($payload['exp'])) {
            return null;
        }
        return date('Y-m-d H:i:s', (int) $payload['exp']);
    }
}
