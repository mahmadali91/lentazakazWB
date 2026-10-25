<?php
declare(strict_types=1);

namespace App\Core;

/** Отправка писем через встроенную функцию mail() (на Beget работает без настройки). */
final class Mailer
{
    public static function send(string $to, string $subject, string $text): bool
    {
        $from = (string) Config::get('mail.from', 'noreply@localhost');
        $fromName = (string) Config::get('mail.from_name', Config::get('app.name'));
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $from . '>',
            'Reply-To: ' . (Config::get('app.support_email') ?: $from),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $ok = @mail(
            $to,
            '=?UTF-8?B?' . base64_encode($subject) . '?=',
            chunk_split(base64_encode($text)),
            implode("\r\n", $headers),
            '-f' . $from
        );
        if (!$ok) {
            log_message('mail', "Не удалось отправить письмо на $to: $subject");
        }
        return $ok;
    }
}
