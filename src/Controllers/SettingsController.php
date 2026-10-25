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
        return view('app/settings', [
            'title'    => 'Настройки',
            'user'     => $user,
            'plan'     => Plans::forUser($user),
            'tgEnabled'=> Telegram::enabled(),
            'nav'      => 'settings',
        ], 'app');
    }

    public function profile(): never
    {
        $user = Auth::require();
        DB::exec('UPDATE users SET name = ? WHERE id = ?', [mb_substr((string) input('name', ''), 0, 120), $user['id']]);
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
