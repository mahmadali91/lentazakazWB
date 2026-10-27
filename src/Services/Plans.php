<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class Plans
{
    /** Скидка за оплату на несколько месяцев. */
    public const PERIODS = [1 => 0, 3 => 10, 6 => 15, 12 => 20];

    public static function all(): array
    {
        return (array) config('plans', []);
    }

    public static function paid(): array
    {
        return array_filter(self::all(), static fn($p) => ($p['price'] ?? 0) > 0 && ($p['public'] ?? true));
    }

    public static function get(string $code): array
    {
        $plans = self::all();
        $plan = $plans[$code] ?? $plans['free'] ?? reset($plans);
        $plan['code'] = isset($plans[$code]) ? $code : 'free';
        return $plan;
    }

    /** Тариф супер-админа (владелец сервиса): всё включено, без ограничений и бесплатно. */
    public static function super(): array
    {
        $plan = self::get('business');
        return ['code' => 'super', 'name' => 'Супер-админ', 'tagline' => 'Владелец сервиса: всё бесплатно и без ограничений', 'price' => 0,
            'stores' => 9999, 'team' => 9999, 'store_price' => 0, 'stores_max' => 9999, 'extra_stores' => 0, 'history_days' => $plan['history_days'],
            'sync_minutes' => $plan['sync_minutes'], 'telegram' => true, 'export' => true, 'profit' => true, 'finance' => true, 'public' => false,
            'features' => [], 'expires_at' => null, 'expired_code' => null, 'super' => true];
    }

    /** Действующий тариф пользователя (истёкший → бесплатный). */
    public static function forUser(array $user): array
    {
        if (!empty($user['is_super'])) {
            return self::super();
        }
        $code = (string) $user['plan_code'];
        $expires = $user['plan_expires_at'] ?? null;
        $active = $code !== 'free' && $expires !== null && strtotime((string) $expires) > time();
        $plan = self::get($active ? $code : 'free');
        // Докупленные магазины действуют, пока действует тариф
        $plan['extra_stores'] = $active && !empty($plan['store_price']) ? min((int) ($user['extra_stores'] ?? 0), self::maxExtra($plan)) : 0;
        $plan['stores'] = (int) $plan['stores'] + $plan['extra_stores'];
        $plan['expires_at'] = $active ? $expires : null;
        $plan['expired_code'] = (!$active && $code !== 'free') ? $code : null;
        return $plan;
    }

    /** Названия публичных тарифов, где есть возможность: «A» и «B» — для подсказок «доступно на тарифах…». */
    public static function namesWith(string $feature): string
    {
        $names = [];
        foreach (self::all() as $p) {
            $on = $feature === 'finance' ? self::finance($p) : !empty($p[$feature]);
            if ($on && ($p['public'] ?? true) && ($p['price'] ?? 0) > 0) {
                $names[] = '«' . $p['name'] . '»';
            }
        }
        $last = array_pop($names);
        return $names ? implode(', ', $names) . ' и ' . $last : (string) $last;
    }

    /** Доступен ли раздел «Финансы» (отчёты реализации WB). */
    public static function finance(array $plan): bool
    {
        return (bool) ($plan['finance'] ?? $plan['profit'] ?? false);
    }

    /** Цена тарифа за срок (с доп. магазинами, если тариф их допускает). */
    public static function price(string $code, int $months, int $extra = 0): float
    {
        $plan = self::get($code);
        $base = (float) ($plan['price'] ?? 0) + min(max(0, $extra), self::maxExtra($plan)) * (float) ($plan['store_price'] ?? 0);
        $discount = self::PERIODS[$months] ?? 0;
        return round($base * $months * (100 - $discount) / 100);
    }

    /** Сколько магазинов можно докупить к тарифу сверх включённых. */
    public static function maxExtra(array $plan): int
    {
        return empty($plan['store_price']) ? 0 : max(0, (int) ($plan['stores_max'] ?? 0) - (int) (self::all()[$plan['code'] ?? '']['stores'] ?? $plan['stores'] ?? 0));
    }

    /**
     * Доплата за $qty доп. магазинов до конца действующей подписки: цена магазина в месяц × оставшиеся дни / 30.
     * null — докупить нельзя (тариф без докупки, истёк или лимит).
     */
    public static function addonPrice(array $user, int $qty): ?float
    {
        $plan = self::forUser($user);
        if ($qty < 1 || empty($plan['store_price']) || !$plan['expires_at'] || $plan['extra_stores'] + $qty > self::maxExtra($plan)) {
            return null;
        }
        return max(1.0, ceil((float) $plan['store_price'] * $qty * self::daysLeft($plan) / 30));
    }

    /** Полных дней до конца подписки (неполный день — как целый). */
    public static function daysLeft(array $plan): int
    {
        return $plan['expires_at'] ? max(1, (int) ceil((strtotime((string) $plan['expires_at']) - time()) / 86400)) : 0;
    }

    /** Зачисляет оплаченные доп. магазины (идемпотентность — на стороне платежа). */
    public static function addStores(int $userId, int $qty): void
    {
        $user = DB::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user) {
            $max = self::maxExtra(self::get((string) $user['plan_code']));
            DB::exec('UPDATE users SET extra_stores = LEAST(?, extra_stores + ?) WHERE id = ?', [$max, max(0, $qty), $userId]);
        }
    }

    /**
     * Продлевает/меняет тариф. Если продлевается тот же действующий тариф — срок добавляется к текущему.
     */
    /** @return array{0: string, 1: string}|null период действия оплаты [с, по] */
    public static function activate(int $userId, string $code, int $months, int $extra = 0): ?array
    {
        $user = DB::one('SELECT plan_code, plan_expires_at FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return null;
        }
        $from = time();
        if ($user['plan_code'] === $code && $user['plan_expires_at'] && strtotime($user['plan_expires_at']) > time()) {
            $from = strtotime($user['plan_expires_at']);
        }
        $expires = date('Y-m-d H:i:s', strtotime("+$months month", $from));
        $extra = min(max(0, $extra), self::maxExtra(self::get($code)));
        DB::exec('UPDATE users SET plan_code = ?, plan_expires_at = ?, extra_stores = ? WHERE id = ?', [$code, $expires, $extra, $userId]);
        return [date('Y-m-d H:i:s', $from), $expires];
    }
}
