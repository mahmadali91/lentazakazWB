<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;

/** Веб-установщик: проверяет окружение, создаёт таблицы, администратора и config/config.php. */
final class InstallController
{
    private function guard(): void
    {
        if (Config::installed()) {
            http_response_code(403);
            exit('Сервис уже установлен. Чтобы переустановить — удалите config/config.php.');
        }
    }

    public static function checks(): array
    {
        return [
            'PHP 8.1 или новее'          => version_compare(PHP_VERSION, '8.1.0', '>='),
            'Расширение pdo_mysql'        => extension_loaded('pdo_mysql'),
            'Расширение curl'             => extension_loaded('curl'),
            'Расширение openssl'          => extension_loaded('openssl'),
            'Расширение mbstring'         => extension_loaded('mbstring'),
            'Запись в папку config/'      => is_writable(BASE_PATH . '/config'),
            'Запись в папку storage/'     => is_writable(BASE_PATH . '/storage'),
        ];
    }

    public function form(): string
    {
        $this->guard();
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return view('install/form', [
            'title'  => 'Установка',
            'checks' => self::checks(),
            'url'    => ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'error'  => null,
            'done'   => null,
        ], 'auth');
    }

    public function install(): string
    {
        $this->guard();
        $in = fn(string $k, string $d = '') => trim((string) ($_POST[$k] ?? $d));
        $db = [
            'host'    => $in('db_host', 'localhost'),
            'port'    => (int) $in('db_port', '3306'),
            'name'    => $in('db_name'),
            'user'    => $in('db_user'),
            'pass'    => (string) ($_POST['db_pass'] ?? ''),
            'charset' => 'utf8mb4',
        ];
        $siteUrl = rtrim($in('app_url'), '/');
        $email = mb_strtolower($in('admin_email'));
        $password = (string) ($_POST['admin_password'] ?? '');
        $error = null;

        if (in_array(false, self::checks(), true)) {
            $error = 'Сервер не прошёл проверку требований (см. список выше).';
        } elseif ($db['name'] === '' || $db['user'] === '') {
            $error = 'Укажите имя базы данных и пользователя.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 8) {
            $error = 'Укажите email администратора и пароль не короче 8 символов.';
        } elseif (!preg_match('#^https?://#', $siteUrl)) {
            $error = 'Адрес сайта должен начинаться с https://';
        }

        if ($error === null) {
            try {
                $pdo = DB::connect($db);
                $sql = (string) file_get_contents(BASE_PATH . '/database/schema.sql');
                $sql = preg_replace('/^--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    $pdo->exec($statement);
                }
                $host = parse_url($siteUrl, PHP_URL_HOST) ?: 'localhost';
                $config = require BASE_PATH . '/config/config.example.php';
                $config['app']['url'] = $siteUrl;
                $config['app']['key'] = Crypto::generateKey();
                $config['app']['support_email'] = $email;
                $config['app']['name'] = $in('app_name', 'Лента заказов WB') ?: 'Лента заказов WB';
                $config['db'] = $db;
                // Письма шлём с основного домена (wb.linkoz.ru → noreply@linkoz.ru) — там обычно настроена почта и SPF
                $mailDomain = implode('.', array_slice(explode('.', (string) $host), -2));
                $config['mail']['from'] = 'noreply@' . $mailDomain;
                $config['mail']['from_name'] = $config['app']['name'];
                $config['cron']['secret'] = bin2hex(random_bytes(16));

                $exists = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                $exists->execute([$email]);
                if ($id = $exists->fetchColumn()) {
                    $pdo->prepare("UPDATE users SET role = 'admin', password_hash = ? WHERE id = ?")
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    // Первый администратор — владелец сервиса (супер-админ): всё бесплатно и без ограничений
                    $pdo->prepare("INSERT INTO users (email, password_hash, name, role, is_super, plan_code, plan_expires_at, created_at)
                        VALUES (?, ?, 'Администратор', 'admin', IF((SELECT COUNT(*) FROM users u) = 0, 1, 0), 'business', ?, ?)")
                        ->execute([$email, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s', strtotime('+10 years')), now()]);
                }
                Config::write($config);
                return view('install/form', [
                    'title'  => 'Установка завершена',
                    'checks' => self::checks(),
                    'url'    => $siteUrl,
                    'error'  => null,
                    'done'   => [
                        'cron'       => BASE_PATH . '/cron/sync.php',
                        'cron_url'   => $siteUrl . '/cron-run?key=' . $config['cron']['secret'],
                        'php'        => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                    ],
                ], 'auth');
            } catch (\Throwable $e) {
                $error = 'Ошибка: ' . $e->getMessage();
            }
        }

        return view('install/form', [
            'title'  => 'Установка',
            'checks' => self::checks(),
            'url'    => $siteUrl,
            'error'  => $error,
            'done'   => null,
        ], 'auth');
    }
}
