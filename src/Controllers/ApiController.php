<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\OrderRepository;
use App\Services\Plans;

/** JSON для живой ленты (опрашивается браузером). */
final class ApiController
{
    public function feed(): never
    {
        $user = Auth::require();
        $plan = Plans::forUser($user);
        [$storeIds, $f] = FeedController::filters($user, $plan);
        session_write_close();

        $beforeId = input('before_id') !== null ? (int) input('before_id') : null;
        $beforeDate = input('before_date') !== null ? (string) input('before_date') : null;
        $orders = OrderRepository::list($storeIds, $f, 50, $beforeId, $beforeDate);

        $payload = [
            'orders'      => $orders,
            'has_more'    => count($orders) === 50,
            'server_time' => $this->serverMark(),
        ];
        if ($beforeId === null) {
            $payload['kpi'] = OrderRepository::kpi($storeIds, $f);
            $payload['chart'] = OrderRepository::chart($storeIds, $f);
            $payload['top'] = OrderRepository::topProducts($storeIds, $f);
            $payload['sync'] = $this->syncInfo($storeIds);
        }
        json_response($payload);
    }

    public function updates(): never
    {
        $user = Auth::require();
        $plan = Plans::forUser($user);
        [$storeIds, $f] = FeedController::filters($user, $plan);
        session_write_close();
        $since = (string) input('since', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?$/', $since)) {
            $since = date('Y-m-d H:i:s', time() - 60);
        }
        $changed = OrderRepository::changedSince($storeIds, $f, $since);
        json_response([
            'orders'      => $changed,
            'kpi'         => $changed ? OrderRepository::kpi($storeIds, $f) : null,
            'server_time' => $this->serverMark(),
            'sync'        => $this->syncInfo($storeIds),
        ]);
    }

    /** Метка времени БД с миллисекундами — от неё считаем «что изменилось». */
    private function serverMark(): string
    {
        return (string) DB::value('SELECT DATE_FORMAT(NOW(3) - INTERVAL 2 SECOND, \'%Y-%m-%d %H:%i:%s.%f\')');
    }

    private function syncInfo(array $storeIds): array
    {
        if (!$storeIds) {
            return ['last' => null, 'errors' => []];
        }
        $in = implode(',', array_map('intval', $storeIds));
        $rows = DB::all("SELECT id, name, feed_ok_at, mp_ok_at, stats_ok_at, last_error, initial_done FROM stores WHERE id IN ($in)");
        $last = null;
        $errors = [];
        $loading = false;
        foreach ($rows as $r) {
            foreach (['feed_ok_at', 'mp_ok_at', 'stats_ok_at'] as $c) {
                if ($r[$c] && (!$last || $r[$c] > $last)) {
                    $last = $r[$c];
                }
            }
            if ($r['last_error']) {
                $errors[] = $r['name'] . ': ' . $r['last_error'];
            }
            if (!(int) $r['initial_done']) {
                $loading = true;
            }
        }
        return ['last' => $last ? date('c', strtotime($last)) : null, 'errors' => $errors, 'loading' => $loading];
    }
}
