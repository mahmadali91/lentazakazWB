<?php
declare(strict_types=1);

// Встроенный сервер PHP (локальная разработка): отдаём статические файлы как есть
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AdminController;
use App\Controllers\ApiController;
use App\Controllers\ApiKeysController;
use App\Controllers\ApiV1Controller;
use App\Controllers\AuthController;
use App\Controllers\BillingController;
use App\Controllers\FeedController;
use App\Controllers\FinanceController;
use App\Controllers\HomeController;
use App\Controllers\InstallController;
use App\Controllers\ProductsController;
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
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header("Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
header_remove('X-Powered-By');
if (($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
    header('Strict-Transport-Security: max-age=31536000');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');

if (!Config::installed() && !str_starts_with($path, '/install')) {
    redirect('/install');
}
if (Config::installed()) {
    App\Core\Migrator::ensure();
    Config::overlayFromDb();
}

$isWebhook = str_starts_with($path, '/webhook/') || $path === '/cron-run' || str_starts_with($path, '/api/v1') || str_starts_with($path, '/mcp/') || $path === '/mcp';
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

// Телефон обязателен для владельцев кабинетов: без него отправляем в профиль (кроме режима администратора)
if (!$isWebhook && $method === 'GET' && str_starts_with($path, '/app') && !str_starts_with($path, '/app/settings')
    && Config::installed() && Auth::needsPhone(Auth::user()) && !Auth::impersonator()) {
    flash('error', 'Укажите номер телефона в профиле — это обязательное поле.');
    redirect('/app/settings#profile');
}

// Предзапрос CORS для API и MCP (браузерные клиенты, MCP Inspector)
if ($method === 'OPTIONS' && $isWebhook && !str_starts_with($path, '/webhook/')) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key, Mcp-Session-Id, Mcp-Protocol-Version');
    http_response_code(204);
    exit;
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
$r->get('/app/profile', [SettingsController::class, 'profilePage']);
$r->post('/app/settings/profile', [SettingsController::class, 'profile']);
$r->post('/app/settings/password', [SettingsController::class, 'password']);
$r->post('/app/settings/telegram', [SettingsController::class, 'telegram']);
$r->post('/app/settings/telegram/unlink', [SettingsController::class, 'telegramUnlink']);
$r->post('/app/settings/telegram/test', [SettingsController::class, 'telegramTest']);
$r->post('/app/team', [SettingsController::class, 'teamAdd']);
$r->post('/app/team/{id}/password', [SettingsController::class, 'teamPassword']);
$r->post('/app/team/{id}/delete', [SettingsController::class, 'teamDelete']);
$r->get('/app/products', [ProductsController::class, 'index']);
$r->post('/app/products', [ProductsController::class, 'save']);
$r->post('/app/products/settings', [ProductsController::class, 'settings']);
$r->get('/app/products/export', [ProductsController::class, 'export']);
$r->post('/app/products/import', [ProductsController::class, 'import']);
$r->get('/app/finance', [FinanceController::class, 'index']);
$r->get('/app/finance/opiu', [FinanceController::class, 'opiu']);
$r->get('/app/finance/sku', [FinanceController::class, 'sku']);
$r->get('/app/finance/abc', [FinanceController::class, 'abc']);
$r->get('/app/finance/orders', [FinanceController::class, 'orders']);
$r->get('/app/finance/reports', [FinanceController::class, 'reports']);
$r->get('/app/finance/reports/{store}/{id}', [FinanceController::class, 'report']);
$r->post('/app/finance/reports/{store}/{id}/delete', [FinanceController::class, 'deleteReport']);
$r->get('/app/finance/export', [FinanceController::class, 'export']);
$r->post('/app/finance/upload', [FinanceController::class, 'upload']);
$r->post('/app/finance/history', [FinanceController::class, 'history']);
$r->get('/app/finance/costs', [FinanceController::class, 'costs']);
$r->post('/app/finance/costs', [FinanceController::class, 'saveCosts']);
$r->get('/app/finance/storage', [FinanceController::class, 'storage']);
$r->get('/app/finance/ads', [FinanceController::class, 'ads']);
$r->get('/app/finance/expenses', [FinanceController::class, 'expenses']);
$r->post('/app/finance/expenses', [FinanceController::class, 'addExpense']);
$r->post('/app/finance/expenses/{id}/delete', [FinanceController::class, 'deleteExpense']);
$r->get('/app/finance/selfbuys', [FinanceController::class, 'selfbuys']);
$r->post('/app/finance/selfbuys', [FinanceController::class, 'addSelfbuys']);
$r->post('/app/finance/selfbuys/delete', [FinanceController::class, 'deleteSelfbuy']);
$r->get('/app/finance/promo', [FinanceController::class, 'promo']);
$r->post('/app/finance/settings', [FinanceController::class, 'settings']);

// JSON для живой ленты
$r->get('/api/feed', [ApiController::class, 'feed']);
$r->get('/api/feed/updates', [ApiController::class, 'updates']);

// Внешнее API по ключу (только чтение) и MCP-сервер для Claude
$r->get('/api/v1', [ApiV1Controller::class, 'index']);
$r->get('/api/v1/{name}', [ApiV1Controller::class, 'tool']);
$r->post('/api/v1/{name}', [ApiV1Controller::class, 'tool']);
$r->post('/mcp', [ApiV1Controller::class, 'mcpHeader']);
$r->get('/mcp', [ApiV1Controller::class, 'mcpHeader']);
$r->post('/mcp/{key}', [ApiV1Controller::class, 'mcp']);
$r->get('/mcp/{key}', [ApiV1Controller::class, 'mcp']);
$r->get('/app/settings/api', [ApiKeysController::class, 'index']);
$r->post('/app/settings/api/keys', [ApiKeysController::class, 'create']);
$r->post('/app/settings/api/keys/{id}/delete', [ApiKeysController::class, 'delete']);
$r->get('/app/api', static fn() => redirect('/app/settings/api'));

// Админка
$r->get('/admin', [AdminController::class, 'index']);
$r->get('/admin/users', [AdminController::class, 'users']);
$r->post('/admin/users/create', [AdminController::class, 'create']);
$r->get('/admin/users/{id}', [AdminController::class, 'show']);
$r->post('/admin/users/{id}', [AdminController::class, 'update']);
$r->post('/admin/users/{id}/extend', [AdminController::class, 'extend']);
$r->post('/admin/users/{id}/password', [AdminController::class, 'password']);
$r->post('/admin/users/{id}/delete', [AdminController::class, 'delete']);
$r->post('/admin/users/{id}/login', [AdminController::class, 'impersonate']);
$r->post('/admin/impersonate/stop', [AdminController::class, 'stopImpersonate']);
$r->get('/admin/payments', [AdminController::class, 'payments']);
$r->get('/admin/settings', [AdminController::class, 'settings']);
$r->post('/admin/settings', [AdminController::class, 'saveSettings']);
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
