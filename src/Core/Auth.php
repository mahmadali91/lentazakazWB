<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

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
    }

    public static function require(): array
    {
        $user = self::user();
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
