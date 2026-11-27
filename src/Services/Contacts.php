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
    public const FIELDS = ['name' => 'Имя или название', 'phone' => 'Телефон', 'telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'max' => 'MAX', 'email' => 'Email', 'note' => 'Комментарий'];

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
            if ($k === 'whatsapp') {
                // Номер WhatsApp: из ссылки wa.me или номера — только цифры (8XXXXXXXXXX → 7XXXXXXXXXX)
                $v = preg_replace('/\D/', '', preg_replace('#^(https?://)?(wa\.me|api\.whatsapp\.com/send\?phone=)/?#i', '', $v));
                if (strlen($v) === 11 && $v[0] === '8') {
                    $v = '7' . substr($v, 1);
                }
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
        if (!empty($c['whatsapp']) && preg_match('/^\d{10,15}$/', $c['whatsapp'])) {
            $parts[] = '<a href="https://wa.me/' . e($c['whatsapp']) . '" target="_blank" rel="noopener">WhatsApp</a>';
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

    /** Ссылки для связи: телефон, Telegram, WhatsApp, MAX, email. @return array<int, array{0:string,1:string,2:string}> [тип, подпись, ссылка] */
    public static function links(array $owner): array
    {
        $c = self::of($owner);
        $out = [];
        if (!empty($c['phone'])) {
            $out[] = ['phone', $c['phone'], 'tel:' . preg_replace('/[^\d+]/', '', $c['phone'])];
        }
        if (!empty($c['telegram']) && preg_match('/^[A-Za-z0-9_]{4,64}$/', $c['telegram'])) {
            $out[] = ['telegram', 'Telegram', 'https://t.me/' . $c['telegram']];
        }
        if (!empty($c['whatsapp']) && preg_match('/^\d{10,15}$/', $c['whatsapp'])) {
            $out[] = ['whatsapp', 'WhatsApp', 'https://wa.me/' . $c['whatsapp']];
        }
        if (!empty($c['max']) && preg_match('#^https://max\.ru/[\w./?=-]+$#', $c['max'])) {
            $out[] = ['max', 'MAX', $c['max']];
        }
        $email = $c['email'] ?? (filter_var($owner['email'] ?? '', FILTER_VALIDATE_EMAIL) ? $owner['email'] : '');
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['email', $email, 'mailto:' . $email];
        }
        return $out;
    }

    /** Кнопки связи с владельцем кабинета (агентством). */
    public static function buttons(array $owner): string
    {
        $h = '';
        foreach (self::links($owner) as [$type, $label, $href]) {
            $ext = str_starts_with($href, 'https://') ? ' target="_blank" rel="noopener"' : '';
            $h .= '<a class="btn btn-sm contact-btn contact-' . $type . '" href="' . e($href) . '"' . $ext . '>' . e($label) . '</a>';
        }
        $c = self::of($owner);
        $name = $c['name'] ?? ($owner['name'] ?? '');
        return ($name !== '' ? '<div class="contact-name"><b>' . e($name) . '</b></div>' : '')
            . ($h !== '' ? '<div class="contact-btns">' . $h . '</div>' : '<div class="muted small">Владелец кабинета ещё не указал контакты.</div>')
            . (!empty($c['note']) ? '<div class="small muted" style="margin-top:8px">' . nl2br(e($c['note'])) . '</div>' : '');
    }

    /** Контакты одной строкой без разметки (для сообщений на странице входа). */
    public static function text(array $owner): string
    {
        $c = self::of($owner);
        $parts = array_filter([
            $c['phone'] ?? '', !empty($c['telegram']) ? 'Telegram @' . $c['telegram'] : '', !empty($c['whatsapp']) ? 'WhatsApp +' . $c['whatsapp'] : '', !empty($c['max']) ? 'MAX ' . $c['max'] : '',
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
