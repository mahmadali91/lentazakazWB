<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Services\Cron;
use App\Services\Plans;
use App\Services\Telegram;
use App\Services\YooKassa;

/** Админ-панель: клиенты, сроки тарифов, ручная выдача доступа, платежи, состояние синхронизации. */
final class AdminController
{
    /** Фильтры списка клиентов: название и SQL-условие (u — users). */
    public const FILTERS = [
        'all'      => ['Все', '1 = 1'],
        'active'   => ['Оплачен', "u.plan_code <> 'free' AND u.plan_expires_at > NOW() AND EXISTS (SELECT 1 FROM payments pp WHERE pp.user_id = u.id AND pp.status IN ('succeeded','manual'))"],
        'trial'    => ['Пробный', "u.plan_code <> 'free' AND u.plan_expires_at > NOW() AND NOT EXISTS (SELECT 1 FROM payments pp WHERE pp.user_id = u.id AND pp.status IN ('succeeded','manual'))"],
        'expiring' => ['Истекает ≤ 7 дн', "u.plan_code <> 'free' AND u.plan_expires_at > NOW() AND u.plan_expires_at <= NOW() + INTERVAL 7 DAY"],
        'expired'  => ['Истёк', "u.plan_code <> 'free' AND u.plan_expires_at <= NOW()"],
        'free'     => ['Бесплатный', "u.plan_code = 'free'"],
        'blocked'  => ['Заблокирован', 'u.is_blocked = 1'],
    ];

    private function base(array $admin): array
    {
        return ['user' => $admin, 'plan' => Plans::forUser($admin), 'nav' => 'admin'];
    }

