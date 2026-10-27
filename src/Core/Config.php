<?php
declare(strict_types=1);

namespace App\Core;

final class Config
{
    private static array $data = [];
    private static bool $installed = false;

    public static function load(): void
    {
        $file = BASE_PATH . '/config/config.php';
        if (is_file($file)) {
            self::$data = require $file;
            self::$installed = true;
        } else {
            self::$data = require BASE_PATH . '/config/config.example.php';
        }
        self::$data['plans'] = require BASE_PATH . '/config/plans.php';
    }

    public static function installed(): bool
    {
        return self::$installed;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Значения, которые админ меняет в «Настройках сервиса», хранятся в таблице settings с префиксом cfg. */
    public const DB_KEYS = [
        'app.support_phone'     => false,
        'app.support_email'     => false,
        'app.support_telegram'  => false,
        'telegram.bot_username' => false,
        'telegram.bot_token'    => true,  // true — хранится зашифрованным
        'max.bot_username'      => false,
        'max.bot_token'         => true,
        'yookassa.shop_id'      => false,
        'yookassa.secret_key'   => true,
        'yookassa.send_receipt' => false,
        'yookassa.vat_code'     => false,
        'company.name'          => false,  // реквизиты исполнителя — подставляются в оферту и политику
        'company.inn'           => false,
        'company.ogrn'          => false,
        'company.address'       => false,
        'company.bank'          => false,
    ];

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$data;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = $ref[$part] ?? [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    /** Накладывает настройки из БД поверх config.php. Вызывается после подключения к БД. */
    public static function overlayFromDb(): void
    {
        try {
            $rows = DB::all("SELECT name, value FROM settings WHERE name LIKE 'cfg.%'");
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $r) {
            $key = substr($r['name'], 4);
            if (!array_key_exists($key, self::DB_KEYS) || $r['value'] === null || $r['value'] === '') {
                continue;
            }
            $value = (string) $r['value'];
            if (self::DB_KEYS[$key]) {
                try {
                    $value = Crypto::decrypt($value);
                } catch (\Throwable) {
                    continue;
                }
            }
            self::set($key, $value);
        }
    }

    /** Записывает config/config.php (используется установщиком). */
    public static function write(array $data): void
    {
        $code = "<?php\n// Создано установщиком " . date('Y-m-d H:i') . ". Не публикуйте этот файл.\nreturn " . var_export($data, true) . ";\n";
        $file = BASE_PATH . '/config/config.php';
        if (file_put_contents($file, $code, LOCK_EX) === false) {
            throw new \RuntimeException('Не удалось записать config/config.php — проверьте права на папку config');
        }
        @chmod($file, 0640);
        self::load();
    }
}
