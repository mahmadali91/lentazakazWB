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
set_time_limit(58);
$report = App\Services\Cron::run(50);
if (in_array('-v', $argv, true)) {
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), PHP_EOL;
}
