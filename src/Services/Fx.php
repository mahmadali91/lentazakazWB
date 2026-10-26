<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Курсы ЦБ РФ для пересчёта сумм в рубли (cbr.ru, XML API). Кэш — таблица fx_rates (рублей за 1 единицу валюты).
 * Используется, если клиент включил «Показывать суммы в рублях» (users.show_rub).
 */
final class Fx
{
    private const DAILY = 'https://www.cbr.ru/scripts/XML_daily.asp?date_req=';
    private const DYNAMIC = 'https://www.cbr.ru/scripts/XML_dynamic.asp?date_req1=%s&date_req2=%s&VAL_NM_RQ=%s';
    private const MIRROR = 'https://www.cbr-xml-daily.ru/archive/%s/daily_json.js';
    /** Не больше запросов к ЦБ за один запуск — страница не должна ждать курсы. */
    private const MAX_CALLS = 4;
    private static array $memo = [];
    private static int $calls = 0;

    /** Включён ли пересчёт в рубли у кабинета. */
    public static function enabled(?array $account): bool
    {
        return $account !== null && !empty($account['show_rub']);
    }

    /** Курс: рублей за 1 единицу валюты на дату (последний известный на эту дату). null — курс недоступен. */
    public static function rate(string $code, ?string $day = null): ?float
    {
        $code = strtoupper($code);
        if ($code === '' || $code === 'RUB' || $code === 'RUR') {
            return 1.0;
        }
        $day = min($day ?? date('Y-m-d'), date('Y-m-d'));
        $key = $code . $day;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }
        $row = DB::one('SELECT day, rate FROM fx_rates WHERE code = ? AND day <= ? ORDER BY day DESC LIMIT 1', [$code, $day]);
        // Курс старше 4 дней (выходные и праздники ЦБ — до 3 дней) — подгружаем этот день
        if ((!$row || strtotime($row['day']) < strtotime($day) - 4 * 86400) && self::canCall()) {
            self::loadDay($day);
            $row = DB::one('SELECT day, rate FROM fx_rates WHERE code = ? AND day <= ? ORDER BY day DESC LIMIT 1', [$code, $day]) ?: $row;
        }
        return self::$memo[$key] = $row ? (float) $row['rate'] : null;
    }

    /** Подгружает курсы валюты за период одним запросом (для финансов за много недель). */
    public static function preload(string $code, string $from, string $to): void
    {
        $code = strtoupper($code);
        if ($code === 'RUB') {
            return;
        }
        $to = min($to, date('Y-m-d'));
        $have = (int) DB::value('SELECT COUNT(*) FROM fx_rates WHERE code = ? AND day BETWEEN ? AND ?', [$code, $from, $to]);
        if ($have >= max(1, (int) ((strtotime($to) - strtotime($from)) / 86400 / 7) * 4)) {
            return;
        }
        $ids = json_decode((string) DB::setting('fx_ids', ''), true) ?: [];
        if (!isset($ids[$code]) && self::canCall()) {
            self::loadDay(date('Y-m-d'));
            $ids = json_decode((string) DB::setting('fx_ids', ''), true) ?: [];
        }
        if (!isset($ids[$code]) || !self::canCall()) {
            return;
        }
        $xml = self::get(sprintf(self::DYNAMIC, date('d/m/Y', strtotime($from) - 7 * 86400), date('d/m/Y', strtotime($to)), $ids[$code]));
        if (!$xml) {
            return;
        }
        foreach ($xml->Record ?? [] as $r) {
            $nominal = max(1, (int) $r->Nominal);
            $value = (float) str_replace(',', '.', (string) $r->Value);
            $d = \DateTime::createFromFormat('d.m.Y', (string) $r['Date']);
            if ($value > 0 && $d) {
                self::store($d->format('Y-m-d'), $code, $value / $nominal);
            }
        }
    }

    /** Коэффициенты пересчёта для магазинов (курс на сегодня): [store_id => рублей за единицу]. null — курс недоступен. */
    public static function storeFactors(array $storeIds): ?array
    {
        if (!$storeIds) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT id, currency FROM stores WHERE id IN (' . implode(',', array_map('intval', $storeIds)) . ')') as $s) {
            $rate = self::rate((string) ($s['currency'] ?: 'RUB'));
            if ($rate === null) {
                return null;
            }
            $out[(int) $s['id']] = $rate;
        }
        return $out;
    }

    private static function loadDay(string $day): void
    {
        $xml = self::get(self::DAILY . date('d/m/Y', strtotime($day)));
        if (!$xml) {
            self::loadMirror($day);
            return;
        }
        $d = \DateTime::createFromFormat('d.m.Y', (string) $xml['Date']);
        $date = $d ? $d->format('Y-m-d') : $day;
        $ids = json_decode((string) DB::setting('fx_ids', ''), true) ?: [];
        foreach ($xml->Valute ?? [] as $v) {
            $code = strtoupper((string) $v->CharCode);
            $nominal = max(1, (int) $v->Nominal);
            $value = (float) str_replace(',', '.', (string) $v->Value);
            if ($code !== '' && $value > 0) {
                self::store($date, $code, $value / $nominal);
                $ids[$code] = (string) $v['ID'];
            }
        }
        DB::setSetting('fx_ids', json_encode($ids));
    }

    /** Запасной источник — зеркало курсов ЦБ (cbr-xml-daily.ru): ищем ближайший рабочий день назад. */
    private static function loadMirror(string $day): void
    {
        for ($i = 0; $i < 4 && self::canCall(); $i++) {
            $d = date('Y/m/d', strtotime($day) - $i * 86400);
            $json = json_decode((string) self::http(sprintf(self::MIRROR, $d)), true);
            if (!empty($json['Valute'])) {
                foreach ($json['Valute'] as $code => $v) {
                    if (($v['Value'] ?? 0) > 0) {
                        self::store(str_replace('/', '-', $d), strtoupper((string) $code), (float) $v['Value'] / max(1, (int) ($v['Nominal'] ?? 1)));
                    }
                }
                return;
            }
        }
    }

    /** Можно ли сейчас обращаться к ЦБ: лимит на запуск и пауза 10 минут после сбоя. */
    private static function canCall(): bool
    {
        return self::$calls < self::MAX_CALLS && (int) DB::setting('fx_down_until', '0') < time();
    }

    private static function store(string $day, string $code, float $rate): void
    {
        DB::exec('INSERT INTO fx_rates (code, day, rate) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE rate = VALUES(rate)', [$code, $day, round($rate, 8)]);
    }

    private static function http(string $url): ?string
    {
        self::$calls++;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LentaZakazWB/' . APP_VERSION . ')']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || ($code !== 200 && $code !== 404)) {
            // Сбой сети или защита сайта — 10 минут не обращаемся, берём последний известный курс
            DB::setSetting('fx_down_until', (string) (time() + 600));
            return null;
        }
        return $code === 200 ? $body : null;
    }

    private static function get(string $url): ?\SimpleXMLElement
    {
        $body = self::http($url);
        if ($body === null || !str_contains($body, '<')) {
            return null;
        }
        // Ответ ЦБ — в windows-1251
        $body = preg_replace('/encoding="windows-1251"/i', 'encoding="UTF-8"', mb_convert_encoding($body, 'UTF-8', 'Windows-1251'));
        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $body);
        libxml_use_internal_errors($prev);
        return $xml ?: null;
    }
}
