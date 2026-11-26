<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Контакты владельца кабинета (агентства, менеджера) для его сотрудников и клиентов:
 * куда писать, чтобы подключить ещё магазин или продлить доступ. Хранятся в users.contacts (JSON).
 */
final class Contacts
{
    public const FIELDS = ['name' => 'Имя или название', 'phone' => 'Телефон', 'telegram' => 'Telegram', 'max' => 'MAX', 'email' => 'Email', 'note' => 'Комментарий'];

    public static function of(?array $owner): array
    {
        $c = $owner ? json_decode((string) ($owner['contacts'] ?? ''), true) : null;
        return is_array($c) ? array_intersect_key($c, self::FIELDS) : [];
    }

    /** Данные из формы настроек → JSON (NULL, если ничего не указано). */
    public static function input(array $in): ?string
    {
        $c = [];
        foreach (self::FIELDS as $k => $_) {
            $v = trim((string) ($in[$k] ?? ''));
            if ($k === 'telegram') {
                $v = ltrim(preg_replace('#^(https?://)?(t\.me|telegram\.me)/#i', '', $v), '@');
            }
            $v = mb_substr($v, 0, $k === 'note' ? 300 : 120);
            if ($v !== '') {
                $c[$k] = $v;
            }
        }
        return $c ? json_encode($c, JSON_UNESCAPED_UNICODE) : null;
    }

    /** Владелец кабинета для сотрудника/клиента (с контактами и тарифом). */
    public static function ownerOf(?array $user): ?array
    {
        if (!$user || empty($user['owner_id'])) {
            return null;
        }
        return DB::one('SELECT * FROM users WHERE id = ?', [(int) $user['owner_id']]);
    }

    /** Контакты в HTML: ссылки на телефон, Telegram и почту. Если контактов нет — email владельца. */
    public static function html(array $owner, bool $inline = false): string
    {
        $c = self::of($owner);
        $parts = [];
        if (!empty($c['phone'])) {
            $parts[] = '<a href="tel:' . e(preg_replace('/[^\d+]/', '', $c['phone'])) . '">' . e($c['phone']) . '</a>';
        }
        if (!empty($c['telegram'])) {
            $tg = preg_match('/^[A-Za-z0-9_]{4,64}$/', $c['telegram'])
                ? '<a href="https://t.me/' . e($c['telegram']) . '" target="_blank" rel="noopener">Telegram @' . e($c['telegram']) . '</a>'
                : 'Telegram ' . e($c['telegram']);
            $parts[] = $tg;
        }
        if (!empty($c['max'])) {
            $parts[] = preg_match('#^https://max\.ru/[\w./?=-]+$#', $c['max'])
                ? '<a href="' . e($c['max']) . '" target="_blank" rel="noopener">MAX</a>' : 'MAX ' . e($c['max']);
        }
        $email = $c['email'] ?? (filter_var($owner['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $owner['email'] : '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $parts[] = '<a href="mailto:' . e($email) . '">' . e($email) . '</a>';
        }
        $name = $c['name'] ?? ($owner['name'] ?? '');
        if ($inline) {
            return trim(($name !== '' ? '<b>' . e($name) . '</b>' . ($parts ? ': ' : '') : '') . implode(' · ', $parts));
        }
        $h = $name !== '' ? '<div><b>' . e($name) . '</b></div>' : '';
        $h .= $parts ? '<div class="contact-links">' . implode('<span class="muted"> · </span>', $parts) . '</div>' : '';
        if (!empty($c['note'])) {
            $h .= '<div class="small muted" style="margin-top:4px">' . nl2br(e($c['note'])) . '</div>';
        }
        return $h;
    }

    /** Контакты одной строкой без разметки (для сообщений на странице входа). */
    public static function text(array $owner): string
    {
        $c = self::of($owner);
        $parts = array_filter([
            $c['phone'] ?? '', !empty($c['telegram']) ? 'Telegram @' . $c['telegram'] : '', !empty($c['max']) ? 'MAX ' . $c['max'] : '',
            $c['email'] ?? (filter_var($owner['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $owner['email'] : ''),
        ]);
        $name = $c['name'] ?? ($owner['name'] ?? '');
        return trim($name . ($name !== '' && $parts ? ': ' : '') . implode(', ', $parts));
    }

    /** Сообщение пользователю в MAX и Telegram (что подключено). */
    public static function notify(array $user, string $html): void
    {
        if (!empty($user['max_user_id']) && Max::enabled()) {
            Max::send($user['max_user_id'], $html);
        }
        if (!empty($user['tg_chat_id']) && Telegram::enabled()) {
            Telegram::send($user['tg_chat_id'], $html);
        }
    }
}
