<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Оценка прибыли по заказам.
 *
 * Прибыль с единицы = цена продавца − комиссия WB (% от цены) − логистика − налог (% от цены) − прочие расходы − себестоимость.
 * Комиссия берётся из карточки товара (если указана) или из общих настроек кабинета.
 * Для отмен при получении, «не забран» и возвратов расходом считается обратная логистика.
 * Это оценка: точные удержания WB видны только в еженедельном отчёте реализации.
 */
final class Profit
{
    public const DEFAULTS = [
        'commission' => 25.0,  // комиссия WB, %
        'logistics'  => 80.0,  // логистика до покупателя за единицу, ₽
        'tax'        => 6.0,   // налог, % от цены (УСН «доходы» — 6%)
        'other'      => 0.0,   // упаковка, хранение, реклама и пр. на единицу, ₽
    ];

    public static function settings(array $account): array
    {
        $saved = json_decode((string) ($account['profit_settings'] ?? ''), true);
        $s = self::DEFAULTS;
        if (is_array($saved)) {
            foreach (self::DEFAULTS as $k => $v) {
                if (isset($saved[$k]) && is_numeric($saved[$k])) {
                    $s[$k] = (float) $saved[$k];
                }
            }
        }
        return $s;
    }

    public static function save(int $accountId, array $input): void
    {
        $s = [];
        foreach (self::DEFAULTS as $k => $default) {
            $v = str_replace([',', ' '], ['.', ''], (string) ($input[$k] ?? $default));
            $s[$k] = is_numeric($v) ? max(0.0, min($k === 'commission' || $k === 'tax' ? 100.0 : 1000000.0, (float) $v)) : $default;
        }
        DB::exec('UPDATE users SET profit_settings = ? WHERE id = ?', [json_encode($s), $accountId]);
    }

    /** SQL-выражение прибыли с единицы для строки заказа (алиасы o — orders, p — products). NULL, если нет себестоимости. */
    public static function unitExpr(array $s): string
    {
        $c = (float) $s['commission'];
        $l = (float) $s['logistics'];
        $t = (float) $s['tax'];
        $x = (float) $s['other'];
        return "(o.seller_price - o.seller_price * COALESCE(p.commission_pct, $c) / 100 - $l - o.seller_price * $t / 100 - $x - p.cost_price)";
    }

    /** Расход по отменённым/возвращённым заказам (обратная логистика). */
    public static function lossExpr(array $s): string
    {
        $l = (float) $s['logistics'];
        return "(CASE WHEN o.status IN ('return','returnDefective') OR (o.status = 'cancel' AND o.cancel_type IN ('receipt','expire')) THEN $l ELSE 0 END)";
    }

    /** Прибыль для одного заказа (для карточки в ленте). */
    public static function forOrder(array $row, array $s): ?float
    {
        if (!isset($row['p_cost']) || $row['p_cost'] === null || $row['seller_price'] === null) {
            return null;
        }
        $status = $row['status'];
        if ($status === 'cancel' || $status === 'return' || $status === 'returnDefective') {
            $lost = in_array($status, ['return', 'returnDefective'], true) || in_array($row['cancel_type'], ['receipt', 'expire'], true);
            return $lost ? -round((float) $s['logistics']) : 0.0;
        }
        $price = (float) $row['seller_price'];
        $commission = $row['p_commission'] !== null ? (float) $row['p_commission'] : (float) $s['commission'];
        return round($price - $price * $commission / 100 - $s['logistics'] - $price * $s['tax'] / 100 - $s['other'] - (float) $row['p_cost']);
    }
}
