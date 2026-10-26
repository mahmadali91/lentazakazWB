<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\View;

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('app.url', ''), '/') . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? substr((string) filemtime($file), -6) : APP_VERSION;
    return '/assets/' . ltrim($path, '/') . '?v=' . $v;
}

function redirect(string $to): never
{
    header('Location: ' . $to, true, 302);
    exit;
}

function view(string $template, array $data = [], ?string $layout = 'main'): string
{
    return View::render($template, $data, $layout);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function old(string $key, string $default = ''): string
{
    return (string) ($_SESSION['_old'][$key] ?? $default);
}

function remember_input(array $data): void
{
    unset($data['password'], $data['password2'], $data['token'], $data['_csrf']);
    $_SESSION['_old'] = $data;
}

function forget_input(): void
{
    unset($_SESSION['_old']);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function input(string $key, mixed $default = null): mixed
{
    $v = $_POST[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

const CURRENCY_SIGNS = ['RUB' => '₽', 'KGS' => 'с', 'KZT' => '₸', 'BYN' => 'Br', 'AMD' => '֏', 'UZS' => 'сум', 'TJS' => 'сом', 'GEL' => '₾', 'AZN' => '₼'];

/**
 * Знак валюты. С кодом — знак этой валюты; без кода — текущей валюты страницы (currency_use()),
 * иначе валюты магазинов кабинета. WB отдаёт суммы в валюте продавца: RUB, KGS, KZT…
 */
function currency_sign(?string $code = null): string
{
    if ($code !== null) {
        return CURRENCY_SIGNS[strtoupper($code)] ?? ($code !== '' ? strtoupper($code) : '₽');
    }
    $cur = currency_use();
    return $cur === null ? '₽' : currency_sign($cur);
}

/** Текущая валюта страницы: задать (код) или получить. По умолчанию — валюта магазинов кабинета. */
function currency_use(?string $set = null): ?string
{
    static $current = false;
    if ($set !== null) {
        $current = strtoupper($set);
        return $current;
    }
    if ($current === false) {
        $current = null;
        try {
            $account = session_status() === PHP_SESSION_ACTIVE ? App\Core\Auth::account() : null;
            if ($account) {
                $ids = array_column(App\Core\DB::all('SELECT id FROM stores WHERE user_id = ?', [$account['id']]), 'id');
                $current = currency_of_stores($ids)['code'];
            }
        } catch (\Throwable) {
        }
    }
    return $current;
}

/**
 * Пересчёт ленты в рубли по курсу ЦБ, если клиент включил «Показывать в рублях» и среди магазинов есть не рублёвые.
 * Возвращает true, если суммы пересчитываются.
 */
function fx_feed(?array $account, array $storeIds): bool
{
    if (!App\Services\Fx::enabled($account) || !$storeIds) {
        return false;
    }
    $cc = currency_of_stores($storeIds);
    if (!$cc['all'] || $cc['all'] === ['RUB']) {
        return false;
    }
    $factors = App\Services\Fx::storeFactors($storeIds);
    if ($factors === null) {
        return false;
    }
    App\Services\OrderRepository::$fx = $factors;
    currency_use('RUB');
    return true;
}

/** Валюта набора магазинов: ['code' => 'KGS'|null, 'mixed' => bool, 'all' => ['RUB', 'KGS']]. */
function currency_of_stores(array $storeIds): array
{
    $codes = [];
    if ($storeIds) {
        $in = implode(',', array_map('intval', $storeIds));
        $codes = array_values(array_unique(array_map('strtoupper', array_column(
            App\Core\DB::all("SELECT currency FROM stores WHERE id IN ($in) AND currency IS NOT NULL AND currency <> ''"), 'currency'))));
    }
    return ['code' => count($codes) === 1 ? $codes[0] : ($codes[0] ?? null), 'mixed' => count($codes) > 1, 'all' => $codes];
}

function money(float|int|string|null $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    return number_format((float) $value, 0, ',', ' ') . ' ' . currency_sign();
}

/** Цена тарифа сервиса — всегда в рублях. */
function rub(float|int|string|null $value): string
{
    return $value === null || $value === '' ? '—' : number_format((float) $value, 0, ',', ' ') . ' ₽';
}

/** Телефон в формате +7XXXXXXXXXX (или + и 10–15 цифр для других стран); null — некорректный. */
function normalize_phone(string $value): ?string
{
    $d = preg_replace('/\D/', '', $value);
    if (strlen($d) === 11 && $d[0] === '8') {
        $d = '7' . substr($d, 1);
    } elseif (strlen($d) === 10 && $d[0] === '9') {
        $d = '7' . $d;
    }
    return strlen($d) >= 10 && strlen($d) <= 15 ? '+' . $d : null;
}

/** +79913004737 → +7 991 300-47-37 */
function phone_ru(?string $phone): string
{
    if (!$phone) {
        return '';
    }
    return preg_match('/^\+7(\d{3})(\d{3})(\d{2})(\d{2})$/', $phone, $m) ? "+7 $m[1] $m[2]-$m[3]-$m[4]" : $phone;
}

/** Проверка ИНН по контрольным числам (10 цифр — организация, 12 — ИП/физлицо). */
function inn_valid(string $inn): bool
{
    if (!preg_match('/^(\d{10}|\d{12})$/', $inn)) {
        return false;
    }
    $d = array_map('intval', str_split($inn));
    $sum = static fn(array $w, int $n) => array_sum(array_map(static fn($k, $i) => $k * $d[$i], $w, array_keys($w))) % 11 % 10;
    if (strlen($inn) === 10) {
        return $sum([2, 4, 10, 3, 5, 9, 4, 6, 8], 9) === $d[9];
    }
    return $sum([7, 2, 4, 10, 3, 5, 9, 4, 6, 8], 10) === $d[10] && $sum([3, 7, 2, 4, 10, 3, 5, 9, 4, 6, 8], 11) === $d[11];
}

/** Реквизиты исполнителя одной строкой: «ИП Иванов И. И., ИНН 123…, ОГРНИП …». Пусто — не заполнены. */
function company_line(): string
{
    $parts = array_filter([
        (string) config('company.name', ''),
        config('company.inn') ? 'ИНН ' . config('company.inn') : '',
        config('company.ogrn') ? (strlen((string) config('company.ogrn')) === 15 ? 'ОГРНИП ' : 'ОГРН ') . config('company.ogrn') : '',
    ]);
    return implode(', ', $parts);
}

/** Число по-русски: 1 234,5. null — прочерк. */
function num_ru(float|int|string|null $value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $v = round((float) $value, $decimals);
    return number_format($v == 0 ? 0.0 : $v, $decimals, ',', ' ');
}

function plural(int $n, string $one, string $few, string $many): string
{
    $n = abs($n) % 100;
    $n1 = $n % 10;
    if ($n > 10 && $n < 20) {
        return $many;
    }
    if ($n1 > 1 && $n1 < 5) {
        return $few;
    }
    if ($n1 === 1) {
        return $one;
    }
    return $many;
}

function date_ru(?string $datetime, bool $withTime = true): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $withTime ? date('d.m.Y H:i', $ts) : date('d.m.Y', $ts);
}

/** Перевод времени из ответа WB (RFC3339 с любым поясом) во время приложения. */
function wb_time(?string $value): ?string
{
    if (!$value || str_starts_with($value, '0001-01-01')) {
        return null;
    }
    try {
        $dt = new DateTime($value);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function scheme_label(?string $scheme): string
{
    return [
        'FBO' => 'FBO · склад WB',
        'FBS' => 'FBS',
        'DBS' => 'DBS',
        'DBW' => 'DBW',
        'CC'  => 'Самовывоз',
        'MP'  => 'Склад продавца',
    ][$scheme ?? ''] ?? '—';
}

function status_label(?string $status): string
{
    return [
        'created'         => 'Оформлен',
        'buyout'          => 'Выкуплен',
        'cancel'          => 'Отменён',
        'return'          => 'Возврат',
        'returnDefective' => 'Возврат (брак)',
    ][$status ?? ''] ?? (string) $status;
}

/**
 * Версия для показа: номер из bootstrap.php + короткий хеш коммита и дата обновления (из .git на сервере).
 * Пример: «1.6.0 · 49ff478 · 26.10.2026».
 */
/** Telegram-канал поддержки без @ (настраивается в админ-панели); пустая строка — не показывать. */
function support_telegram(): string
{
    return ltrim(trim((string) config('app.support_telegram', '')), '@');
}

function app_version(): string
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $v = APP_VERSION;
    $git = BASE_PATH . '/.git';
    $head = @file_get_contents($git . '/HEAD');
    if ($head !== false) {
        $hash = trim($head);
        if (str_starts_with($hash, 'ref: ')) {
            $ref = trim(substr($hash, 5));
            $file = $git . '/' . $ref;
            $hash = is_file($file) ? trim((string) @file_get_contents($file)) : '';
            if ($hash === '' && is_file($git . '/packed-refs')) {
                foreach (file($git . '/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (str_ends_with($line, ' ' . $ref)) {
                        $hash = strtok($line, ' ');
                    }
                }
            }
            $time = is_file($file) ? filemtime($file) : false;
        } else {
            $time = filemtime($git . '/HEAD');
        }
        if (preg_match('/^[0-9a-f]{7,40}$/', (string) $hash)) {
            $v .= ' · ' . substr($hash, 0, 7);
        }
        $fetch = @filemtime($git . '/FETCH_HEAD') ?: ($time ?? false);
        if ($fetch) {
            $v .= ' · ' . date('d.m.Y', $fetch);
        }
    }
    return $v;
}

function log_message(string $channel, string $message): void
{
    $file = BASE_PATH . '/storage/logs/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log';
    @file_put_contents($file, '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function random_code(int $bytes = 16): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/** Встроенные SVG-иконки (стиль Feather/Lucide). */
function icon(string $name, int $size = 20): string
{
    $paths = [
        'feed'     => '<path d="M3 12h4l3 8 4-16 3 8h4"/>',
        'store'    => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v11h16V9"/><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/>',
        'card'     => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'search'   => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'bell-off' => '<path d="M13.7 21a2 2 0 0 1-3.4 0"/><path d="M18.6 13A17.9 17.9 0 0 1 18 8"/><path d="M6.3 6.3A6 6 0 0 0 6 8c0 7-3 9-3 9h14"/><path d="M18 8a6 6 0 0 0-9.3-5"/><path d="M1 1l22 22"/>',
        'sun'      => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'     => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/>',
        'zap'      => '<path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>',
        'box'      => '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7z"/><path d="M3.3 7L12 12l8.7-5"/><path d="M12 22V12"/>',
        'send'     => '<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/>',
        'chart'    => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-6"/>',
        'lock'     => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'filter'   => '<path d="M22 3H2l8 9.5V19l4 2v-8.5L22 3z"/>',
        'refresh'  => '<path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.5 9a9 9 0 0 1 14.9-3.4L23 10M1 14l4.6 4.4A9 9 0 0 0 20.5 15"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'check'    => '<path d="M20 6L9 17l-5-5"/>',
        'clock'    => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'users'    => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
        'chevron'  => '<path d="M9 18l6-6-6-6"/>',
        'mail'     => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 6l-10 7L2 6"/>',
        'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'list'     => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
        'image'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'code'     => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'help'     => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    ];
    $p = $paths[$name] ?? $paths['box'];
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Логин для входа: email или имя из латиницы/цифр/._- (3–64 символа). Возвращает нормализованный логин или null. */
function normalize_login(string $login): ?string
{
    $login = mb_strtolower(trim($login));
    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
        return $login;
    }
    return preg_match('/^[a-z0-9._-]{3,64}$/', $login) ? $login : null;
}
