<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\DB;
use App\Wb\WbClient;
use App\Wb\WbException;

final class StoreService
{
    /** Понятные названия категорий токена WB. */
    public const SCOPE_LABELS = [
        'analytics'   => 'Аналитика — лента заказов в реальном времени (все схемы)',
        'marketplace' => 'Маркетплейс — новые сборочные задания FBS / DBS / DBW / Самовывоз',
        'statistics'  => 'Статистика — бренд, предмет, склад WB, цены',
        'content'     => 'Контент — названия и фото товаров',
        'finance'     => 'Финансы — отчёты реализации для расчёта чистой прибыли',
        'advert'      => 'Продвижение — расходы на рекламу по товарам',
    ];

    /**
     * Проверяет токен на всех нужных сервисах WB.
     * @return array{scopes: array, seller: array, expires_at: ?string}
     */
    public static function inspectToken(string $token): array
    {
        $token = trim($token);
        if (strlen($token) < 20) {
            throw new \InvalidArgumentException('Похоже, это не токен WB API. Скопируйте токен целиком.');
        }
        $wb = new WbClient($token);
        try {
            $seller = $wb->sellerInfo();
        } catch (WbException $e) {
            if ($e->status === 401) {
                throw new \InvalidArgumentException('WB отклонил токен (401). Проверьте, что токен скопирован полностью и не отозван.');
            }
            throw new \InvalidArgumentException('Не удалось проверить токен: ' . $e->humanMessage());
        }
        $scopes = [];
        foreach (array_keys(self::SCOPE_LABELS) as $service) {
            try {
                $scopes[$service] = $wb->ping($service);
            } catch (WbException) {
                $scopes[$service] = false;
            }
        }
        if (empty($scopes['analytics']) && empty($scopes['statistics'])) {
            throw new \InvalidArgumentException('У токена нет категорий «Аналитика» или «Статистика» — без них заказы получить нельзя. Создайте токен с этими категориями.');
        }
        return [
            'scopes'     => $scopes,
            'seller'     => $seller,
            'expires_at' => WbClient::tokenExpiresAt($token),
        ];
    }

    /** Налог, НДС и фиксированные расходы в неделю из формы магазина. */
    public static function financeInput(array $in): array
    {
        $num = static function ($v, float $max): ?float {
            $v = str_replace([',', ' ', "\u{A0}"], ['.', '', ''], trim((string) $v));
            return $v === '' || !is_numeric($v) ? null : round(max(0.0, min($max, (float) $v)), 2);
        };
        return ['tax_rate' => $num($in['tax_rate'] ?? '', 100) ?? 6.0, 'vat_rate' => $num($in['vat_rate'] ?? '', 100) ?? 0.0,
            'weekly_expense' => $num($in['weekly_expense'] ?? '', 100000000)];
    }

    /**
     * Один кабинет WB подключается в сервис только один раз. Если он уже подключён — сообщаем, к какому кабинету сервиса (ID клиента),
     * и предупреждаем владельца этого кабинета в Telegram.
     */
    private static function ensureUnique(int $userId, array $seller, ?int $exceptStoreId = null): void
    {
        $sid = trim((string) ($seller['sid'] ?? ''));
        if ($sid === '') {
            return;
        }
        $dup = DB::one('SELECT s.id, s.name, s.user_id, u.tg_chat_id FROM stores s JOIN users u ON u.id = s.user_id WHERE s.seller_sid = ? AND s.id <> ? LIMIT 1',
            [$sid, (int) $exceptStoreId]);
        if (!$dup) {
            return;
        }
        if ((int) $dup['user_id'] === $userId) {
            if ($exceptStoreId !== null) {
                return; // замена токена у магазина, который уже есть в этом же кабинете
            }
            throw new \InvalidArgumentException('Этот кабинет WB уже подключён в вашем кабинете — магазин «' . $dup['name'] . '».');
        }
        log_message('stores', 'повторное подключение кабинета WB ' . $sid . ' (' . ($seller['name'] ?? '') . '): клиент ' . client_code($userId)
            . ', уже подключён у клиента ' . client_code((int) $dup['user_id']) . ', магазин ' . $dup['id']);
        if ($dup['tg_chat_id'] && Telegram::enabled()) {
            Telegram::send($dup['tg_chat_id'], '⚠️ Кто-то попытался подключить ваш кабинет WB «' . e((string) ($seller['name'] ?? $dup['name'])) . '» в другой аккаунт сервиса (ID клиента '
                . client_code($userId) . '). Подключение отклонено. Если это не вы — перевыпустите токен WB.');
        }
        throw new \InvalidArgumentException('Этот кабинет WB уже подключён в сервисе к кабинету клиента ID ' . client_code((int) $dup['user_id'])
            . '. Один кабинет WB можно подключить только один раз. Если это ваш кабинет — напишите в поддержку.');
    }

