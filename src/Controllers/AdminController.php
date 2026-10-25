<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Cron;
use App\Services\Plans;

final class AdminController
{
    public function index(): string
    {
        $user = Auth::requireAdmin();
        $today = date('Y-m-d 00:00:00');
        $stats = [
            'users'        => (int) DB::value('SELECT COUNT(*) FROM users'),
            'users_today'  => (int) DB::value('SELECT COUNT(*) FROM users WHERE created_at >= ?', [$today]),
            'paying'       => (int) DB::value("SELECT COUNT(*) FROM users WHERE plan_code <> 'free' AND plan_expires_at > NOW()
                                AND id IN (SELECT user_id FROM payments WHERE status IN ('succeeded','manual'))"),
            'stores'       => (int) DB::value('SELECT COUNT(*) FROM stores'),
            'orders_today' => (int) DB::value('SELECT COUNT(*) FROM orders WHERE order_created_at >= ?', [$today]),
            'revenue_30'   => (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'succeeded' AND paid_at >= ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]),
        ];
        return view('admin/index', [
            'title'    => 'Админ-панель',
            'user'     => $user,
            'plan'     => Plans::forUser($user),
            'stats'    => $stats,
            'cronLast' => DB::setting('cron_last_run'),
            'errors'   => DB::all('SELECT s.id, s.name, s.last_error, s.last_error_at, u.email FROM stores s JOIN users u ON u.id = s.user_id
                            WHERE s.last_error IS NOT NULL ORDER BY s.last_error_at DESC LIMIT 20'),
            'log'      => DB::all('SELECT l.*, s.name FROM sync_log l JOIN stores s ON s.id = l.store_id ORDER BY l.id DESC LIMIT 30'),
            'nav'      => 'admin',
        ], 'app');
    }

    public function users(): string
    {
        $user = Auth::requireAdmin();
        $q = (string) input('q', '');
        $params = [];
        $where = '1=1';
        if ($q !== '') {
            $where = '(u.email LIKE ? OR u.name LIKE ?)';
            $params = ['%' . $q . '%', '%' . $q . '%'];
        }
        $users = DB::all("SELECT u.*, (SELECT COUNT(*) FROM stores s WHERE s.user_id = u.id) AS stores_count
            FROM users u WHERE $where ORDER BY u.id DESC LIMIT 200", $params);
        return view('admin/users', [
            'title' => 'Пользователи',
            'user'  => $user,
            'plan'  => Plans::forUser($user),
            'users' => $users,
            'plans' => Plans::all(),
            'q'     => $q,
            'nav'   => 'admin',
        ], 'app');
    }

    public function updateUser(string $id): never
    {
        $admin = Auth::requireAdmin();
        $target = DB::one('SELECT * FROM users WHERE id = ?', [(int) $id]);
        if (!$target) {
            redirect('/admin/users');
        }
        $plan = (string) input('plan_code', 'free');
        if (!isset(Plans::all()[$plan])) {
            $plan = 'free';
        }
        $expires = (string) input('plan_expires_at', '');
        $expiresAt = $expires !== '' ? date('Y-m-d 23:59:59', strtotime($expires)) : null;
        $data = [
            'plan_code'       => $plan,
            'plan_expires_at' => $plan === 'free' ? null : $expiresAt,
            'is_blocked'      => (int) $target['id'] === (int) $admin['id'] ? 0 : (int) !empty($_POST['is_blocked']),
            'role'            => (int) $target['id'] === (int) $admin['id'] ? 'admin' : (!empty($_POST['is_admin']) ? 'admin' : 'user'),
        ];
        DB::update('users', $data, 'id = :id', ['id' => $target['id']]);
        if ($plan !== 'free' && $expiresAt && ($target['plan_code'] !== $plan || $target['plan_expires_at'] !== $expiresAt)) {
            DB::insert('payments', [
                'user_id'    => $target['id'],
                'plan_code'  => $plan,
                'months'     => 0,
                'amount'     => 0,
                'provider'   => 'admin',
                'status'     => 'manual',
                'created_at' => now(),
                'paid_at'    => now(),
            ]);
        }
        flash('success', 'Пользователь ' . $target['email'] . ' обновлён.');
        redirect('/admin/users?q=' . urlencode((string) input('q', '')));
    }

    public function payments(): string
    {
        $user = Auth::requireAdmin();
        return view('admin/payments', [
            'title'    => 'Платежи',
            'user'     => $user,
            'plan'     => Plans::forUser($user),
            'payments' => DB::all('SELECT p.*, u.email FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 300'),
            'nav'      => 'admin',
        ], 'app');
    }

    public function runCron(): never
    {
        Auth::requireAdmin();
        session_write_close();
        $report = Cron::run(25);
        @session_start();
        flash('success', 'Синхронизация выполнена: ' . json_encode($report, JSON_UNESCAPED_UNICODE));
        redirect('/admin');
    }
}
