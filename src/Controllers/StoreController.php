<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Plans;
use App\Services\StoreService;

final class StoreController
{
    public function index(): string
    {
        $user = Auth::require();
        $plan = Plans::forUser($user);
        $stores = StoreService::forUser((int) $user['id']);
        $counts = [];
        foreach (DB::all('SELECT store_id, COUNT(*) c FROM orders WHERE store_id IN (SELECT id FROM stores WHERE user_id = ?) GROUP BY store_id', [$user['id']]) as $r) {
            $counts[$r['store_id']] = (int) $r['c'];
        }
        return view('app/stores', [
            'title'     => 'Магазины',
            'user'      => $user,
            'plan'      => $plan,
            'stores'    => $stores,
            'counts'    => $counts,
            'activeIds' => StoreService::activeIds((int) $user['id'], $plan),
            'nav'       => 'stores',
        ], 'app');
    }

    public function create(): never
    {
        $user = Auth::require();
        $plan = Plans::forUser($user);
        $count = (int) DB::value('SELECT COUNT(*) FROM stores WHERE user_id = ?', [$user['id']]);
        if ($count >= (int) $plan['stores']) {
            flash('error', 'На тарифе «' . $plan['name'] . '» можно подключить ' . $plan['stores'] . ' ' . plural((int) $plan['stores'], 'магазин', 'магазина', 'магазинов') . '. Перейдите на тариф выше.');
            redirect('/app/billing');
        }
        try {
            StoreService::create((int) $user['id'], (string) input('name', ''), (string) ($_POST['token'] ?? ''));
            flash('success', 'Магазин подключён! Первые заказы появятся в ленте в течение 1–2 минут.');
            redirect('/app');
        } catch (\InvalidArgumentException $e) {
            remember_input($_POST);
            flash('error', $e->getMessage());
            redirect('/app/stores');
        }
    }

    private function own(string $id): array
    {
        $store = StoreService::findForUser(Auth::id(), (int) $id);
        if (!$store) {
            http_response_code(404);
            exit('Магазин не найден');
        }
        return $store;
    }

    public function updateToken(string $id): never
    {
        Auth::require();
        $store = $this->own($id);
        try {
            StoreService::updateToken($store, (string) ($_POST['token'] ?? ''));
            flash('success', 'Токен обновлён и проверен.');
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/app/stores');
    }

    public function rename(string $id): never
    {
        Auth::require();
        $store = $this->own($id);
        $name = mb_substr((string) input('name', ''), 0, 120);
        if ($name !== '') {
            DB::exec('UPDATE stores SET name = ? WHERE id = ?', [$name, $store['id']]);
            flash('success', 'Название сохранено.');
        }
        redirect('/app/stores');
    }

    public function toggle(string $id): never
    {
        Auth::require();
        $store = $this->own($id);
        DB::exec('UPDATE stores SET is_active = 1 - is_active WHERE id = ?', [$store['id']]);
        flash('success', (int) $store['is_active'] ? 'Синхронизация магазина приостановлена.' : 'Синхронизация магазина возобновлена.');
        redirect('/app/stores');
    }

    public function resync(string $id): never
    {
        Auth::require();
        $store = $this->own($id);
        DB::exec('UPDATE stores SET feed_state = NULL, feed_next_at = NULL, mp_next_at = NULL, stats_cursor = NULL,
            stats_next_at = NULL, cards_next_at = NULL, last_error = NULL WHERE id = ?', [$store['id']]);
        flash('success', 'Полная перезагрузка заказов запущена. Это займёт несколько минут.');
        redirect('/app/stores');
    }

    public function delete(string $id): never
    {
        Auth::require();
        $store = $this->own($id);
        DB::exec('DELETE FROM stores WHERE id = ?', [$store['id']]);
        flash('success', 'Магазин и его заказы удалены. Токен стёрт из базы.');
        redirect('/app/stores');
    }
}
