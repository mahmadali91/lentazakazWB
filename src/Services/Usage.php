<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Нагрузка клиентов на сервер: объём данных в базе (оценка по строкам таблиц с данными магазинов),
 * время фоновой синхронизации (store_load) и число запросов к WB (sync_log). Для раздела «Клиенты» админки.
 */
final class Usage
{
    private const CACHE = BASE_PATH . '/storage/cache/usage.json';
    private const TTL = 600;

    /** Учёт времени синхронизации магазина (вызывается из cron после прохода магазина). */
    public static function record(int $storeId, float $seconds): void
    {
        DB::exec('INSERT INTO store_load (store_id, day, runs, ms) VALUES (?, CURDATE(), 1, ?)
            ON DUPLICATE KEY UPDATE runs = runs + 1, ms = ms + VALUES(ms)', [$storeId, (int) round($seconds * 1000)]);
    }

    /**
     * Нагрузка по владельцам кабинетов: [user_id => ['bytes', 'rows', 'cpu_7d' (сек), 'runs_7d', 'requests_24h', 'stores' => [store_id => ...]]].
     * Считается раз в 10 минут (подсчёт строк по большим таблицам не быстрый).
     */
    public static function byUser(bool $fresh = false): array
    {
        if (!$fresh && is_file(self::CACHE) && filemtime(self::CACHE) > time() - self::TTL) {
            $cached = json_decode((string) file_get_contents(self::CACHE), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $owner = array_column(DB::all('SELECT id, user_id FROM stores'), 'user_id', 'id');
        $stores = [];
        $add = static function (int $storeId, string $key, float $v) use (&$stores): void {
            $stores[$storeId][$key] = ($stores[$storeId][$key] ?? 0) + $v;
        };
        // Таблицы с данными магазинов и средний размер строки (данные + индексы)
        $tables = DB::all("SELECT t.TABLE_NAME name, t.TABLE_ROWS n, t.DATA_LENGTH + t.INDEX_LENGTH bytes FROM information_schema.TABLES t
            JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.COLUMN_NAME = 'store_id'
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'");
        foreach ($tables as $t) {
            $name = (string) $t['name'];
            if (!preg_match('/^[a-z0-9_]+$/', $name)) {
                continue;
            }
            $counts = DB::all("SELECT store_id, COUNT(*) c FROM `$name` GROUP BY store_id");
            $total = array_sum(array_column($counts, 'c')) ?: 1;
            // TABLE_ROWS у InnoDB приблизительный — размер строки считаем по точному числу строк
            $perRow = (float) $t['bytes'] / max($total, 1);
            foreach ($counts as $c) {
                if ($c['store_id'] === null) {
                    continue;
                }
                $add((int) $c['store_id'], 'rows', (float) $c['c']);
                $add((int) $c['store_id'], 'bytes', (float) $c['c'] * $perRow);
            }
        }
        foreach (DB::all('SELECT store_id, SUM(ms) ms, SUM(runs) runs FROM store_load WHERE day >= CURDATE() - INTERVAL 6 DAY GROUP BY store_id') as $l) {
            $add((int) $l['store_id'], 'cpu_7d', (float) $l['ms'] / 1000);
            $add((int) $l['store_id'], 'runs_7d', (float) $l['runs']);
        }
        foreach (DB::all('SELECT store_id, COUNT(*) c FROM sync_log WHERE created_at >= ? GROUP BY store_id', [date('Y-m-d H:i:s', time() - 86400)]) as $l) {
            $add((int) $l['store_id'], 'requests_24h', (float) $l['c']);
        }
        $out = [];
        foreach ($stores as $sid => $v) {
            $uid = (int) ($owner[$sid] ?? 0);
            if (!$uid) {
                continue;
            }
            $out[$uid]['stores'][$sid] = array_map(static fn($x) => round($x, 1), $v);
            foreach ($v as $k => $x) {
                $out[$uid][$k] = round(($out[$uid][$k] ?? 0) + $x, 1);
            }
        }
        @file_put_contents(self::CACHE, json_encode($out));
        return $out;
    }

    /** «12,4 МБ» */
    public static function size(float $bytes): string
    {
        return $bytes >= 1048576 * 1024 ? number_format($bytes / 1073741824, 2, ',', ' ') . ' ГБ'
            : ($bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', ' ') . ' МБ' : number_format($bytes / 1024, 0, ',', ' ') . ' КБ');
    }

    /** Общий размер базы данных сервиса. */
    public static function dbTotal(): float
    {
        return (float) DB::value('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
    }
}
