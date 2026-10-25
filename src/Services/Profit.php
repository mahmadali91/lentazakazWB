<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Расчёт прибыли по заказу с объяснением, откуда взята каждая цифра.
 *
 * Прибыль = цена продавца − комиссия WB − логистика − налог − прочие расходы − себестоимость.
 *
 * Комиссия: ставка WB для предмета товара и схемы продажи (FBO / FBS / DBS / самовывоз), загружается
 * методом /api/v1/tariffs/commission токеном магазина. Ручная ставка товара важнее, общая — запасной вариант.
 *
 * Логистика (инструкция WB «Доставка: виды и расчёт стоимости»):
 *   прямая доставка до 1 л — по таблице объёма (23/26/29/30/32 ₽), от 1 л — 46 ₽ + 14 ₽ × доп. литры;
 *   умножается на коэффициент доставки склада (тариф WB склада, иначе — из настроек, по умолчанию 170%).
 *   Обратная доставка (отказ/возврат) — базовый тариф без коэффициента; для FBS — 25 ₽ (+4 ₽ за доп. литр).
 *   DBS и самовывоз доставляет продавец — логистики WB нет.
 */
final class Profit
{
    public const DEFAULTS = [
        'commission' => 25.0,  // комиссия, если ставка категории неизвестна, %
        'coef'       => 170.0, // коэффициент доставки склада, если склад неизвестен, %
        'logistics'  => 80.0,  // логистика, если объём товара неизвестен, ₽
        'tax'        => 6.0,   // налог, % от цены
        'other'      => 0.0,   // прочие расходы на единицу, ₽
    ];

    /** Базовый тариф прямой доставки до 1 литра: верхняя граница объёма => ₽. */
    private const SMALL = [[0.2, 23], [0.4, 26], [0.6, 29], [0.8, 30], [1.0, 32]];

