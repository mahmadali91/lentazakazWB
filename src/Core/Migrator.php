<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Автоматические миграции схемы БД для уже установленного сервиса.
 * Запускается из index.php и cron; версия кэшируется в storage/cache, чтобы не обращаться к БД на каждый запрос.
 */
final class Migrator
{
    public const VERSION = 2;

    public static function ensure(): void
    {
        $cache = BASE_PATH . '/storage/cache/schema_version';
        if (is_file($cache) && (int) file_get_contents($cache) >= self::VERSION) {
            return;
        }
        $lock = fopen(BASE_PATH . '/storage/migrate.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        try {
            $version = (int) DB::setting('schema_version', '1');
            if ($version < 2) {
                self::v2();
            }
            DB::setSetting('schema_version', (string) self::VERSION);
            @file_put_contents($cache, (string) self::VERSION);
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** v2: сотрудники, заметки о клиентах, себестоимость и настройки прибыли, напоминания о сроке. */
    private static function v2(): void
    {
        self::addColumn('users', 'owner_id', 'INT UNSIGNED NULL AFTER tg_events');
        self::addColumn('users', 'note', 'VARCHAR(500) NULL AFTER owner_id');
        self::addColumn('users', 'profit_settings', 'TEXT NULL AFTER note');
        self::addColumn('users', 'remind_sent_for', 'DATETIME NULL AFTER profit_settings');
        self::run('ALTER TABLE users ADD KEY idx_users_owner (owner_id)');
        self::run('ALTER TABLE users ADD CONSTRAINT fk_users_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE');
        self::addColumn('products', 'cost_price', 'DECIMAL(12,2) NULL AFTER photo');
        self::addColumn('products', 'commission_pct', 'DECIMAL(5,2) NULL AFTER cost_price');
        // Карточки для всех товаров, которые уже есть в заказах (чтобы им можно было указать себестоимость)
        DB::exec('INSERT IGNORE INTO products (store_id, nm_id, vendor_code, brand, subject, updated_at)
            SELECT store_id, nm_id, MAX(article), MAX(brand), MAX(subject), NOW() FROM orders WHERE nm_id IS NOT NULL GROUP BY store_id, nm_id');
    }

    private static function addColumn(string $table, string $column, string $definition): void
    {
        $exists = DB::value(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
        if (!(int) $exists) {
            self::run("ALTER TABLE $table ADD COLUMN $column $definition");
        }
    }

    /** Выполняет DDL, игнорируя «уже существует» (повторный запуск, параллельные запросы). */
    private static function run(string $sql): void
    {
        try {
            DB::pdo()->exec($sql);
        } catch (\PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            // 1060 — колонка уже есть, 1061 — индекс уже есть, 1826/121 — внешний ключ уже есть
            if (!in_array($code, [1060, 1061, 1826, 121], true)) {
                throw $e;
            }
        }
    }
}
