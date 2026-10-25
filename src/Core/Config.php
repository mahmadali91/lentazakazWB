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
