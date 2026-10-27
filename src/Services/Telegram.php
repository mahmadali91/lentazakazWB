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

    /** Текст последней ошибки Telegram API (для админки и диагностики). */
    public static string $lastError = '';

    public static function api(string $method, array $params = []): ?array
    {
        $token = (string) config('telegram.bot_token', '');
        self::$lastError = '';
        if ($token === '') {
            self::$lastError = 'токен бота не задан';
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
        $curlError = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['ok'])) {
            self::$lastError = $raw === false
                ? 'нет связи с api.telegram.org: ' . $curlError
                : 'HTTP ' . $http . ': ' . (is_array($data) ? (string) ($data['description'] ?? '') : mb_substr((string) $raw, 0, 200));
            log_message('telegram', "$method failed: " . self::$lastError);
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
        $rows = DB::all("SELECT o.*, s.name AS store_name, s.currency AS store_currency, s.user_id, u.plan_code, u.plan_expires_at,
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
        $byOwner = [];
        foreach ($rows as $r) {
            $byOwner[$r['user_id']][] = $r;
        }
        foreach ($byOwner as $ownerId => $list) {
            $plan = Plans::forUser($list[0]);
            // Получатели: владелец кабинета и его сотрудники с подключённым Telegram — каждый по своим магазинам
            // (клиент менеджера — только по магазинам, к которым у него есть доступ) и по выбранным для уведомлений
            // Каналы: Telegram и MAX (настройки событий и магазинов — общие)
            $tgOn = self::enabled();
            $maxOn = Max::enabled();
            $recipients = ($tgOn || $maxOn) && !empty($plan['telegram'])
                ? DB::all('SELECT id, owner_id, store_access, tg_chat_id, max_user_id, tg_events, tg_stores FROM users
                    WHERE (id = ? OR owner_id = ?) AND (' . ($tgOn ? 'tg_chat_id IS NOT NULL' : '0') . ' OR ' . ($maxOn ? 'max_user_id IS NOT NULL' : '0') . ') AND is_blocked = 0', [$ownerId, $ownerId])
                : [];
            foreach ($recipients as &$rcp) {
                $rcp['stores'] = self::recipientStores($rcp);
            }
            unset($rcp);
            $messages = array_fill(0, count($recipients), []);
            foreach ($list as $o) {
                $event = $o['status'] === 'returnDefective' ? 'return' : $o['status'];
                // Старые заказы (старше суток) не присылаем — только отмечаем
                $fresh = strtotime((string) $o['order_created_at']) > time() - 86400 || $o['status'] !== 'created';
                if ($fresh) {
                    foreach ($recipients as $i => $rcp) {
                        if (in_array($event, array_filter(explode(',', (string) $rcp['tg_events'])), true)
                            && ($rcp['stores'] === null || in_array((int) $o['store_id'], $rcp['stores'], true))) {
                            $messages[$i][] = self::format($o);
                        }
                    }
                }
                DB::exec('UPDATE orders SET notified_status = ? WHERE id = ?', [$o['status'], $o['id']]);
            }
            foreach ($recipients as $i => $rcp) {
                if ($tgOn && $rcp['tg_chat_id']) {
                    self::sendBatch($rcp['tg_chat_id'], $messages[$i]);
                }
                if ($maxOn && $rcp['max_user_id']) {
                    self::sendBatch($rcp['max_user_id'], $messages[$i], [Max::class, 'send']);
                }
            }
        }
    }

    /**
     * Магазины, по которым получатель получает уведомления: доступ сотрудника ∩ выбранные в настройках. null — все магазины кабинета.
     * @return int[]|null
     */
    public static function recipientStores(array $user): ?array
    {
        $access = StoreService::memberAccess($user);
        $chosen = $user['tg_stores'] !== null && $user['tg_stores'] !== '' ? array_map('intval', json_decode((string) $user['tg_stores'], true) ?: []) : null;
        if ($access === null) {
            return $chosen;
        }
        return $chosen === null ? $access : array_values(array_intersect($access, $chosen));
    }

    /** Отправляет пачку уведомлений, укладываясь в лимит Telegram (4096 символов на сообщение). */
    private static function sendBatch(int|string $chatId, array $messages, ?callable $send = null): void
    {
        $send ??= [self::class, 'send'];
        if (!$messages) {
            return;
        }
        if (count($messages) > 15) {
            $extra = count($messages) - 15;
            $messages = array_slice($messages, 0, 15);
            $messages[] = "…и ещё $extra " . plural($extra, 'событие', 'события', 'событий') . ' — смотрите ленту на сайте.';
        }
        $chunk = '';
        foreach ($messages as $m) {
            if (mb_strlen($chunk) + mb_strlen($m) > 3800) {
                $send($chatId, $chunk);
                $chunk = '';
            }
            $chunk .= ($chunk === '' ? '' : "\n\n") . $m;
        }
        if ($chunk !== '') {
            $send($chatId, $chunk);
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
            $lines[] = '💰 ' . number_format((float) $o['seller_price'], 0, ',', ' ') . ' ' . currency_sign((string) ($o['store_currency'] ?? 'RUB'));
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
