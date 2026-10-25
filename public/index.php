<?php
declare(strict_types=1);

// Встроенный сервер PHP (локальная разработка): отдаём статические файлы как есть
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\FeedController;
use App\Controllers\HomeController;
use App\Controllers\InstallController;
use App\Controllers\SettingsController;
use App\Controllers\StoreController;
use App\Controllers\SystemController;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Router;

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');

if (!Config::installed() && !str_starts_with($path, '/install')) {
    redirect('/install');
}

$isWebhook = str_starts_with($path, '/webhook/') || $path === '/cron-run';
if (!$isWebhook) {
    Auth::startSession();
    if ($method === 'POST' && !Csrf::check()) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        $back = e((string) parse_url($_SERVER['HTTP_REFERER'] ?? $path, PHP_URL_PATH) ?: '/');
        exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<div style="font:16px system-ui;max-width:520px;margin:15vh auto;padding:0 16px">'
            . '<h2>Сессия устарела</h2><p>Обновите страницу и повторите действие. Если ошибка повторяется — '
            . 'разрешите cookies для этого сайта.</p><p><a href="' . $back . '">← Вернуться</a></p></div>');
    }
}

$r = new Router();

// Публичные страницы
$r->get('/', [HomeController::class, 'index']);
$r->get('/oferta', [HomeController::class, 'oferta']);
$r->get('/privacy', [HomeController::class, 'privacy']);

// Авторизация
$r->get('/login', [AuthController::class, 'loginForm']);
$r->post('/login', [AuthController::class, 'login']);
$r->get('/register', [AuthController::class, 'registerForm']);
$r->post('/register', [AuthController::class, 'register']);
$r->post('/logout', [AuthController::class, 'logout']);
$r->get('/forgot', [AuthController::class, 'forgotForm']);
$r->post('/forgot', [AuthController::class, 'forgot']);
$r->get('/reset', [AuthController::class, 'resetForm']);
$r->post('/reset', [AuthController::class, 'reset']);

// Кабинет
$r->get('/app', [FeedController::class, 'index']);
$r->get('/app/export', [FeedController::class, 'export']);
$r->get('/app/stores', [StoreController::class, 'index']);
$r->post('/app/stores', [StoreController::class, 'create']);
$r->post('/app/stores/{id}/token', [StoreController::class, 'updateToken']);
$r->post('/app/stores/{id}/rename', [StoreController::class, 'rename']);
$r->post('/app/stores/{id}/toggle', [StoreController::class, 'toggle']);
$r->post('/app/stores/{id}/delete', [StoreController::class, 'delete']);
$r->post('/app/stores/{id}/resync', [StoreController::class, 'resync']);
$r->get('/app/billing', [BillingController::class, 'index']);
$r->post('/app/billing/pay', [BillingController::class, 'pay']);
$r->get('/app/billing/return', [BillingController::class, 'return']);
$r->get('/app/settings', [SettingsController::class, 'index']);
$r->post('/app/settings/profile', [SettingsController::class, 'profile']);
$r->post('/app/settings/password', [SettingsController::class, 'password']);
$r->post('/app/settings/telegram', [SettingsController::class, 'telegram']);
$r->post('/app/settings/telegram/unlink', [SettingsController::class, 'telegramUnlink']);
$r->post('/app/settings/telegram/test', [SettingsController::class, 'telegramTest']);

// JSON для живой ленты
$r->get('/api/feed', [ApiController::class, 'feed']);
$r->get('/api/feed/updates', [ApiController::class, 'updates']);

// Админка
$r->get('/admin', [AdminController::class, 'index']);
$r->get('/admin/users', [AdminController::class, 'users']);
$r->post('/admin/users/{id}', [AdminController::class, 'updateUser']);
$r->get('/admin/payments', [AdminController::class, 'payments']);
$r->post('/admin/cron', [AdminController::class, 'runCron']);

// Системное
$r->post('/webhook/yookassa', [SystemController::class, 'yookassa']);
$r->get('/cron-run', [SystemController::class, 'cron']);
$r->get('/install', [InstallController::class, 'form']);
$r->post('/install', [InstallController::class, 'install']);

try {
    $r->dispatch($method, $uri);
} catch (\Throwable $e) {
    log_message('error', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    if (config('app.debug')) {
        echo '<pre>' . e($e) . '</pre>';
    } else {
        echo App\Core\View::render('errors/500', ['title' => 'Ошибка'], 'main');
    }
}
