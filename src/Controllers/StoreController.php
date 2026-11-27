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
        $user = Auth::requireOwner();
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
            // Клиенты агентства: доступ к отдельным магазинам по email и их запросы
            'clients'   => \App\Services\StoreClients::forOwner((int) $user['id']),
            'canClients'=> (int) ($plan['team'] ?? 0) > 0,
            'requests'  => DB::all('SELECT r.*, u.email, u.name FROM member_requests r JOIN users u ON u.id = r.member_id
                WHERE r.owner_id = ? AND (r.done_at IS NULL OR r.done_at > NOW() - INTERVAL 14 DAY) ORDER BY r.done_at IS NULL DESC, r.id DESC LIMIT 30', [$user['id']]),
            'nav'       => 'stores',
        ], 'app');
    }

    public function create(): never
    {
        $user = Auth::requireOwner();
        $plan = Plans::forUser($user);
        $count = (int) DB::value('SELECT COUNT(*) FROM stores WHERE user_id = ?', [$user['id']]);
        if ($count >= (int) $plan['stores']) {
            $canBuy = !empty($plan['store_price']) && $plan['expires_at'] && $plan['extra_stores'] < Plans::maxExtra($plan);
            flash('error', 'На тарифе «' . $plan['name'] . '» можно подключить ' . $plan['stores'] . ' ' . plural((int) $plan['stores'], 'магазин', 'магазина', 'магазинов') . '. '
                . ($canBuy ? 'Добавьте магазин к тарифу — ' . rub($plan['store_price']) . ' в месяц.' : 'Перейдите на тариф выше.'));
            redirect('/app/billing' . ($canBuy ? '#add-store' : ''));
        }
        try {
            StoreService::create((int) $user['id'], (string) input('name', ''), (string) ($_POST['token'] ?? ''), StoreService::financeInput($_POST));
            flash('success', 'Магазин подключён! Первые заказы появятся в ленте в течение 5 минут.');
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
        Auth::requireOwner();
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
        Auth::requireOwner();
        $store = $this->own($id);
        $name = mb_substr((string) input('name', ''), 0, 120);
        if ($name !== '') {
            DB::exec('UPDATE stores SET name = ? WHERE id = ?', [$name, $store['id']]);
            flash('success', 'Название сохранено.');
        }
        redirect('/app/stores');
    }

    /** Налог, НДС и фиксированные расходы магазина (для расчёта прибыли). */
    /** Налог, НДС и фиксированные расходы магазина: владелец или его клиент с уровнем «Клиент» (по своим магазинам). */
    public function finance(string $id): never
    {
        Auth::requireEditor();
        if (!Auth::isMember()) {
            $store = $this->own($id);
        } else {
            $account = Auth::account();
            if (!in_array((int) $id, StoreService::visibleIds((int) $account['id'], \App\Services\Plans::forUser($account)), true)) {
                redirect('/app/finance');
            }
            $store = ['id' => (int) $id];
        }
        DB::update('stores', StoreService::financeInput($_POST), 'id = :id', ['id' => $store['id']]);
        flash('success', 'Налог и расходы магазина сохранены — прибыль пересчитана.');
        $back = (string) input('back', '');
        redirect(preg_match('#^/app/[a-z/]*(\?[\w=&%.\-]*)?$#', $back) ? $back : '/app/stores');
    }

    /** Открыть клиенту доступ к магазину по email (новый — аккаунт и письмо активации, существующий — письмо подтверждения). */
    public function clientAdd(string $id): never
    {
        $owner = Auth::requireOwner();
        $store = $this->own($id);
        if ((int) (Plans::forUser($owner)['team'] ?? 0) <= 0) {
            flash('error', 'Доступ клиентам к магазинам — на тарифе ' . Plans::namesWith('team') . '.');
            redirect('/app/stores');
        }
        try {
            flash('success', \App\Services\StoreClients::invite($owner, $store, (string) input('email', '')));
        } catch (\InvalidArgumentException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/app/stores#store-' . (int) $store['id']);
    }

    public function clientDelete(string $id, string $userId): never
    {
        Auth::requireOwner();
        $store = $this->own($id);
        DB::exec('DELETE FROM store_clients WHERE store_id = ? AND user_id = ?', [$store['id'], (int) $userId]);
        flash('success', 'Доступ клиента к магазину «' . $store['name'] . '» закрыт.');
        redirect('/app/stores#store-' . (int) $store['id']);
    }

    public function toggle(string $id): never
    {
        Auth::requireOwner();
        $store = $this->own($id);
        DB::exec('UPDATE stores SET is_active = 1 - is_active WHERE id = ?', [$store['id']]);
        flash('success', (int) $store['is_active'] ? 'Синхронизация магазина приостановлена.' : 'Синхронизация магазина возобновлена.');
        redirect('/app/stores');
    }

    public function resync(string $id): never
    {
        Auth::requireOwner();
        $store = $this->own($id);
        DB::exec('UPDATE stores SET feed_state = NULL, feed_next_at = NULL, mp_next_at = NULL, stats_cursor = NULL,
            stats_next_at = NULL, sales_cursor = NULL, sales_next_at = NULL, cards_next_at = NULL, last_error = NULL WHERE id = ?', [$store['id']]);
        flash('success', 'Полная перезагрузка заказов запущена. Это займёт несколько минут.');
        redirect('/app/stores');
    }

    public function delete(string $id): never
    {
        Auth::requireOwner();
        $store = $this->own($id);
        DB::exec('DELETE FROM stores WHERE id = ?', [$store['id']]);
        flash('success', 'Магазин и его заказы удалены. Токен стёрт из базы.');
        redirect('/app/stores');
    }
}