    private const SCHEME_COMMISSION = ['FBO' => 'fbo', 'FBS' => 'fbs', 'MP' => 'fbs', 'DBS' => 'dbs', 'DBW' => 'dbs', 'CC' => 'pickup'];
    private const COMMISSION_LABEL = ['fbo' => 'FBO (склад WB)', 'fbs' => 'FBS (склад продавца)', 'dbs' => 'DBS/DBW', 'pickup' => 'Самовывоз'];

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
            $max = in_array($k, ['commission', 'tax'], true) ? 100.0 : ($k === 'coef' ? 1000.0 : 1000000.0);
            $s[$k] = is_numeric($v) ? max(0.0, min($max, (float) $v)) : $default;
        }
        DB::exec('UPDATE users SET profit_settings = ? WHERE id = ?', [json_encode($s), $accountId]);
    }

    // ------------------------------------------------------------------ справочники

    /** Комиссии и коэффициенты складов для набора магазинов (кэш на запрос). */
    public static function context(array $storeIds): array
    {
        static $cache = [];
        $key = implode(',', $storeIds);
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $ctx = ['comm' => [], 'commByName' => [], 'box' => [], 'commAt' => null, 'boxAt' => null];
        if ($storeIds) {
            $in = implode(',', array_map('intval', $storeIds));
            foreach (DB::all("SELECT * FROM wb_commissions WHERE store_id IN ($in)") as $c) {
                $ctx['comm'][$c['store_id']][(int) $c['subject_id']] = $c;
                $ctx['commByName'][$c['store_id']][mb_strtolower($c['subject_name'])] = $c;
                $ctx['commAt'] = max((string) $ctx['commAt'], $c['updated_at']);
            }
        }
        foreach (DB::all('SELECT * FROM wb_box_tariffs') as $b) {
            $ctx['box'][mb_strtolower($b['warehouse_name'])] = $b;
            $ctx['boxAt'] = max((string) $ctx['boxAt'], $b['updated_at']);
        }
        return $cache[$key] = $ctx;
    }

    /** Базовый тариф прямой доставки по объёму (без коэффициента склада). */
    public static function baseDelivery(float $volume): float
    {
        if ($volume <= 1.0) {
            foreach (self::SMALL as [$max, $price]) {
                if ($volume <= $max + 1e-9) {
                    return (float) $price;
                }
            }
        }
        return 46.0 + 14.0 * ($volume - 1.0);
    }

    /** Обратная доставка FBS в ПВЗ: 25 ₽ до 1 л, от 1 л — 25 ₽ + 4 ₽ × доп. литры. */
    public static function fbsReturn(float $volume): float
    {
        return $volume <= 1.0 ? 25.0 : 25.0 + 4.0 * ($volume - 1.0);
    }

    /**
     * Подробный расчёт для заказа. Ключи строки: seller_price, status, cancel_type, scheme, warehouse, store_id,
     * p_cost, p_commission, p_subject_id, p_volume, subject/p_subject.
     * @return array|null null — нет цены
     */
    public static function breakdown(array $r, array $s, array $ctx): ?array
    {
        if ($r['seller_price'] === null) {
            return null;
        }
        $price = (float) $r['seller_price'];
        $scheme = (string) ($r['scheme'] ?: ((int) ($r['is_mp'] ?? 0) ? 'MP' : 'FBO'));
        $status = (string) $r['status'];

        // --- комиссия
        $col = self::SCHEME_COMMISSION[$scheme] ?? 'fbs';
        $commPct = (float) $s['commission'];
        $commSrc = 'общая ставка из настроек (категория не найдена)';
        if (isset($r['p_commission']) && $r['p_commission'] !== null) {
            $commPct = (float) $r['p_commission'];
            $commSrc = 'ставка, указанная вручную для товара';
        } else {
            $subjectName = mb_strtolower((string) ($r['subject'] ?? $r['p_subject'] ?? ''));
            $c = null;
            if (!empty($r['p_subject_id'])) {
                $c = $ctx['comm'][$r['store_id']][(int) $r['p_subject_id']] ?? null;
            }
            if (!$c && $subjectName !== '') {
                $c = $ctx['commByName'][$r['store_id']][$subjectName] ?? null;
            }
            if ($c && $c[$col] !== null) {
                $commPct = (float) $c[$col];
                $commSrc = 'тариф WB: «' . $c['subject_name'] . '», ' . self::COMMISSION_LABEL[$col];
            }
        }

        // --- логистика
        $volume = isset($r['p_volume']) && $r['p_volume'] !== null && (float) $r['p_volume'] > 0 ? (float) $r['p_volume'] : null;
        $sellerDelivers = in_array($scheme, ['DBS', 'CC'], true);
        $coef = (float) $s['coef'];
        $coefSrc = 'коэффициент из настроек';
        $wh = mb_strtolower((string) ($r['warehouse'] ?? ''));
        if ($wh !== '' && isset($ctx['box'][$wh])) {
            $b = $ctx['box'][$wh];
            $whCoef = $scheme === 'FBO' ? $b['delivery_coef'] : ($b['mp_coef'] ?? $b['delivery_coef']);
            if ($whCoef !== null && (float) $whCoef > 0) {
                $coef = (float) $whCoef;
                $coefSrc = 'тариф WB склада «' . $r['warehouse'] . '»';
            }
        }
        if ($sellerDelivers) {
            $direct = 0.0;
            $logSrc = 'доставляет продавец (' . $scheme . ') — логистики WB нет';
        } elseif ($volume !== null) {
            $base = self::baseDelivery($volume);
            $direct = round($base * $coef / 100, 2);
            $logSrc = 'объём ' . rtrim(rtrim(number_format($volume, 3, ',', ''), '0'), ',') . ' л → базовый тариф ' . self::fmt($base)
                . ' ₽ × ' . self::fmt($coef) . '% (' . $coefSrc . ')';
        } else {
            $direct = (float) $s['logistics'];
            $logSrc = 'фиксированная сумма из настроек — объём товара ещё не загружен';
        }

        // --- обратная доставка при отказе при получении / возврате
        $lostAtPickup = in_array($status, ['return', 'returnDefective'], true)
            || ($status === 'cancel' && in_array($r['cancel_type'] ?? '', ['receipt', 'expire'], true));
        $reverse = 0.0;
        if ($lostAtPickup && !$sellerDelivers) {
            $reverse = $volume !== null
                ? ($scheme === 'FBO' ? self::baseDelivery($volume) : self::fbsReturn($volume))
                : (float) $s['logistics'];
        }

        $cost = isset($r['p_cost']) && $r['p_cost'] !== null ? (float) $r['p_cost'] : null;
        $commission = round($price * $commPct / 100, 2);
        $tax = round($price * (float) $s['tax'] / 100, 2);
        $other = (float) $s['other'];

        if ($status === 'cancel' || $lostAtPickup) {
            // Продажи нет: теряем только логистику (туда и обратно), если товар доехал до ПВЗ
            $profit = $lostAtPickup ? -round($direct + $reverse) : 0.0;
            $kind = $lostAtPickup ? 'loss' : 'none';
        } else {
            $profit = $cost === null ? null : round($price - $commission - $direct - $tax - $other - $cost);
            $kind = 'sale';
        }

        return [
            'kind'           => $kind,
            'price'          => $price,
            'commission_pct' => $commPct,
            'commission'     => $commission,
            'commission_src' => $commSrc,
            'logistics'      => $direct,
            'logistics_src'  => $logSrc,
            'reverse'        => $reverse,
            'volume'         => $volume,
            'coef'           => $coef,
            'tax_pct'        => (float) $s['tax'],
            'tax'            => $tax,
            'other'          => $other,
            'cost'           => $cost,
            'profit'         => $profit,
        ];
    }

    private static function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',');
    }
}
