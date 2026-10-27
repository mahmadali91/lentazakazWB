<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;
    private static ?array $account = null;

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('lz_sid');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
        $dir = BASE_PATH . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_set_save_handler(new FileSessionHandler($dir), true);
        }
        session_start();
    }

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = (int) ($_SESSION['uid'] ?? 0);
            if ($id > 0) {
                self::$user = DB::one('SELECT * FROM users WHERE id = ? AND is_blocked = 0', [$id]);
                if (!self::$user) {
                    unset($_SESSION['uid']);
                }
            }
        }
        return self::$user;
    }

    /**
     * Кабинет, с которым работает пользователь: для сотрудника — кабинет владельца, иначе — сам пользователь.
     * Тариф, магазины и себестоимость принадлежат кабинету.
     */
    public static function account(): ?array
    {
        $user = self::user();
        if (!$user || empty($user['owner_id'])) {
            return $user;
        }
        if (self::$account === null || (int) self::$account['id'] !== (int) $user['owner_id']) {
            self::$account = DB::one('SELECT * FROM users WHERE id = ? AND is_blocked = 0', [$user['owner_id']]);
            if (!self::$account) {
                // Владелец удалён или заблокирован — сотрудник тоже теряет доступ
                self::logout();
                return null;
            }
        }
        return self::$account;
    }

    public static function accountId(): int
    {
        return (int) (self::account()['id'] ?? 0);
    }

    /** Владелец кабинета без телефона (сотрудникам и администратору телефон не обязателен). */
    public static function needsPhone(?array $user): bool
    {
        return $user !== null && empty($user['owner_id']) && ($user['role'] ?? '') !== 'admin' && empty($user['phone']);
    }

    public static function isMember(): bool
    {
        return !empty(self::user()['owner_id']);
    }

    /** Действие доступно только владельцу кабинета (не сотруднику). */
    public static function requireOwner(): array
    {
        $user = self::require();
        if (!empty($user['owner_id'])) {
            flash('error', 'Этот раздел доступен только владельцу кабинета.');
            redirect('/app');
        }
        return $user;
    }

    /** Администратор вошёл в кабинет клиента («Войти как»). */
    public static function impersonator(): ?int
    {
        return isset($_SESSION['impersonator']) ? (int) $_SESSION['impersonator'] : null;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        self::$user = $user;
        self::$loaded = true;
        DB::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [now(), $user['id']]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$account = null;
    }

    public static function require(): array
    {
        $user = self::user();
        if ($user && !empty($user['owner_id']) && !self::account()) {
            $user = null;
        }
        // Сотрудник или клиент работает, пока тариф владельца допускает столько сотрудников (по порядку добавления)
        if ($user && !empty($user['owner_id']) && !self::impersonator()) {
            $limit = (int) (\App\Services\Plans::forUser((array) self::account())['team'] ?? 0);
            $rank = (int) DB::value('SELECT COUNT(*) FROM users WHERE owner_id = ? AND id <= ?', [$user['owner_id'], $user['id']]);
            if ($rank > $limit) {
                self::logout();
                flash('error', 'Доступ сотрудников и клиентов приостановлен: тариф владельца кабинета его не включает. Обратитесь к владельцу кабинета.');
                $user = null;
            }
        }
        if (!$user) {
            if (self::wantsJson()) {
                json_response(['error' => 'unauthorized'], 401);
            }
            redirect('/login');
        }
        return $user;
    }

    public static function requireAdmin(): array
    {
        $user = self::require();
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            exit('Доступ запрещён');
        }
        return $user;
    }

    public static function refresh(): void
    {
        self::$loaded = false;
        self::$user = null;
        self::$account = null;
    }

    private static function wantsJson(): bool
    {
        return str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/api/');
    }

    /** Простая защита от перебора паролей: не более 10 попыток за 15 минут с одного IP. */
    public static function tooManyAttempts(): bool
    {
        $count = (int) DB::value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?',
            [client_ip(), date('Y-m-d H:i:s', time() - 900)]
        );
        return $count >= 10;
    }

    public static function recordAttempt(): void
    {
        DB::insert('login_attempts', ['ip' => client_ip(), 'attempted_at' => now()]);
    }
}
