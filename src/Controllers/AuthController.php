<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Mailer;
use App\Services\Plans;

final class AuthController
{
    public function loginForm(): string
    {
        if (Auth::user()) {
            redirect('/app');
        }
        return view('auth/login', ['title' => 'Вход'], 'auth');
    }

    public function login(): never
    {
        $email = mb_strtolower((string) input('email', ''));
        $password = (string) ($_POST['password'] ?? '');
        remember_input($_POST);
        if (Auth::tooManyAttempts()) {
            flash('error', 'Слишком много попыток входа. Подождите 15 минут.');
            redirect('/login');
        }
        $user = DB::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Auth::recordAttempt();
            flash('error', 'Неверный логин или пароль.');
            redirect('/login');
        }
        if ((int) $user['is_blocked']) {
            flash('error', 'Аккаунт заблокирован. Напишите в поддержку.');
            redirect('/login');
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        forget_input();
        Auth::login($user);
        \App\Services\StoreClients::enterDefault($user);
        redirect('/app');
    }

    public function registerForm(): string
    {
        if (Auth::user()) {
            redirect('/app');
        }
        return view('auth/register', ['title' => 'Регистрация'], 'auth');
    }

    public function register(): never
    {
        $email = mb_strtolower((string) input('email', ''));
        $name = mb_substr((string) input('name', ''), 0, 120);
        $password = (string) ($_POST['password'] ?? '');
        $phone = normalize_phone((string) input('phone', ''));
        remember_input($_POST);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Укажите корректный email.');
            redirect('/register');
        }
        if ($phone === null) {
            flash('error', 'Укажите номер телефона, например +7 900 123-45-67.');
            redirect('/register');
        }
        if (mb_strlen($password) < 8) {
            flash('error', 'Пароль должен быть не короче 8 символов.');
            redirect('/register');
        }
        if (empty($_POST['agree'])) {
            flash('error', 'Нужно согласие с офертой и политикой конфиденциальности.');
            redirect('/register');
        }
        if (Auth::tooManyAttempts()) {
            flash('error', 'Слишком много попыток. Подождите 15 минут.');
            redirect('/register');
        }
        Auth::recordAttempt();
        if (DB::value('SELECT id FROM users WHERE email = ?', [$email])) {
            flash('error', 'Этот email уже зарегистрирован. Войдите или восстановите пароль.');
            redirect('/register');
        }
        $trialPlan = (string) config('app.trial_plan', 'pro');
        if ((Plans::get($trialPlan)['trial'] ?? true) === false) {
            $trialPlan = 'pro'; // тариф без пробного периода (Агентство) на пробный период не выдаётся
        }
        $trialDays = (int) config('app.trial_days', 7);
        $id = DB::insert('users', [
            'email'           => $email,
            'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
            'name'            => $name,
            'phone'           => $phone,
            'plan_code'       => $trialDays > 0 ? $trialPlan : 'free',
            'plan_expires_at' => $trialDays > 0 ? date('Y-m-d H:i:s', time() + $trialDays * 86400) : null,
            'created_at'      => now(),
        ]);
        forget_input();
        Auth::login(DB::one('SELECT * FROM users WHERE id = ?', [$id]));
        if ($trialDays > 0) {
            flash('success', "Добро пожаловать! Вам доступен пробный период на $trialDays " . plural($trialDays, 'день', 'дня', 'дней') . '. Подключите магазин WB, чтобы увидеть заказы.');
        }
        redirect('/app/stores');
    }

    public function logout(): never
    {
        Auth::logout();
        redirect('/');
    }

    public function forgotForm(): string
    {
        return view('auth/forgot', ['title' => 'Восстановление пароля'], 'auth');
    }

    public function forgot(): never
    {
        $email = mb_strtolower((string) input('email', ''));
        if (Auth::tooManyAttempts()) {
            flash('error', 'Слишком много попыток. Подождите 15 минут.');
            redirect('/forgot');
        }
        Auth::recordAttempt();
        $user = DB::one('SELECT id, email FROM users WHERE email = ? AND is_blocked = 0', [$email]);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            DB::exec('DELETE FROM password_resets WHERE user_id = ?', [$user['id']]);
            DB::insert('password_resets', [
                'token_hash' => hash('sha256', $token),
                'user_id'    => $user['id'],
                'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            ]);
            Mailer::send(
                $user['email'],
                'Восстановление пароля — ' . config('app.name'),
                "Здравствуйте!\n\nЧтобы задать новый пароль, перейдите по ссылке (действует 1 час):\n"
                . url('/reset?token=' . $token)
                . "\n\nЕсли вы не запрашивали восстановление — просто проигнорируйте это письмо."
            );
        }
        flash('success', 'Если такой email зарегистрирован, мы отправили на него ссылку для восстановления пароля.');
        redirect('/login');
    }

    public function resetForm(): string
    {
        $welcome = (string) input('welcome', '') === '1';
        return view('auth/reset', ['title' => $welcome ? 'Активация кабинета' : 'Новый пароль', 'token' => (string) input('token', ''), 'welcome' => $welcome], 'auth');
    }

    public function reset(): never
    {
        $token = (string) input('token', '');
        $password = (string) ($_POST['password'] ?? '');
        $row = DB::one('SELECT * FROM password_resets WHERE token_hash = ? AND expires_at > ?', [hash('sha256', $token), now()]);
        if (!$row) {
            flash('error', 'Ссылка недействительна или устарела. Запросите восстановление ещё раз.');
            redirect('/forgot');
        }
        if (mb_strlen($password) < 8) {
            flash('error', 'Пароль должен быть не короче 8 символов.');
            redirect('/reset?token=' . urlencode($token));
        }
        DB::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $row['user_id']]);
        DB::exec('DELETE FROM password_resets WHERE user_id = ?', [$row['user_id']]);
        // Ссылка пришла на почту — сразу входим; клиент агентства без своих магазинов попадает в его кабинет
        $user = DB::one('SELECT * FROM users WHERE id = ? AND is_blocked = 0', [$row['user_id']]);
        if ($user) {
            Auth::login($user);
            \App\Services\StoreClients::enterDefault($user);
            flash('success', 'Пароль сохранён. Логин для входа — ' . $user['email'] . '.');
            redirect('/app');
        }
        flash('success', 'Пароль изменён. Войдите с новым паролем.');
        redirect('/login');
    }

    /** Подтверждение доступа к магазину агентства по ссылке из письма. */
    public function accessConfirm(): never
    {
        $row = \App\Services\StoreClients::confirm((string) input('token', ''));
        if (!$row) {
            flash('error', 'Ссылка недействительна или устарела. Попросите владельца кабинета отправить приглашение ещё раз.');
            redirect(Auth::user() ? '/app' : '/login');
        }
        self::enterAccess($row);
    }

    /** После подтверждения: открыть магазин клиенту; если в браузере вошёл другой аккаунт — выйти и предложить вход под нужным email. */
    private static function enterAccess(array $row): never
    {
        $client = DB::one('SELECT id, email FROM users WHERE id = ?', [(int) $row['user_id']]);
        $user = Auth::user();
        if ($user && (int) $user['id'] === (int) $row['user_id'] && Auth::switchAccount((int) $row['owner_id'])) {
            $_SESSION['store'] = (int) $row['store_id'];
            flash('success', 'Доступ к магазину открыт. Переключаться между кабинетами — в меню «Кабинет WB».');
            redirect('/app');
        }
        if ($user) {
            Auth::logout();
        }
        remember_input(['email' => (string) ($client['email'] ?? '')]);
        flash('success', 'Доступ к магазину для ' . ($client['email'] ?? '') . ' открыт. Войдите под этим email — магазин будет в меню «Кабинет WB».'
            . ($user ? ' (Вы были в другом аккаунте — мы вышли из него.)' : ''));
        redirect('/login');
    }

    /** Приглашение можно принять и без письма — в своём аккаунте, по кнопке в уведомлении. */
    public function accessAccept(string $id): never
    {
        $user = Auth::require();
        $row = DB::one('SELECT * FROM store_clients WHERE id = ? AND user_id = ? AND confirmed_at IS NULL', [(int) $id, $user['id']]);
        if (!$row) {
            redirect('/app');
        }
        DB::exec('UPDATE store_clients SET confirmed_at = NOW(), token_hash = NULL WHERE id = ?', [$row['id']]);
        self::enterAccess($row);
    }

    /** Клиент выходит из кабинета агентства (закрывает себе доступ к его магазинам). */
    public function accessLeave(): never
    {
        $user = Auth::require();
        $ownerId = Auth::actingKind() === 'client' ? (int) Auth::acting() : 0;
        if ($ownerId && \App\Services\StoreClients::leave($user, $ownerId)) {
            Auth::switchAccount(0);
            unset($_SESSION['store']);
            flash('success', 'Вы вышли из кабинета — доступ к его магазинам закрыт.');
        }
        redirect('/app');
    }

    public function accessDecline(string $id): never
    {
        $user = Auth::require();
        DB::exec('DELETE FROM store_clients WHERE id = ? AND user_id = ? AND confirmed_at IS NULL', [(int) $id, $user['id']]);
        flash('success', 'Приглашение отклонено.');
        redirect('/app');
    }
}