    public static function create(int $userId, string $name, string $token, array $finance = []): int
    {
        $info = self::inspectToken($token);
        $seller = $info['seller'];
        self::ensureUnique($userId, $seller);
        $token = trim($token);
        return DB::insert('stores', [
            'user_id'          => $userId,
            'name'             => $name !== '' ? mb_substr($name, 0, 120) : mb_substr((string) ($seller['tradeMark'] ?? $seller['name'] ?? 'Магазин'), 0, 120),
            'seller_name'      => mb_substr((string) ($seller['name'] ?? ''), 0, 255),
            'seller_sid'       => (string) ($seller['sid'] ?? ''),
            'seller_tin'       => (string) ($seller['tin'] ?? ''),
            'seller_country'   => mb_substr((string) ($seller['country'] ?? ''), 0, 8),
            'token_enc'        => Crypto::encrypt($token),
            'token_hint'       => '…' . substr($token, -6),
            'token_expires_at' => $info['expires_at'],
            'scopes'           => json_encode($info['scopes']),
            'created_at'       => now(),
        ] + ($finance ?: self::financeInput([])));
    }

    public static function updateToken(array $store, string $token): void
    {
        $info = self::inspectToken($token);
        // Новый токен — от того же кабинета WB или от кабинета, ещё не подключённого в сервисе
        self::ensureUnique((int) $store['user_id'], $info['seller'], (int) $store['id']);
        $token = trim($token);
        DB::update('stores', [
            'token_enc'        => Crypto::encrypt($token),
            'token_hint'       => '…' . substr($token, -6),
            'token_expires_at' => $info['expires_at'],
            'scopes'           => json_encode($info['scopes']),
            'seller_name'      => mb_substr((string) ($info['seller']['name'] ?? $store['seller_name']), 0, 255),
            'seller_sid'       => (string) (($info['seller']['sid'] ?? '') ?: $store['seller_sid']),
            'last_error'       => null,
            'last_error_at'    => null,
            'feed_next_at'     => null,
            'mp_next_at'       => null,
            'stats_next_at'    => null,
            'cards_next_at'    => null,
            'tariffs_next_at'  => null,
            'remains_next_at'  => null,
            'sales_next_at'    => null,
            'remains_state'    => null,
            'fin_next_at'      => null,
            'storage_next_at'  => null,
            'ads_next_at'      => null,
        ], 'id = :id', ['id' => $store['id']]);
    }

    public static function forUser(int $userId): array
    {
        return DB::all('SELECT * FROM stores WHERE user_id = ? ORDER BY id', [$userId]);
    }

    public static function findForUser(int $userId, int $storeId): ?array
    {
        return DB::one('SELECT * FROM stores WHERE id = ? AND user_id = ?', [$storeId, $userId]);
    }

    /**
     * Магазины, к которым у сотрудника ограничен доступ (клиент менеджера видит только свои). null — все магазины кабинета.
     * @return int[]|null
     */
    public static function memberAccess(?array $user): ?array
    {
        if (!$user || empty($user['owner_id']) || $user['store_access'] === null || $user['store_access'] === '') {
            return null;
        }
        return array_values(array_map('intval', json_decode((string) $user['store_access'], true) ?: []));
    }

    /** Магазины кабинета, которые видит текущий пользователь: по тарифу и, для сотрудника, по выданному доступу. */
    public static function visibleIds(int $accountId, array $plan, ?array $user = null): array
    {
        $ids = self::activeIds($accountId, $plan);
        $access = self::memberAccess($user ?? (PHP_SAPI === 'cli' ? null : \App\Core\Auth::user()));
        return $access === null ? $ids : array_values(array_intersect($ids, $access));
    }

    /** Магазины, доступные пользователю по тарифу (лишние — на паузе). */
    public static function activeIds(int $userId, array $plan): array
    {
        $ids = DB::all('SELECT id FROM stores WHERE user_id = ? AND is_active = 1 ORDER BY id LIMIT ' . (int) $plan['stores'], [$userId]);
        return array_map(static fn($r) => (int) $r['id'], $ids);
    }
}
