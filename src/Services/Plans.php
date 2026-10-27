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

    /** Действующий тариф пользователя (истёкший → бесплатный). */
    public static function forUser(array $user): array
    {
        $code = (string) $user['plan_code'];
        $expires = $user['plan_expires_at'] ?? null;
        $active = $code !== 'free' && $expires !== null && strtotime((string) $expires) > time();
        $plan = self::get($active ? $code : 'free');
        $plan['expires_at'] = $active ? $expires : null;
        $plan['expired_code'] = (!$active && $code !== 'free') ? $code : null;
        return $plan;
    }

    /** Доступен ли раздел «Финансы» (отчёты реализации WB). */
    public static function finance(array $plan): bool
    {
        return (bool) ($plan['finance'] ?? $plan['profit'] ?? false);
    }

    public static function price(string $code, int $months): float
    {
        $base = (float) (self::get($code)['price'] ?? 0);
        $discount = self::PERIODS[$months] ?? 0;
        return round($base * $months * (100 - $discount) / 100);
    }

    /**
     * Продлевает/меняет тариф. Если продлевается тот же действующий тариф — срок добавляется к текущему.
     */
    public static function activate(int $userId, string $code, int $months): void
    {
        $user = DB::one('SELECT plan_code, plan_expires_at FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return;
        }
        $from = time();
        if ($user['plan_code'] === $code && $user['plan_expires_at'] && strtotime($user['plan_expires_at']) > time()) {
            $from = strtotime($user['plan_expires_at']);
        }
        $expires = date('Y-m-d H:i:s', strtotime("+$months month", $from));
        DB::exec('UPDATE users SET plan_code = ?, plan_expires_at = ? WHERE id = ?', [$code, $expires, $userId]);
    }
}
