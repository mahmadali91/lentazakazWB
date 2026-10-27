<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Передача (выделение) аккаунта: сотрудник или клиент кабинета становится самостоятельным владельцем
 * вместе с магазинами, к которым у него был доступ. Новый аккаунт сразу переходит на тариф 2
 * («Лента + Юнит + Финансы») на месяц с даты передачи; передача стоит как месяц тарифа 2
 * (для супер-админа — бесплатно). Оплаченный остаток за переданные магазины у прежнего владельца сгорает.
 */
final class AccountTransfer
{
    public const PLAN = 'pro';

    /** Цена передачи — месяц тарифа 2 (для супер-админа — 0). */
    public static function price(array $owner): float
    {
        return !empty($owner['is_super']) ? 0.0 : (float) (Plans::get(self::PLAN)['price'] ?? 0);
    }

    /**
     * Проверка: сотрудник принадлежит кабинету и ему открыты отдельные магазины (1…лимит тарифа 2).
     * @return array{member: array, stores: int[]}
     */
    public static function check(int $ownerId, int $memberId): array
    {
        $member = DB::one('SELECT * FROM users WHERE id = ? AND owner_id = ?', [$memberId, $ownerId]);
        if (!$member) {
            throw new \RuntimeException('Сотрудник не найден.');
        }
        $access = StoreService::memberAccess($member);
        if ($access === null) {
            throw new \RuntimeException('Сначала откройте сотруднику «только выбранные» магазины — они перейдут в его аккаунт.');
        }
        $own = array_map('intval', array_column(DB::all('SELECT id FROM stores WHERE user_id = ?', [$ownerId]), 'id'));
        $stores = array_values(array_intersect($own, $access));
        $plan = Plans::get(self::PLAN);
        $max = (int) $plan['stores'] + Plans::maxExtra($plan + ['code' => self::PLAN]);
        if (!$stores) {
            throw new \RuntimeException('У сотрудника нет доступа ни к одному магазину — передавать нечего.');
        }
        if (count($stores) > $max) {
            throw new \RuntimeException('На тарифе «' . $plan['name'] . '» можно до ' . $max . ' магазинов, а у сотрудника — ' . count($stores) . '.');
        }
        return ['member' => $member, 'stores' => $stores];
    }

    /** Передача: магазины, их расходы и данные — новому владельцу; тариф 2 на месяц с сегодняшнего дня. */
    public static function execute(int $ownerId, int $memberId): array
    {
        ['member' => $member, 'stores' => $stores] = self::check($ownerId, $memberId);
        $in = implode(',', $stores);
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            DB::exec("UPDATE stores SET user_id = ? WHERE id IN ($in)", [$memberId]);
            DB::exec("UPDATE fin_expenses SET user_id = ? WHERE user_id = ? AND store_id IN ($in)", [$memberId, $ownerId]);
            $extra = count($stores) - (int) Plans::get(self::PLAN)['stores'];
            DB::exec("UPDATE users SET owner_id = NULL, store_access = NULL, tg_stores = NULL, plan_code = ?, plan_expires_at = ?, extra_stores = ? WHERE id = ?",
                [self::PLAN, date('Y-m-d H:i:s', strtotime('+1 month')), max(0, $extra), $memberId]);
            // Оплаченные доп. магазины прежнего владельца за переданные магазины сгорают
            DB::exec('UPDATE users SET extra_stores = GREATEST(0, CAST(extra_stores AS SIGNED) - ?) WHERE id = ?', [count($stores), $ownerId]);
            // Сотрудникам прежнего владельца закрываем доступ к переданным магазинам
            foreach (DB::all('SELECT id, store_access FROM users WHERE owner_id = ? AND store_access IS NOT NULL', [$ownerId]) as $m) {
                $left = array_values(array_diff(array_map('intval', json_decode((string) $m['store_access'], true) ?: []), $stores));
                DB::exec('UPDATE users SET store_access = ? WHERE id = ?', [json_encode($left), $m['id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        log_message('transfer', "кабинет {$ownerId} → аккаунт {$memberId}: магазины $in");
        return ['member' => $member, 'stores' => $stores];
    }
}
