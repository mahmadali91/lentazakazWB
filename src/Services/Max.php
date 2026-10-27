<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Уведомления о заказах в мессенджере MAX через Bot API (dev.max.ru).
 * Токен — в заголовке Authorization; привязка аккаунта — по ссылке max.ru/<бот>?start=<код> (событие bot_started),
 * события бота опрашиваются вместе с cron (GET /updates).
 */
final class Max
{
    private const API = 'https://platform-api2.max.ru';
    /** Сертификаты УЦ Минцифры: API MAX работает на них, а на хостинге их может не быть в системном хранилище. */
    private const CA_BUNDLE = BASE_PATH . '/config/certs/russian_trusted_ca.pem';

    /** Текст последней ошибки MAX API (для админки и диагностики). */
    public static string $lastError = '';

    public static function enabled(): bool
    {
        return (string) config('max.bot_token', '') !== '' && (string) config('max.bot_username', '') !== '';
    }

    public static function api(string $method, string $path, array $query = [], ?array $body = null): ?array
    {
        $token = (string) config('max.bot_token', '');
        self::$lastError = '';
        if ($token === '') {
            self::$lastError = 'токен бота не задан';
            return null;
        }
        $url = rtrim((string) config('max.api_url', self::API), '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $raw = false;
        $curlError = '';
        $http = 0;
        // Сначала — системные сертификаты; если сервер не знает УЦ Минцифры, повторяем с приложенными
        foreach ([null, self::CA_BUNDLE] as $ca) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => ['Authorization: ' . $token, 'Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_CONNECTTIMEOUT => 5,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            }
            if ($ca !== null) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
            curl_setopt_array($ch, $opts);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $curlError = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            // 60 — неизвестный УЦ, 51 — сертификат сервера не прошёл проверку, 58/77 — проблемы с сертификатами
            if (!in_array($errno, [51, 58, 60, 77], true) || !is_file(self::CA_BUNDLE)) {
                break;
            }
        }
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if ($raw === false || $http >= 400 || !is_array($data)) {
            self::$lastError = $raw === false
                ? 'нет связи с MAX: ' . $curlError
                : 'HTTP ' . $http . ': ' . (is_array($data) ? (string) ($data['message'] ?? $data['code'] ?? '') : mb_substr((string) $raw, 0, 200));
            log_message('max', "$method $path failed: " . self::$lastError);
            return null;
        }
        return $data;
    }

    /** Сообщение пользователю (диалог с ботом). Разметка — HTML, как в Telegram (<b>, <i>, <a>). */
    public static function send(int|string $userId, string $html): bool
    {
        return self::api('POST', '/messages', ['user_id' => $userId, 'disable_link_preview' => 'true'], ['text' => mb_substr($html, 0, 4000), 'format' => 'html']) !== null;
    }

    public static function linkUrl(string $code): string
    {
        return 'https://max.ru/' . config('max.bot_username') . '?start=' . $code;
    }

    /** Обрабатывает события бота: привязка аккаунта по коду из ссылки (bot_started) или сообщением «/start КОД», отключение — /stop. */
    public static function processUpdates(): void
    {
        if (!self::enabled()) {
            return;
        }
        $marker = DB::setting('max_marker');
        $query = ['timeout' => 0, 'limit' => 100];
        if ($marker !== null && $marker !== '') {
            $query['marker'] = $marker;
        }
        $resp = self::api('GET', '/updates', $query);
        if (!$resp) {
            return;
        }
        foreach ($resp['updates'] ?? [] as $u) {
            $type = (string) ($u['update_type'] ?? '');
            if ($type === 'bot_started') {
                $userId = $u['user']['user_id'] ?? null;
                $text = '/start ' . (string) ($u['payload'] ?? '');
            } elseif ($type === 'message_created' && ($u['message']['recipient']['chat_type'] ?? '') === 'dialog') {
                $userId = $u['message']['sender']['user_id'] ?? null;
                $text = trim((string) ($u['message']['body']['text'] ?? ''));
            } else {
                continue;
            }
            if (!$userId) {
                continue;
            }
            if (preg_match('#^/start\s+([A-Za-z0-9_-]{6,32})$#', trim($text), $m)) {
                $user = DB::one('SELECT id, email FROM users WHERE max_link_code = ?', [$m[1]]);
                if ($user) {
                    DB::exec('UPDATE users SET max_user_id = ?, max_link_code = NULL WHERE id = ?', [$userId, $user['id']]);
                    self::send($userId, "✅ Готово! Уведомления о заказах будут приходить сюда.\nАккаунт: <b>" . e($user['email']) . '</b>');
                } else {
                    self::send($userId, 'Код привязки не найден или устарел. Откройте «Настройки» на сайте и нажмите «Подключить MAX» ещё раз.');
                }
            } elseif ($text === '/stop') {
                DB::exec('UPDATE users SET max_user_id = NULL WHERE max_user_id = ?', [$userId]);
                self::send($userId, 'Уведомления отключены. Подключить снова можно в настройках на сайте.');
            } elseif (str_starts_with($text, '/start')) {
                self::send($userId, 'Здравствуйте! Чтобы получать уведомления о заказах WB, откройте на сайте «Настройки» → «Подключить MAX».');
            }
        }
        if (isset($resp['marker'])) {
            DB::setSetting('max_marker', (string) $resp['marker']);
        }
    }
}
