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

    public static function create(int $userId, string $name, string $token): int
    {
        $info = self::inspectToken($token);
        $seller = $info['seller'];
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
        ]);
    }

    public static function updateToken(array $store, string $token): void
    {
        $info = self::inspectToken($token);
        $token = trim($token);
        DB::update('stores', [
            'token_enc'        => Crypto::encrypt($token),
            'token_hint'       => '…' . substr($token, -6),
            'token_expires_at' => $info['expires_at'],
            'scopes'           => json_encode($info['scopes']),
            'seller_name'      => mb_substr((string) ($info['seller']['name'] ?? $store['seller_name']), 0, 255),
            'last_error'       => null,
            'last_error_at'    => null,
            'feed_next_at'     => null,
            'mp_next_at'       => null,
            'stats_next_at'    => null,
            'cards_next_at'    => null,
            'tariffs_next_at'  => null,
            'remains_next_at'  => null,
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

    /** Магазины, доступные пользователю по тарифу (лишние — на паузе). */
    public static function activeIds(int $userId, array $plan): array
    {
        $ids = DB::all('SELECT id FROM stores WHERE user_id = ? AND is_active = 1 ORDER BY id LIMIT ' . (int) $plan['stores'], [$userId]);
        return array_map(static fn($r) => (int) $r['id'], $ids);
    }
}