    public function index(): string
    {
        $admin = Auth::requireAdmin();
        $today = date('Y-m-d 00:00:00');
        $stats = [
            'users'        => (int) DB::value('SELECT COUNT(*) FROM users WHERE owner_id IS NULL'),
            'users_today'  => (int) DB::value('SELECT COUNT(*) FROM users WHERE owner_id IS NULL AND created_at >= ?', [$today]),
            'paying'       => (int) DB::value('SELECT COUNT(*) FROM users u WHERE u.owner_id IS NULL AND ' . self::FILTERS['active'][1]),
            'trial'        => (int) DB::value('SELECT COUNT(*) FROM users u WHERE u.owner_id IS NULL AND ' . self::FILTERS['trial'][1]),
            'stores'       => (int) DB::value('SELECT COUNT(*) FROM stores'),
            'orders_today' => (int) DB::value('SELECT COUNT(*) FROM orders WHERE order_created_at >= ?', [$today]),
            'revenue_30'   => (float) DB::value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status IN ('succeeded','manual') AND paid_at >= ?", [date('Y-m-d H:i:s', time() - 30 * 86400)]),
        ];
        // Графики за 30 дней: регистрации, выручка, заказы
        $series = ['users' => [], 'revenue' => [], 'orders' => []];
        $from = date('Y-m-d', strtotime('-29 days'));
        $maps = [
            'users'   => DB::all("SELECT DATE(created_at) d, COUNT(*) v FROM users WHERE owner_id IS NULL AND created_at >= ? GROUP BY d", [$from]),
            'revenue' => DB::all("SELECT DATE(paid_at) d, SUM(amount) v FROM payments WHERE status IN ('succeeded','manual') AND paid_at >= ? GROUP BY d", [$from]),
            'orders'  => DB::all('SELECT DATE(order_created_at) d, COUNT(*) v FROM orders WHERE order_created_at >= ? GROUP BY d', [$from]),
        ];
        foreach ($maps as $k => $rows) {
            $m = array_column($rows, 'v', 'd');
            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-$i days"));
                $series[$k][] = ['d' => date('d.m', strtotime($d)), 'v' => (float) ($m[$d] ?? 0)];
            }
        }
        return view('admin/index', $this->base($admin) + [
            'series'   => $series,
            'title'    => 'Дашборд',
            'stats'    => $stats,
            'cronLast' => DB::setting('cron_last_run'),
            'expiring' => DB::all('SELECT u.*, (SELECT COUNT(*) FROM stores s WHERE s.user_id = u.id) AS stores_count FROM users u
                            WHERE u.owner_id IS NULL AND ' . self::FILTERS['expiring'][1] . ' ORDER BY u.plan_expires_at LIMIT 20'),
            'errors'   => DB::all('SELECT s.id, s.name, s.last_error, s.last_error_at, u.email, u.id AS user_id FROM stores s JOIN users u ON u.id = s.user_id
                            WHERE s.last_error IS NOT NULL ORDER BY s.last_error_at DESC LIMIT 20'),
            'log'      => DB::all('SELECT l.*, s.name FROM sync_log l JOIN stores s ON s.id = l.store_id ORDER BY l.id DESC LIMIT 30'),
        ], 'app');
    }

    public function users(): string
    {
        $admin = Auth::requireAdmin();
        $q = (string) input('q', '');
        $filter = (string) input('f', 'all');
        if (!isset(self::FILTERS[$filter])) {
            $filter = 'all';
        }
        $where = 'u.owner_id IS NULL AND ' . self::FILTERS[$filter][1];
        $params = [];
        if ($q !== '') {
            $where .= ' AND (u.email LIKE :q1 OR u.name LIKE :q2 OR u.note LIKE :q3 OR u.phone LIKE :q7 OR EXISTS (SELECT 1 FROM stores s2 WHERE s2.user_id = u.id AND (s2.name LIKE :q4 OR s2.seller_name LIKE :q5 OR s2.seller_tin = :q6)))';
            $like = '%' . $q . '%';
            $params = ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $q, 'q7' => ($d = preg_replace('/\D/', '', $q)) !== '' ? '%' . $d . '%' : '-'];
        }
        $users = DB::all("SELECT u.*,
                (SELECT COUNT(*) FROM stores s WHERE s.user_id = u.id) AS stores_count,
                (SELECT COUNT(*) FROM users m WHERE m.owner_id = u.id) AS members_count,
                (SELECT COUNT(*) FROM orders o JOIN stores s ON s.id = o.store_id WHERE s.user_id = u.id AND o.order_created_at >= NOW() - INTERVAL 7 DAY) AS orders_7d,
                (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.user_id = u.id AND p.status IN ('succeeded','manual')) AS paid_total,
                EXISTS (SELECT 1 FROM payments p WHERE p.user_id = u.id AND p.status IN ('succeeded','manual')) AS has_paid
            FROM users u WHERE $where ORDER BY u.id DESC LIMIT 300", $params);
        $counts = [];
        foreach (self::FILTERS as $k => [, $sql]) {
            $counts[$k] = (int) DB::value("SELECT COUNT(*) FROM users u WHERE u.owner_id IS NULL AND $sql");
        }
        return view('admin/users', $this->base($admin) + [
            'title'   => 'Клиенты',
            'users'   => $users,
            'plans'   => Plans::all(),
            'q'       => $q,
            'filter'  => $filter,
            'counts'  => $counts,
        ], 'app');
    }

    public function show(string $id): string
    {
        $admin = Auth::requireAdmin();
        $client = $this->find($id);
        $owner = $client['owner_id'] ? DB::one('SELECT id, email FROM users WHERE id = ?', [$client['owner_id']]) : null;
        return view('admin/user', $this->base($admin) + [
            'title'    => 'Клиент ' . $client['email'],
            'client'   => $client,
            'owner'    => $owner,
            'cplan'    => Plans::forUser($client),
            'hasPaid'  => (bool) DB::value("SELECT 1 FROM payments WHERE user_id = ? AND status IN ('succeeded','manual') LIMIT 1", [$client['id']]),
            'plans'    => Plans::all(),
            'stores'   => DB::all('SELECT s.*, (SELECT COUNT(*) FROM orders o WHERE o.store_id = s.id) AS orders_total,
                            (SELECT COUNT(*) FROM orders o WHERE o.store_id = s.id AND o.order_created_at >= NOW() - INTERVAL 7 DAY) AS orders_7d
                            FROM stores s WHERE s.user_id = ? ORDER BY s.id', [$client['id']]),
            'members'  => DB::all('SELECT id, email, name, last_login_at FROM users WHERE owner_id = ? ORDER BY id', [$client['id']]),
            'payments' => DB::all('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 50', [$client['id']]),
        ], 'app');
    }

    private function find(string $id): array
    {
        $u = DB::one('SELECT * FROM users WHERE id = ?', [(int) $id]);
        if (!$u) {
            flash('error', 'Пользователь не найден.');
            redirect('/admin/users');
        }
        return $u;
    }

    /** Создание клиента администратором: логин (email или имя) + пароль + тариф на N дней. */
    public function create(): never
    {
        Auth::requireAdmin();
        $login = normalize_login((string) input('login', ''));
        $password = (string) ($_POST['password'] ?? '');
        $planCode = (string) input('plan_code', 'free');
        $days = max(0, min(3650, (int) input('days', 30)));
        $phone = normalize_phone((string) input('phone', ''));
        if ($login === null || mb_strlen($password) < 8) {
            flash('error', 'Укажите логин (email или латиница/цифры, от 3 символов) и пароль не короче 8 символов.');
            redirect('/admin/users');
        }
        if ($phone === null) {
            flash('error', 'Укажите телефон клиента, например +7 900 123-45-67.');
            redirect('/admin/users');
        }
        if (DB::value('SELECT id FROM users WHERE email = ?', [$login])) {
            flash('error', 'Логин «' . $login . '» уже занят.');
            redirect('/admin/users');
        }
        if (!isset(Plans::all()[$planCode])) {
            $planCode = 'free';
        }
        $id = DB::insert('users', [
            'email'           => $login,
            'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
            'name'            => mb_substr((string) input('name', ''), 0, 120),
            'phone'           => $phone,
            'note'            => mb_substr((string) input('note', ''), 0, 500) ?: null,
            'plan_code'       => $planCode,
            'plan_expires_at' => $planCode !== 'free' && $days > 0 ? date('Y-m-d 23:59:59', strtotime("+$days day")) : null,
            'created_at'      => now(),
        ]);
        if ($planCode !== 'free' && $days > 0) {
            $this->manualPayment($id, $planCode, 0, (float) str_replace(',', '.', (string) input('amount', '0')));
        }
        flash('success', "Клиент создан. Данные для входа: " . url('/login') . " · логин «{$login}» · пароль, который вы указали.");
        redirect('/admin/users/' . $id);
    }

    /** Изменение тарифа, срока, роли, блокировки и заметки. */
    public function update(string $id): never
    {
        $admin = Auth::requireAdmin();
        $target = $this->find($id);
        $plan = (string) input('plan_code', $target['plan_code']);
        if (!isset(Plans::all()[$plan])) {
            $plan = 'free';
        }
        $expires = (string) input('plan_expires_at', '');
        $expiresAt = $expires !== '' && strtotime($expires) ? date('Y-m-d 23:59:59', strtotime($expires)) : null;
        $self = (int) $target['id'] === (int) $admin['id'];
        DB::update('users', [
            'plan_code'       => $plan,
            'plan_expires_at' => $plan === 'free' ? null : $expiresAt,
            'is_blocked'      => $self ? 0 : (int) !empty($_POST['is_blocked']),
            'role'            => $self ? 'admin' : (!empty($_POST['is_admin']) ? 'admin' : 'user'),
            'note'            => array_key_exists('note', $_POST) ? (mb_substr((string) input('note', ''), 0, 500) ?: null) : $target['note'],
            'phone'           => array_key_exists('phone', $_POST) ? (normalize_phone((string) input('phone', '')) ?? $target['phone']) : $target['phone'],
        ], 'id = :id', ['id' => $target['id']]);
        if ($plan !== 'free' && $expiresAt && ($target['plan_code'] !== $plan || $target['plan_expires_at'] !== $expiresAt)) {
            $this->manualPayment((int) $target['id'], $plan, 0, (float) str_replace(',', '.', (string) input('amount', '0')));
        }
        flash('success', 'Клиент ' . $target['email'] . ' обновлён.');
        redirect($this->back('/admin/users/' . $target['id']));
    }

    /** Быстрое продление: +N дней или месяцев к текущему сроку (или от сегодня, если срок истёк / тариф другой). */
    public function extend(string $id): never
    {
        Auth::requireAdmin();
        $target = $this->find($id);
        $plan = (string) input('plan_code', $target['plan_code'] !== 'free' ? $target['plan_code'] : 'start');
        if (!isset(Plans::all()[$plan]) || $plan === 'free') {
            $plan = 'start';
        }
        $period = (string) input('period', '1m');
        if (!preg_match('/^(\d{1,3})([dm])$/', $period, $m)) {
            $m = [0, 1, 'm'];
        }
        $from = time();
        if ($target['plan_code'] === $plan && $target['plan_expires_at'] && strtotime($target['plan_expires_at']) > time()) {
            $from = strtotime($target['plan_expires_at']);
        }
        $expires = date('Y-m-d 23:59:59', strtotime('+' . (int) $m[1] . ($m[2] === 'd' ? ' day' : ' month'), $from));
        DB::exec('UPDATE users SET plan_code = ?, plan_expires_at = ? WHERE id = ?', [$plan, $expires, $target['id']]);
        $this->manualPayment((int) $target['id'], $plan, $m[2] === 'm' ? (int) $m[1] : 0, (float) str_replace(',', '.', (string) input('amount', '0')));
        flash('success', $target['email'] . ': тариф «' . Plans::get($plan)['name'] . '» до ' . date_ru($expires, false) . '.');
        redirect($this->back('/admin/users/' . $target['id']));
    }

    /** Куда вернуться после действия (только внутри админки). */
    private function back(string $default): string
    {
        $back = (string) input('back', '');
        return preg_match('#^/admin(/[A-Za-z0-9/_?=&%.-]*)?$#', $back) ? $back : $default;
    }

    private function manualPayment(int $userId, string $plan, int $months, float $amount): void
    {
        DB::insert('payments', [
            'user_id'    => $userId,
            'plan_code'  => $plan,
            'months'     => max(0, min(255, $months)),
            'amount'     => max(0, $amount),
            'provider'   => 'admin',
            'status'     => 'manual',
            'created_at' => now(),
            'paid_at'    => now(),
        ]);
    }

    public function password(string $id): never
    {
        Auth::requireAdmin();
        $target = $this->find($id);
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($password) < 8) {
            flash('error', 'Пароль должен быть не короче 8 символов.');
        } else {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $target['id']]);
            flash('success', 'Новый пароль для ' . $target['email'] . ' сохранён. Передайте его клиенту.');
        }
        redirect('/admin/users/' . $target['id']);
    }

    public function delete(string $id): never
    {
        $admin = Auth::requireAdmin();
        $target = $this->find($id);
        if ((int) $target['id'] === (int) $admin['id']) {
            flash('error', 'Нельзя удалить самого себя.');
            redirect('/admin/users/' . $target['id']);
        }
        DB::exec('DELETE FROM users WHERE id = ?', [$target['id']]);
        flash('success', 'Аккаунт ' . $target['email'] . ' удалён вместе с магазинами, заказами и сотрудниками.');
        redirect('/admin/users');
    }

    /** «Войти как клиент» — посмотреть кабинет его глазами (для поддержки). */
    public function impersonate(string $id): never
    {
        $admin = Auth::requireAdmin();
        $target = $this->find($id);
        $_SESSION['impersonator'] = (int) $admin['id'];
        $_SESSION['uid'] = (int) $target['id'];
        Auth::refresh();
        redirect('/app');
    }

    public function stopImpersonate(): never
    {
        $adminId = Auth::impersonator();
        $clientId = Auth::id();
        if ($adminId && DB::value("SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_blocked = 0", [$adminId])) {
            $_SESSION['uid'] = $adminId;
            unset($_SESSION['impersonator']);
            redirect('/admin/users/' . $clientId);
        }
        redirect('/app');
    }

    public function payments(): string
    {
        $admin = Auth::requireAdmin();
        return view('admin/payments', $this->base($admin) + [
            'title'    => 'Платежи',
            'payments' => DB::all('SELECT p.*, u.email FROM payments p JOIN users u ON u.id = p.user_id ORDER BY p.id DESC LIMIT 300'),
        ], 'app');
    }

    /** Настройки сервиса: контакты поддержки и Telegram-бот (хранятся в БД поверх config.php, токен — зашифрованным). */
    public function settings(): string
    {
        $admin = Auth::requireAdmin();
        $bot = null;
        if (Telegram::enabled()) {
            $me = Telegram::api('getMe');
            $hook = Telegram::api('getWebhookInfo');
            $bot = ['ok' => $me !== null, 'error' => $me === null ? Telegram::$lastError : '', 'username' => $me['result']['username'] ?? null, 'name' => $me['result']['first_name'] ?? null,
                'webhook' => $hook['result']['url'] ?? '', 'linked' => (int) DB::value('SELECT COUNT(*) FROM users WHERE tg_chat_id IS NOT NULL')];
        }
        return view('admin/settings', $this->base($admin) + [
            'title'    => 'Настройки сервиса',
            'tab'      => 'settings',
            'phone'    => (string) config('app.support_phone', ''),
            'email'    => (string) config('app.support_email', ''),
            'botUser'  => (string) config('telegram.bot_username', ''),
            'hasToken' => (string) config('telegram.bot_token', '') !== '',
            'bot'      => $bot,
            'ykShop'   => (string) config('yookassa.shop_id', ''),
            'ykHasKey' => (string) config('yookassa.secret_key', '') !== '',
            'ykReceipt' => (bool) config('yookassa.send_receipt', false),
            'ykVat'    => (int) config('yookassa.vat_code', 1),
            'ykOk'     => YooKassa::enabled() ? $this->yookassaCheck() : null,
        ], 'app');
    }

    /** Проверка ключей ЮKassa для страницы настроек: ['ok' => bool, 'text' => string]. */
    private function yookassaCheck(): array
    {
        try {
            $me = YooKassa::me();
            return ['ok' => true, 'text' => 'Магазин ' . ($me['account_id'] ?? '') . (!empty($me['test']) ? ' (тестовый)' : '') . ', статус: ' . ($me['status'] ?? '—')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'text' => 'ЮKassa не приняла ключи: проверьте shopId и секретный ключ (подробности — storage/logs/payments.log).'];
        }
    }

    public function saveSettings(): never
    {
        Auth::requireAdmin();
        $phone = trim((string) input('support_phone', ''));
        $email = trim((string) input('support_email', ''));
        if ($phone !== '' && normalize_phone($phone) === null) {
            flash('error', 'Телефон поддержки указан неверно.');
            redirect('/admin/settings');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Email поддержки указан неверно.');
            redirect('/admin/settings');
        }
        DB::setSetting('cfg.app.support_phone', $phone !== '' ? normalize_phone($phone) : '');
        DB::setSetting('cfg.app.support_email', $email);
        $username = ltrim(trim((string) input('bot_username', '')), '@');
        $username = preg_replace('~^(https?://)?t\.me/~i', '', $username);
        $token = trim((string) input('bot_token', ''));
        if ($token !== '' && !preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,50}$/', $token)) {
            flash('error', 'Токен бота выглядит неверно. Скопируйте его из @BotFather целиком: 123456789:AA…');
            redirect('/admin/settings');
        }
        if ($token !== '') {
            Config::set('telegram.bot_token', $token);
            $me = Telegram::api('getMe');
            if ($me === null) {
                flash('error', 'Не удалось проверить токен в Telegram (getMe): ' . Telegram::$lastError . '. Проверьте токен в @BotFather.');
                redirect('/admin/settings');
            }
            $username = (string) ($me['result']['username'] ?? $username);
            // Привязка аккаунтов работает через getUpdates — снимаем вебхук, если он был установлен
            Telegram::api('deleteWebhook', ['drop_pending_updates' => false]);
            DB::setSetting('cfg.telegram.bot_token', Crypto::encrypt($token));
        }
        if ($username !== '' && !preg_match('/^[A-Za-z0-9_]{5,32}$/', $username)) {
            flash('error', 'Имя бота — латиница, цифры и _, например WBLinkoz_bot.');
            redirect('/admin/settings');
        }
        DB::setSetting('cfg.telegram.bot_username', $username);

        // ЮKassa: shopId, секретный ключ (зашифрован; пусто — не менять), чек 54-ФЗ и ставка НДС
        $shop = preg_replace('/\D/', '', (string) input('yk_shop_id', ''));
        $secret = trim((string) input('yk_secret', ''));
        if ($secret !== '' && !preg_match('/^(live|test)_[A-Za-z0-9_-]{20,}$/', $secret)) {
            flash('error', 'Секретный ключ ЮKassa начинается с live_ или test_. Скопируйте его из личного кабинета ЮKassa → Интеграция → Ключи API.');
            redirect('/admin/settings');
        }
        DB::setSetting('cfg.yookassa.shop_id', $shop);
        if ($secret !== '') {
            DB::setSetting('cfg.yookassa.secret_key', Crypto::encrypt($secret));
        }
        if (!empty($_POST['yk_remove'])) {
            DB::setSetting('cfg.yookassa.secret_key', '');
            DB::setSetting('cfg.yookassa.shop_id', '');
        }
        DB::setSetting('cfg.yookassa.send_receipt', !empty($_POST['yk_receipt']) ? '1' : '0');
        DB::setSetting('cfg.yookassa.vat_code', (string) max(1, min(10, (int) input('yk_vat', 1))));
        if (!empty($_POST['bot_remove'])) {
            DB::setSetting('cfg.telegram.bot_token', '');
        }
        flash('success', 'Настройки сохранены.' . ($token !== '' ? ' Бот @' . $username . ' подключён.' : ''));
        redirect('/admin/settings');
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
