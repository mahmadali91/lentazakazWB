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
 */
final class WbClient
{
    public function __construct(private readonly string $token)
    {
    }

    private function host(string $service): string
    {
        $host = Config::get('wb.hosts.' . $service);
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
