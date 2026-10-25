<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Один проход фоновой синхронизации (вызывается раз в минуту из cron). */
final class Cron
{
    public static function run(int $budgetSeconds = 50): array
    {
        $lockFile = BASE_PATH . '/storage/cron.lock';
        $lock = fopen($lockFile, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'уже выполняется'];
        }
        $started = microtime(true);
        $report = ['stores' => 0];
        try {
            DB::setSetting('cron_last_run', now());

            $stores = DB::all('SELECT s.*, u.plan_code, u.plan_expires_at, u.is_blocked
                FROM stores s JOIN users u ON u.id = s.user_id
                WHERE s.is_active = 1 AND u.is_blocked = 0
                ORDER BY COALESCE(LEAST(
                    COALESCE(s.feed_next_at, \'1970-01-01\'), COALESCE(s.mp_next_at, \'1970-01-01\'),
                    COALESCE(s.stats_next_at, \'1970-01-01\')), \'1970-01-01\')');

            $allowed = [];
            foreach ($stores as $store) {
                $plan = Plans::forUser($store);
                // Магазины сверх лимита тарифа не синхронизируются
                $allowed[$store['user_id']] ??= StoreService::activeIds((int) $store['user_id'], $plan);
                if (!in_array((int) $store['id'], $allowed[$store['user_id']], true)) {
                    continue;
                }
                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }
                try {
                    $report['detail'][$store['id']] = (new SyncService($store, $plan))->run();
                    $report['stores']++;
                } catch (\Throwable $e) {
                    log_message('sync', "store {$store['id']}: " . $e->getMessage());
                }
            }

            Telegram::processUpdates();
            Telegram::notifyOrders();
            self::cleanup();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $report['seconds'] = round(microtime(true) - $started, 2);
        return $report;
    }

    private static function cleanup(): void
    {
        // Раз в час чистим служебные таблицы
        $last = DB::setting('cleanup_at');
        if ($last && strtotime($last) > time() - 3600) {
            return;
        }
        DB::setSetting('cleanup_at', now());
        DB::exec('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
        DB::exec('DELETE FROM sync_log WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 3 * 86400)]);
        DB::exec('DELETE FROM password_resets WHERE expires_at < ?', [now()]);
        // Заказы храним 120 дней
        DB::exec('DELETE FROM orders WHERE order_created_at < ?', [date('Y-m-d H:i:s', time() - 120 * 86400)]);
    }
}
