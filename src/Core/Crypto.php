<?php
declare(strict_types=1);

namespace App\Core;

/** Шифрование токенов WB в базе (AES-256-GCM, ключ — app.key из конфига). */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    private static function key(): string
    {
        $key = base64_decode((string) Config::get('app.key', ''), true);
        if ($key === false || strlen($key) < 32) {
            throw new \RuntimeException('Не задан app.key в config/config.php');
        }
        return substr($key, 0, 32);
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Ошибка шифрования');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        if (!str_starts_with($encoded, 'v1:')) {
            throw new \RuntimeException('Неизвестный формат шифра');
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            throw new \RuntimeException('Повреждённые данные');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Не удалось расшифровать токен (сменился app.key?)');
        }
        return $plain;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }
}
