<?php
/**
 * Фоновая синхронизация заказов с WB. Запускайте каждую минуту.
 *
 * Beget → «Задания CRON» → Добавить задание:
 *   Периодичность: каждую минуту (* * * * *)
 *   Команда: /usr/local/bin/php8.2 /home/ЛОГИН/САЙТ/public_html/cron/sync.php
 * (путь к PHP: /usr/local/bin/php8.1, php8.2, php8.3 — по выбранной версии)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require dirname(__DIR__) . '/src/bootstrap.php';

if (!App\Core\Config::installed()) {
    fwrite(STDERR, "Сервис не установлен: откройте сайт и пройдите /install\n");
    exit(1);
}

App\Core\Migrator::ensure();
App\Core\Config::overlayFromDb();
set_time_limit(58);
$report = App\Services\Cron::run(50);
if (in_array('-v', $argv, true)) {
    // Диагностика: что включено у каждого магазина и когда следующий запрос к WB
    $report['diagnostics'] = array_map(static function (array $s): array {
        $scopes = json_decode((string) $s['scopes'], true) ?: [];
        return [
            'store'        => $s['id'] . ' · ' . $s['name'],
            'active'       => (bool) $s['is_active'],
            'scopes'       => $scopes,
            'next'         => ['feed' => $s['feed_next_at'], 'marketplace' => $s['mp_next_at'], 'statistics' => $s['stats_next_at'], 'content' => $s['cards_next_at'],
                'finance' => $s['fin_next_at'] ?? null, 'storage' => $s['storage_next_at'] ?? null, 'ads' => $s['ads_next_at'] ?? null],
            'last_ok'      => ['feed' => $s['feed_ok_at'], 'marketplace' => $s['mp_ok_at'], 'statistics' => $s['stats_ok_at'],
                'finance' => $s['fin_ok_at'] ?? null, 'storage' => $s['storage_ok_at'] ?? null, 'ads' => $s['ads_ok_at'] ?? null],
            'finance'      => App\Core\DB::one('SELECT COUNT(*) reports, COALESCE(SUM(detail_done), 0) loaded, COALESCE(SUM(rows_loaded), 0) rows_count, MIN(date_from) since FROM wb_reports WHERE store_id = ?', [$s['id']]),
            'last_error'   => $s['last_error'] ? $s['last_error'] . ' (' . $s['last_error_at'] . ')' : null,
            'orders_in_db' => (int) App\Core\DB::value('SELECT COUNT(*) FROM orders WHERE store_id = ?', [$s['id']]),
            'recent_log'   => array_map(
                static fn($l) => $l['created_at'] . ' ' . $l['source'] . ' ' . ($l['ok'] ? 'OK ' . $l['rows_count'] : 'ERR ' . $l['message']),
                App\Core\DB::all('SELECT * FROM sync_log WHERE store_id = ? ORDER BY id DESC LIMIT 8', [$s['id']])
            ),
        ];
    }, App\Core\DB::all('SELECT * FROM stores ORDER BY id'));
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
