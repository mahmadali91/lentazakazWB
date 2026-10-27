<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Max;
use App\Services\Plans;
use App\Services\StoreService;
use App\Services\Telegram;

final class SettingsController
{
    public const TG_EVENTS = [
        'created' => 'Новые заказы',
        'buyout'  => 'Выкупы',
        'cancel'  => 'Отмены',
        'return'  => 'Возвраты',
    ];

    public function index(): string
    {
        $user = Auth::require();
        if (Telegram::enabled() && !$user['tg_chat_id'] && !$user['tg_link_code']) {
            $code = random_code(9);
            DB::exec('UPDATE users SET tg_link_code = ? WHERE id = ?', [$code, $user['id']]);
            $user['tg_link_code'] = $code;
        }
        if (Max::enabled() && !$user['max_user_id'] && !$user['max_link_code']) {
            $code = random_code(9);
            DB::exec('UPDATE users SET max_link_code = ? WHERE id = ?', [$code, $user['id']]);
            $user['max_link_code'] = $code;
        }
        $account = Auth::account();
        $plan = Plans::forUser($account);
        $visible = StoreService::visibleIds((int) $account['id'], $plan, $user);
        return view('app/settings', [
            'title'    => 'Настройки',
            'user'     => $user,
            'account'  => $account,
            'plan'     => $plan,
            // Магазины, доступные пользователю, — для выбора уведомлений; все магазины кабинета — для выдачи доступа сотрудникам
            'myStores' => $visible ? DB::all('SELECT id, name FROM stores WHERE id IN (' . implode(',', $visible) . ') ORDER BY id') : [],
            'allStores'=> Auth::isMember() ? [] : DB::all('SELECT id, name FROM stores WHERE user_id = ? ORDER BY id', [$user['id']]),
            'members'  => Auth::isMember() ? [] : DB::all('SELECT id, email, name, store_access, last_login_at, created_at FROM users WHERE owner_id = ? ORDER BY id', [$user['id']]),
            'tgEnabled'=> Telegram::enabled(),
            'maxEnabled'=> Max::enabled(),
            'nav'      => 'settings',
        ], 'app');
    }

    /** Профиль: тариф, переходы в разделы и выход (главное для мобильного меню). */
    public function profilePage(): string
    {
        $user = Auth::require();
        $account = Auth::account();
        return view('app/profile', [
            'title'   => 'Профиль',
            'user'    => $user,
            'account' => $account,
            'plan'    => Plans::forUser($account),
            'nav'     => 'profile',
        ], 'app');
    }

    // ---------------------------------------------------------------- сотрудники

    public function teamAdd(): never
    {
        $owner = Auth::requireOwner();
        $plan = Plans::forUser($owner);
        $count = (int) DB::value('SELECT COUNT(*) FROM users WHERE owner_id = ?', [$owner['id']]);
        if ($count >= (int) ($plan['team'] ?? 0)) {
            flash('error', 'На тарифе «' . $plan['name'] . '» можно добавить сотрудников: ' . (int) ($plan['team'] ?? 0) . '. Перейдите на тариф выше.');
            redirect('/app/settings#team');
        }
        $login = normalize_login((string) input('login', ''));
        $password = (string) ($_POST['password'] ?? '');
        if ($login === null) {
            flash('error', 'Логин — это email или латиница/цифры/точка/дефис (от 3 символов).');
            redirect('/app/settings#team');
        }
        if (mb_strlen($password) < 8) {
            flash('error', 'Пароль сотрудника должен быть не короче 8 символов.');
            redirect('/app/settings#team');
        }
        if (DB::value('SELECT id FROM users WHERE email = ?', [$login])) {
            flash('error', 'Логин «' . $login . '» уже занят.');
            redirect('/app/settings#team');
        }
        DB::insert('users', [
            'email'         => $login,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name'          => mb_substr((string) input('name', ''), 0, 120),
            'owner_id'      => $owner['id'],
            'store_access'  => self::accessInput((int) $owner['id']),
            'plan_code'     => 'free',
            'created_at'    => now(),
        ]);
        flash('success', 'Сотрудник добавлен. Передайте ему адрес сайта, логин «' . $login . '» и пароль.');
        redirect('/app/settings#team');
    }

    private function member(string $id): array
    {
        $owner = Auth::requireOwner();
        $m = DB::one('SELECT * FROM users WHERE id = ? AND owner_id = ?', [(int) $id, $owner['id']]);
        if (!$m) {
            flash('error', 'Сотрудник не найден.');
            redirect('/app/settings#team');
        }
        return $m;
    }

