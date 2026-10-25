<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Services\Plans;
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
        $account = Auth::account();
        return view('app/settings', [
            'title'    => 'Настройки',
            'user'     => $user,
            'account'  => $account,
            'plan'     => Plans::forUser($account),
            'members'  => Auth::isMember() ? [] : DB::all('SELECT id, email, name, last_login_at, created_at FROM users WHERE owner_id = ? ORDER BY id', [$user['id']]),
            'tgEnabled'=> Telegram::enabled(),
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

    public function telegram(): never
    {
        $user = Auth::require();
        $events = array_values(array_intersect(array_keys(self::TG_EVENTS), (array) ($_POST['events'] ?? [])));
        DB::exec('UPDATE users SET tg_events = ? WHERE id = ?', [implode(',', $events), $user['id']]);
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
