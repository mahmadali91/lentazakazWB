<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Services\Cron;
use App\Services\YooKassa;

final class SystemController
{
    /**
     * Уведомления ЮKassa (HTTP-уведомления). В личном кабинете ЮKassa укажите URL:
     *   https://wb.linkoz.ru/webhook/yookassa  — события payment.succeeded и payment.canceled
     * Данные не принимаются на веру: статус платежа перепроверяется запросом к API ЮKassa.
     */
    public function yookassa(): never
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $id = is_array($body) ? (string) ($body['object']['id'] ?? '') : '';
        if ($id === '' || !YooKassa::enabled()) {
            http_response_code(400);
            exit('bad request');
        }
        try {
            YooKassa::sync($id);
            http_response_code(200);
            exit('ok');
        } catch (\Throwable $e) {
            log_message('payments', 'webhook: ' . $e->getMessage());
            http_response_code(500);
            exit('error');
        }
    }

    /** Запуск синхронизации по URL — запасной вариант, если нельзя настроить cron. */
    public function cron(): never
    {
        $secret = (string) config('cron.secret', '');
        if ($secret === '' || !hash_equals($secret, (string) ($_GET['key'] ?? ''))) {
            http_response_code(403);
            exit('forbidden');
        }
        @set_time_limit(60);
        json_response(Cron::run(45));
    }

    /** Временная ссылка администратора на файл клиента (выдаёт инструмент admin_file_link, действует 30 минут). */
    public function file(string $token): never
    {
        $row = preg_match('/^[a-f0-9]{40}$/', $token) ? json_decode((string) DB::setting('flink.' . $token, ''), true) : null;
        if (!is_array($row) || ($row['exp'] ?? 0) < time() || !is_file((string) ($row['path'] ?? ''))) {
            http_response_code(404);
            exit('Ссылка недействительна или истекла.');
        }
        log_message('files', 'выдан файл ' . basename($row['path']) . ' по временной ссылке, IP ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . filesize($row['path']));
        header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($row['path'])));
        header('Cache-Control: no-store');
        readfile($row['path']);
        exit;
    }
}