    public function teamPassword(string $id): never
    {
        $m = $this->member($id);
        $password = (string) ($_POST['password'] ?? '');
        if (mb_strlen($password) < 8) {
            flash('error', 'Пароль должен быть не короче 8 символов.');
        } else {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $m['id']]);
            flash('success', 'Пароль сотрудника ' . $m['email'] . ' изменён.');
        }
        redirect('/app/settings#team');
    }

    /** Доступ сотрудника (клиента менеджера) к магазинам кабинета. */
    public function teamAccess(string $id): never
    {
        $m = $this->member($id);
        DB::exec('UPDATE users SET store_access = ? WHERE id = ?', [self::accessInput((int) $m['owner_id']), $m['id']]);
        flash('success', 'Доступ ' . $m['email'] . ' к магазинам сохранён.');
        redirect('/app/settings#team');
    }

    /** Из формы: «все магазины» → NULL, иначе JSON выбранных магазинов кабинета. */
    private static function accessInput(int $ownerId): ?string
    {
        if (($_POST['access'] ?? 'all') === 'all') {
            return null;
        }
        $own = array_map('intval', array_column(DB::all('SELECT id FROM stores WHERE user_id = ?', [$ownerId]), 'id'));
        return json_encode(array_values(array_intersect($own, array_map('intval', (array) ($_POST['stores'] ?? [])))));
    }

    public function teamDelete(string $id): never
    {
        $m = $this->member($id);
        DB::exec('DELETE FROM users WHERE id = ?', [$m['id']]);
        flash('success', 'Доступ сотрудника ' . $m['email'] . ' удалён.');
        redirect('/app/settings#team');
    }

    public function profile(): never
    {
        $user = Auth::require();
        $phone = normalize_phone((string) input('phone', ''));
        if ($phone === null && (trim((string) input('phone', '')) !== '' || Auth::needsPhone($user))) {
            flash('error', 'Укажите номер телефона, например +7 900 123-45-67.');
            redirect('/app/settings#profile');
        }
        DB::exec('UPDATE users SET name = ?, phone = COALESCE(?, phone) WHERE id = ?', [mb_substr((string) input('name', ''), 0, 120), $phone, $user['id']]);
        flash('success', 'Профиль сохранён.');
        redirect('/app/settings');
    }

    public function password(): never
    {
        $user = Auth::require();
        $current = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['password'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Текущий пароль указан неверно.');
        } elseif (mb_strlen($new) < 8) {
            flash('error', 'Новый пароль должен быть не короче 8 символов.');
        } else {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('success', 'Пароль изменён.');
        }
        redirect('/app/settings');
    }

    /** Показывать суммы в рублях по курсу ЦБ (для магазинов не в рублях). */
    public function currency(): never
    {
        $owner = Auth::requireOwner();
        $on = isset($_POST['show_rub']) ? (int) (bool) $_POST['show_rub'] : 1 - (int) ($owner['show_rub'] ?? 0);
        DB::exec('UPDATE users SET show_rub = ? WHERE id = ?', [$on, $owner['id']]);
        flash('success', $on ? 'Суммы показываются в рублях по курсу ЦБ РФ.' : 'Суммы показываются в валюте магазинов.');
        $back = (string) input('back', '/app/settings');
        redirect(str_starts_with($back, '/app') ? $back : '/app/settings');
    }

    public function telegram(): never
    {
        $user = Auth::require();
        $events = array_values(array_intersect(array_keys(self::TG_EVENTS), (array) ($_POST['events'] ?? [])));
        // Магазины для уведомлений: все отмеченные доступные — значит «все» (NULL), чтобы новые магазины тоже попадали
        $tgStores = null;
        if (isset($_POST['tg_stores_on'])) {
            $account = Auth::account();
            $visible = StoreService::visibleIds((int) $account['id'], Plans::forUser($account), $user);
            $chosen = array_values(array_intersect($visible, array_map('intval', (array) ($_POST['tg_stores'] ?? []))));
            $tgStores = count($chosen) === count($visible) ? null : json_encode($chosen);
        }
        DB::exec('UPDATE users SET tg_events = ?, tg_stores = ? WHERE id = ?', [implode(',', $events), $tgStores, $user['id']]);
        flash('success', 'Настройки уведомлений сохранены.');
        redirect('/app/settings');
    }

    public function telegramUnlink(): never
    {
        $user = Auth::require();
        DB::exec('UPDATE users SET tg_chat_id = NULL, tg_link_code = NULL WHERE id = ?', [$user['id']]);
        flash('success', 'Telegram отключён.');
        redirect('/app/settings');
    }

    public function maxUnlink(): never
    {
        $user = Auth::require();
        DB::exec('UPDATE users SET max_user_id = NULL, max_link_code = NULL WHERE id = ?', [$user['id']]);
        flash('success', 'MAX отключён.');
        redirect('/app/settings');
    }

    public function maxTest(): never
    {
        $user = Auth::require();
        if ($user['max_user_id'] && Max::send($user['max_user_id'], '🔔 Тестовое уведомление от <b>' . e(config('app.name')) . '</b>. Всё работает!')) {
            flash('success', 'Тестовое сообщение отправлено в MAX.');
        } else {
            flash('error', 'Не удалось отправить сообщение в MAX' . (Max::$lastError !== '' ? ': ' . Max::$lastError : '') . '.');
        }
        redirect('/app/settings');
    }

    public function telegramTest(): never
    {
        $user = Auth::require();
        if ($user['tg_chat_id'] && Telegram::send($user['tg_chat_id'], '🔔 Тестовое уведомление от <b>' . e(config('app.name')) . '</b>. Всё работает!')) {
            flash('success', 'Тестовое сообщение отправлено.');
        } else {
            flash('error', 'Не удалось отправить сообщение. Проверьте, что бот не заблокирован.');
        }
        redirect('/app/settings');
    }
}
