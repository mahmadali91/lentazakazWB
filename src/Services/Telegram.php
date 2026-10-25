<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Уведомления о заказах в Telegram через Bot API. */
final class Telegram
{
    public static function enabled(): bool
    {
        return (string) config('telegram.bot_token', '') !== '' && (string) config('telegram.bot_username', '') !== '';
    }

    public static function api(string $method, array $params = []): ?array
    {
        $token = (string) config('telegram.bot_token', '');
        if ($token === '') {
            return null;
        }
        $ch = curl_init("https://api.telegram.org/bot$token/$method");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['ok'])) {
            log_message('telegram', "$method failed: " . (is_string($raw) ? mb_substr($raw, 0, 300) : 'no response'));
            return null;
        }
        return $data;
    }

    public static function send(int|string $chatId, string $html): bool
    {
        return self::api('sendMessage', [
            'chat_id'                  => $chatId,
            'text'                     => $html,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ]) !== null;
    }

    public static function linkUrl(string $code): string
    {
        return 'https://t.me/' . config('telegram.bot_username') . '?start=' . $code;
    }

    /** Обрабатывает входящие сообщения бота (привязка аккаунта по /start КОД). */
    public static function processUpdates(): void
    {
        if (!self::enabled()) {
            return;
        }
        $offset = (int) DB::setting('tg_offset', '0');
        $resp = self::api('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => ['message']]);
        if (!$resp) {
            return;
        }
        foreach ($resp['result'] ?? [] as $update) {
            $offset = max($offset, (int) $update['update_id'] + 1);
            $msg = $update['message'] ?? null;
            if (!$msg || empty($msg['chat']['id'])) {
                continue;
            }
            $chatId = $msg['chat']['id'];
            $text = trim((string) ($msg['text'] ?? ''));
            if (preg_match('#^/start\s+([A-Za-z0-9_-]{6,32})$#', $text, $m)) {
                $user = DB::one('SELECT id, email FROM users WHERE tg_link_code = ?', [$m[1]]);
                if ($user) {
                    DB::exec('UPDATE users SET tg_chat_id = ?, tg_link_code = NULL WHERE id = ?', [$chatId, $user['id']]);
                    self::send($chatId, "✅ Готово! Уведомления о заказах будут приходить сюда.\nАккаунт: <b>" . e($user['email']) . '</b>');
                } else {
                    self::send($chatId, 'Код привязки не найден или устарел. Откройте «Настройки» на сайте и нажмите «Подключить Telegram» ещё раз.');
                }
            } elseif ($text === '/stop') {
                DB::exec('UPDATE users SET tg_chat_id = NULL WHERE tg_chat_id = ?', [$chatId]);
                self::send($chatId, 'Уведомления отключены. Подключить снова можно в настройках на сайте.');
            } elseif (str_starts_with($text, '/start')) {
                self::send($chatId, 'Здравствуйте! Чтобы получать уведомления о заказах WB, откройте на сайте «Настройки» → «Подключить Telegram».');
            }
        }
        DB::setSetting('tg_offset', (string) $offset);
    }

    /** Рассылает уведомления о новых заказах и смене статусов. */
    public static function notifyOrders(): void
    {
        $rows = DB::all("SELECT o.*, s.name AS store_name, s.user_id, u.tg_chat_id, u.tg_events, u.plan_code, u.plan_expires_at,
                p.title AS p_title
            FROM orders o
            JOIN stores s ON s.id = o.store_id
            JOIN users u ON u.id = s.user_id
            LEFT JOIN products p ON p.store_id = o.store_id AND p.nm_id = o.nm_id
            WHERE (o.notified_status IS NULL OR o.notified_status <> o.status)
              AND o.first_seen_at > ?
            ORDER BY o.order_created_at
            LIMIT 500", [date('Y-m-d H:i:s', time() - 86400)]);
        if (!$rows) {
            return;
        }
        $byUser = [];
        foreach ($rows as $r) {
            $byUser[$r['user_id']][] = $r;
        }
        foreach ($byUser as $list) {
            $u = $list[0];
            $plan = Plans::forUser($u);
            $events = array_filter(explode(',', (string) $u['tg_events']));
            $canSend = self::enabled() && $u['tg_chat_id'] && !empty($plan['telegram']);
            $messages = [];
            foreach ($list as $o) {
                $event = $o['status'] === 'returnDefective' ? 'return' : $o['status'];
                // Старые заказы (старше суток) не присылаем — только отмечаем
                $fresh = strtotime((string) $o['order_created_at']) > time() - 86400 || $o['status'] !== 'created';
                if ($canSend && $fresh && in_array($event, $events, true)) {
                    $messages[] = self::format($o);
                }
                DB::exec('UPDATE orders SET notified_status = ? WHERE id = ?', [$o['status'], $o['id']]);
            }
            if (!$messages) {
                continue;
            }
            if (count($messages) > 15) {
                $extra = count($messages) - 15;
                $messages = array_slice($messages, 0, 15);
                $messages[] = "…и ещё $extra " . plural($extra, 'событие', 'события', 'событий') . ' — смотрите ленту на сайте.';
            }
            // Telegram ограничивает сообщение 4096 символами — режем на части
            $chunk = '';
            foreach ($messages as $m) {
                if (mb_strlen($chunk) + mb_strlen($m) > 3800) {
                    self::send($u['tg_chat_id'], $chunk);
                    $chunk = '';
                }
                $chunk .= ($chunk === '' ? '' : "\n\n") . $m;
            }
            if ($chunk !== '') {
                self::send($u['tg_chat_id'], $chunk);
            }
        }
    }

    private static function format(array $o): string
    {
        $head = [
            'created'         => '🛒 <b>Новый заказ</b>',
            'buyout'          => '✅ <b>Выкуп</b>',
            'cancel'          => '❌ <b>Отмена</b>',
            'return'          => '↩️ <b>Возврат</b>',
            'returnDefective' => '↩️ <b>Возврат (брак)</b>',
        ][$o['status']] ?? '📦 <b>' . e($o['status']) . '</b>';
        $title = $o['p_title'] ?: trim(($o['subject'] ?? '') . ' ' . ($o['brand'] ?? ''));
        $lines = [$head . ' · ' . e($o['store_name'])];
        if ($title !== '') {
            $lines[] = e($title);
        }
        $ids = [];
        if ($o['article']) {
            $ids[] = 'арт. ' . e($o['article']);
        }
        if ($o['nm_id']) {
            $ids[] = 'WB ' . e($o['nm_id']);
        }
        if ($ids) {
            $lines[] = implode(' · ', $ids);
        }
        if ($o['seller_price'] !== null) {
            $lines[] = '💰 ' . money($o['seller_price']);
        }
        $route = scheme_label($o['scheme']);
        if ($o['warehouse']) {
            $route .= ' · ' . $o['warehouse'];
        }
        if ($o['dest_city']) {
            $route .= ' → ' . $o['dest_city'];
        }
        $lines[] = '📦 ' . e($route);
        return implode("\n", $lines);
    }
}
