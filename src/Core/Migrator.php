<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Автоматические миграции схемы БД для уже установленного сервиса.
 * Запускается из index.php и cron; версия кэшируется в storage/cache, чтобы не обращаться к БД на каждый запрос.
 */
final class Migrator
{
    public const VERSION = 16;

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
            if ($version < 3) {
                self::v3();
            }
            if ($version < 4) {
                self::v4();
            }
            if ($version < 5) {
                self::run(self::API_KEYS_DDL);
            }
            if ($version < 6) {
                self::v6();
            }
            if ($version < 13) {
                self::v13();
            }
            if ($version < 14) {
                // Пересчёт сумм в рубли по курсу ЦБ: настройка кабинета и кэш курсов
                self::addColumn('users', 'show_rub', 'TINYINT(1) NOT NULL DEFAULT 0');
                self::run(self::FX_DDL);
            }
            if ($version < 16) {
                // Тариф для менеджеров: клиенту-сотруднику — доступ только к своим магазинам; уведомления Telegram по выбранным магазинам
                self::addColumn('users', 'store_access', 'TEXT NULL AFTER owner_id');
                self::addColumn('users', 'tg_stores', 'TEXT NULL AFTER tg_events');
                self::run('ALTER TABLE stores ADD KEY idx_stores_seller_sid (seller_sid)');
                // Тариф «Агентство» — магазинов без ограничений
                self::run('ALTER TABLE users MODIFY extra_stores SMALLINT UNSIGNED NOT NULL DEFAULT 0');
                self::run('ALTER TABLE payments MODIFY extra_stores SMALLINT UNSIGNED NOT NULL DEFAULT 0');
                // Уже подключённые магазины сверх включённых в тариф остаются работать до конца оплаченного срока (как доп. магазины)
                foreach (\App\Services\Plans::all() as $code => $p) {
                    if (empty($p['store_price'])) {
                        continue;
                    }
                    $max = \App\Services\Plans::maxExtra($p + ['code' => $code]);
                    DB::exec("UPDATE users u SET u.extra_stores = LEAST(?, GREATEST(u.extra_stores,
                            (SELECT COUNT(*) FROM stores s WHERE s.user_id = u.id AND s.is_active = 1) - ?))
                        WHERE u.plan_code = ? AND u.owner_id IS NULL", [$max, (int) $p['stores'], $code]);
                }
            }
            if ($version < 15) {
                // Дополнительные магазины к тарифу (покупаются отдельно до конца подписки)
                self::addColumn('users', 'extra_stores', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER plan_expires_at');
                self::addColumn('payments', 'kind', "VARCHAR(16) NOT NULL DEFAULT 'plan' AFTER plan_code");
                self::addColumn('payments', 'extra_stores', 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER months');
            }
            if ($version < 12 && DB::setting('cfg.app.support_telegram') === null) {
                // Telegram-канал с новостями и поддержкой
                DB::setSetting('cfg.app.support_telegram', 'wblinkoz');
            }
            if ($version < 11) {
                // Валюты источников WB различаются: Лента заказов — в валюте кабинета (как отчёты и кабинет),
                // Статистика (заказы, продажи) — в рублях. Храним обе цены и курс между ними для магазина.
                self::addColumn('orders', 'feed_price', 'DECIMAL(12,2) NULL AFTER seller_price');
                self::addColumn('orders', 'stats_price', 'DECIMAL(12,2) NULL AFTER feed_price');
                self::addColumn('stores', 'stats_rate', 'DECIMAL(10,4) NULL AFTER currency');
                DB::exec('UPDATE orders SET stats_price = seller_price WHERE finished_price IS NOT NULL AND stats_price IS NULL');
                // Перезагрузить Ленту за 30 дней, чтобы вернуть цены в валюте кабинета (уведомления не отправляются — статусы не меняются)
                DB::exec('UPDATE stores SET feed_state = NULL, feed_next_at = NULL');
            }
            if ($version < 10) {
                // Продажи и возвраты из Статистики WB (supplier/sales): выкуп и возврат — отдельные события со своей датой, как в кабинете
                self::addColumn('stores', 'sales_cursor', 'VARCHAR(40) NULL AFTER stats_ok_at');
                self::addColumn('stores', 'sales_next_at', 'DATETIME NULL AFTER sales_cursor');
                self::addColumn('stores', 'sales_ok_at', 'DATETIME NULL AFTER sales_next_at');
                self::run(self::SALES_DDL);
            }
            if ($version < 9) {
                // Признак «заказ есть в Ленте заказов WB»: кабинет WB не считает заказы, которые есть только в Статистике (неоплаченные)
                self::addColumn('orders', 'in_feed', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_b2b');
                DB::exec('UPDATE orders SET in_feed = 1 WHERE warehouse_region IS NOT NULL OR dest_city IS NOT NULL OR chrt_id IS NOT NULL');
            }
            if ($version < 8) {
                // Валюта кабинета продавца (RUB, KGS, KZT…) — WB отдаёт суммы в ней
                self::addColumn('stores', 'currency', 'VARCHAR(8) NULL AFTER seller_country');
                DB::exec('UPDATE stores s SET currency = (SELECT r.currency FROM wb_reports r WHERE r.store_id = s.id AND r.currency <> \'\' ORDER BY r.date_from DESC LIMIT 1) WHERE currency IS NULL');
            }
            if ($version < 7) {
                // Перезагрузить Статистику за историю тарифа: цены заказов берутся из priceWithDisc (как в кабинете WB)
                DB::exec('UPDATE stores SET stats_cursor = NULL, stats_next_at = NULL');
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

    /** v3: комиссии WB по категориям, тарифы складов, объём и остатки товаров. */
    private static function v3(): void
    {
        self::addColumn('products', 'subject_id', 'INT NULL AFTER subject');
        self::addColumn('products', 'volume_l', 'DECIMAL(10,3) NULL AFTER photo');
        self::addColumn('products', 'volume_src', 'VARCHAR(16) NULL AFTER volume_l');
        self::addColumn('products', 'dims', 'VARCHAR(40) NULL AFTER volume_src');
        self::addColumn('products', 'stock_wb', 'INT NULL AFTER dims');
        self::addColumn('products', 'stock_to_client', 'INT NULL AFTER stock_wb');
        self::addColumn('products', 'stock_from_client', 'INT NULL AFTER stock_to_client');
        self::addColumn('products', 'stock_at', 'DATETIME NULL AFTER stock_from_client');
        self::addColumn('stores', 'seller_country', "VARCHAR(8) NOT NULL DEFAULT '' AFTER seller_tin");
        self::addColumn('stores', 'remains_state', 'TEXT NULL AFTER cards_next_at');
        self::addColumn('stores', 'remains_next_at', 'DATETIME NULL AFTER remains_state');
        self::addColumn('stores', 'remains_ok_at', 'DATETIME NULL AFTER remains_next_at');
        self::addColumn('stores', 'tariffs_next_at', 'DATETIME NULL AFTER remains_ok_at');
        self::addColumn('stores', 'tariffs_ok_at', 'DATETIME NULL AFTER tariffs_next_at');
        self::run(self::COMMISSIONS_DDL);
        self::run(self::BOX_TARIFFS_DDL);
    }

    /** v4: финансы — отчёты реализации WB, платное хранение, реклама, прочие расходы, самовыкупы. */
    private static function v4(): void
    {
        self::addColumn('stores', 'fin_state', 'TEXT NULL AFTER tariffs_ok_at');
        self::addColumn('stores', 'fin_next_at', 'DATETIME NULL AFTER fin_state');
        self::addColumn('stores', 'fin_ok_at', 'DATETIME NULL AFTER fin_next_at');
        self::addColumn('stores', 'storage_state', 'TEXT NULL AFTER fin_ok_at');
        self::addColumn('stores', 'storage_next_at', 'DATETIME NULL AFTER storage_state');
        self::addColumn('stores', 'storage_ok_at', 'DATETIME NULL AFTER storage_next_at');
        self::addColumn('stores', 'ads_state', 'TEXT NULL AFTER storage_ok_at');
        self::addColumn('stores', 'ads_next_at', 'DATETIME NULL AFTER ads_state');
        self::addColumn('stores', 'ads_ok_at', 'DATETIME NULL AFTER ads_next_at');
        foreach (self::FINANCE_DDL as $sql) {
            self::run($sql);
        }
    }

    public const FINANCE_DDL = [
        'CREATE TABLE IF NOT EXISTS wb_reports (
            store_id      INT UNSIGNED NOT NULL,
            report_id     BIGINT NOT NULL,
            date_from     DATE NOT NULL,
            date_to       DATE NOT NULL,
            create_date   DATE NULL,
            report_type   TINYINT NOT NULL DEFAULT 1,
            currency      VARCHAR(8) NULL,
            retail_amount DECIMAL(14,2) NULL,
            for_pay       DECIMAL(14,2) NULL,
            delivery      DECIMAL(14,2) NULL,
            storage       DECIMAL(14,2) NULL,
            acceptance    DECIMAL(14,2) NULL,
            deduction     DECIMAL(14,2) NULL,
            penalty       DECIMAL(14,2) NULL,
            additional    DECIMAL(14,2) NULL,
            cashback      DECIMAL(14,2) NULL,
            bank_payment  DECIMAL(14,2) NULL,
            rows_loaded   INT NOT NULL DEFAULT 0,
            rrd_cursor    BIGINT NOT NULL DEFAULT 0,
            detail_done   TINYINT(1) NOT NULL DEFAULT 0,
            loaded_at     DATETIME NULL,
            updated_at    DATETIME NOT NULL,
            PRIMARY KEY (store_id, report_id),
            KEY idx_rep_period (store_id, date_from),
            CONSTRAINT fk_rep_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS wb_report_rows (
            store_id          INT UNSIGNED NOT NULL,
            rrd_id            BIGINT NOT NULL,
            report_id         BIGINT NOT NULL,
            date_from         DATE NOT NULL,
            date_to           DATE NOT NULL,
            gi_id             BIGINT NULL,
            subject           VARCHAR(160) NULL,
            nm_id             BIGINT NULL,
            brand             VARCHAR(160) NULL,
            vendor_code       VARCHAR(120) NULL,
            title             VARCHAR(255) NULL,
            ts_name           VARCHAR(60) NULL,
            barcode           VARCHAR(40) NULL,
            doc_type          VARCHAR(40) NULL,
            oper              VARCHAR(160) NULL,
            quantity          INT NOT NULL DEFAULT 0,
            retail_price      DECIMAL(12,2) NOT NULL DEFAULT 0,
            retail_amount     DECIMAL(12,2) NOT NULL DEFAULT 0,
            retail_price_disc DECIMAL(12,2) NOT NULL DEFAULT 0,
            sale_percent      DECIMAL(6,2) NULL,
            commission_pct    DECIMAL(8,4) NULL,
            spp               DECIMAL(6,2) NULL,
            for_pay           DECIMAL(12,2) NOT NULL DEFAULT 0,
            acquiring_fee     DECIMAL(12,2) NOT NULL DEFAULT 0,
            delivery_amount   INT NOT NULL DEFAULT 0,
            return_amount     INT NOT NULL DEFAULT 0,
            delivery_service  DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus_type        VARCHAR(255) NULL,
            penalty           DECIMAL(12,2) NOT NULL DEFAULT 0,
            additional        DECIMAL(12,2) NOT NULL DEFAULT 0,
            paid_storage      DECIMAL(12,2) NOT NULL DEFAULT 0,
            deduction         DECIMAL(12,2) NOT NULL DEFAULT 0,
            paid_acceptance   DECIMAL(12,2) NOT NULL DEFAULT 0,
            cashback_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
            cashback_discount DECIMAL(12,2) NOT NULL DEFAULT 0,
            cashback_comm     DECIMAL(12,2) NOT NULL DEFAULT 0,
            office_name       VARCHAR(160) NULL,
            pvz_name          VARCHAR(255) NULL,
            order_dt          DATETIME NULL,
            sale_dt           DATETIME NULL,
            shk_id            BIGINT NULL,
            srid              VARCHAR(80) NULL,
            delivery_method   VARCHAR(80) NULL,
            is_b2b            TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (store_id, rrd_id),
            KEY idx_rr_report (store_id, report_id),
            KEY idx_rr_period (store_id, date_from),
            KEY idx_rr_srid (store_id, srid),
            KEY idx_rr_nm (store_id, nm_id),
            CONSTRAINT fk_rr_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS wb_storage (
            store_id    INT UNSIGNED NOT NULL,
            day         DATE NOT NULL,
            nm_id       BIGINT NOT NULL,
            vendor_code VARCHAR(120) NULL,
            subject     VARCHAR(160) NULL,
            volume      DECIMAL(12,3) NULL,
            qty         INT NOT NULL DEFAULT 0,
            amount      DECIMAL(12,4) NOT NULL DEFAULT 0,
            PRIMARY KEY (store_id, day, nm_id),
            CONSTRAINT fk_stor_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS wb_ad_costs (
            store_id     INT UNSIGNED NOT NULL,
            uid          CHAR(32) NOT NULL,
            advert_id    BIGINT NOT NULL,
            camp_name    VARCHAR(255) NULL,
            advert_type  INT NULL,
            payment_type VARCHAR(40) NULL,
            upd_num      BIGINT NULL,
            upd_time     DATETIME NULL,
            day          DATE NOT NULL,
            amount       DECIMAL(12,2) NOT NULL DEFAULT 0,
            PRIMARY KEY (store_id, uid),
            KEY idx_ad_day (store_id, day),
            CONSTRAINT fk_ad_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS wb_ad_nms (
            store_id  INT UNSIGNED NOT NULL,
            advert_id BIGINT NOT NULL,
            nm_id     BIGINT NOT NULL,
            manual    TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (store_id, advert_id, nm_id),
            CONSTRAINT fk_adnm_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS fin_expenses (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id     INT UNSIGNED NOT NULL,
            store_id    INT UNSIGNED NULL,
            day         DATE NOT NULL,
            category    VARCHAR(80) NOT NULL,
            nm_id       BIGINT NULL,
            amount      DECIMAL(12,2) NOT NULL,
            note        VARCHAR(255) NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_exp_user (user_id, day),
            CONSTRAINT fk_exp_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        'CREATE TABLE IF NOT EXISTS fin_selfbuys (
            user_id    INT UNSIGNED NOT NULL,
            srid       VARCHAR(80) NOT NULL,
            extra_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            note       VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (user_id, srid),
            CONSTRAINT fk_sb_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ];

    public const FX_DDL = 'CREATE TABLE IF NOT EXISTS fx_rates (
        code  CHAR(3) NOT NULL,
        day   DATE NOT NULL,
        rate  DECIMAL(16,8) NOT NULL,
        PRIMARY KEY (code, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    /** v13: налог, НДС и фиксированные расходы в неделю — у каждого магазина (раньше налог был общим на аккаунт). */
    private static function v13(): void
    {
        self::addColumn('stores', 'tax_rate', 'DECIMAL(5,2) NULL AFTER stats_rate');
        self::addColumn('stores', 'vat_rate', 'DECIMAL(5,2) NULL AFTER tax_rate');
        self::addColumn('stores', 'weekly_expense', 'DECIMAL(12,2) NULL AFTER vat_rate');
        foreach (DB::all('SELECT id, profit_settings FROM users WHERE profit_settings IS NOT NULL') as $u) {
            $s = json_decode((string) $u['profit_settings'], true);
            if (is_array($s)) {
                DB::exec('UPDATE stores SET tax_rate = ?, vat_rate = ? WHERE user_id = ? AND tax_rate IS NULL',
                    [is_numeric($s['tax'] ?? null) ? (float) $s['tax'] : 6.0, is_numeric($s['vat'] ?? null) ? (float) $s['vat'] : 0.0, (int) $u['id']]);
            }
        }
        DB::exec('UPDATE stores SET tax_rate = 6 WHERE tax_rate IS NULL');
        DB::exec('UPDATE stores SET vat_rate = 0 WHERE vat_rate IS NULL');
    }

    /** v6: телефон клиента, индекс по дате статуса (выкупы за период), контакты поддержки и бот по умолчанию. */
    private static function v6(): void
    {
        self::addColumn('users', 'phone', 'VARCHAR(20) NULL AFTER name');
        self::run('ALTER TABLE orders ADD KEY idx_orders_store_status_at (store_id, status_at)');
        foreach (['cfg.app.support_phone' => '+79913004737', 'cfg.app.support_email' => 'admin@linkoz.ru', 'cfg.telegram.bot_username' => 'WBLinkoz_bot'] as $k => $v) {
            if (DB::setting($k) === null) {
                DB::setSetting($k, $v);
            }
        }
    }

    /** v5: ключи API (REST и MCP-сервер для подключения к Claude). В базе — только SHA-256 ключа. */
    public const API_KEYS_DDL = 'CREATE TABLE IF NOT EXISTS api_keys (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id      INT UNSIGNED NOT NULL,
        name         VARCHAR(80) NOT NULL,
        token_hash   CHAR(64) NOT NULL,
        token_hint   VARCHAR(16) NOT NULL,
        requests     INT UNSIGNED NOT NULL DEFAULT 0,
        last_used_at DATETIME NULL,
        last_ip      VARCHAR(45) NULL,
        created_at   DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_api_token (token_hash),
        KEY idx_api_user (user_id),
        CONSTRAINT fk_api_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public const SALES_DDL = 'CREATE TABLE IF NOT EXISTS wb_sales (
        store_id       INT UNSIGNED NOT NULL,
        sale_id        VARCHAR(40) NOT NULL,
        srid           VARCHAR(80) NULL,
        nm_id          BIGINT NULL,
        is_return      TINYINT(1) NOT NULL DEFAULT 0,
        sale_dt        DATETIME NOT NULL,
        last_change    DATETIME NULL,
        price_with_disc DECIMAL(12,2) NULL,
        finished_price DECIMAL(12,2) NULL,
        for_pay        DECIMAL(12,2) NULL,
        is_mp          TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (store_id, sale_id),
        KEY idx_sales_dt (store_id, sale_dt),
        KEY idx_sales_srid (store_id, srid),
        CONSTRAINT fk_sales_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public const COMMISSIONS_DDL = 'CREATE TABLE IF NOT EXISTS wb_commissions (
        store_id     INT UNSIGNED NOT NULL,
        subject_id   INT NOT NULL,
        subject_name VARCHAR(160) NOT NULL,
        parent_name  VARCHAR(160) NULL,
        fbo          DECIMAL(5,2) NULL,
        fbs          DECIMAL(5,2) NULL,
        dbs          DECIMAL(5,2) NULL,
        edbs         DECIMAL(5,2) NULL,
        pickup       DECIMAL(5,2) NULL,
        booking      DECIMAL(5,2) NULL,
        updated_at   DATETIME NOT NULL,
        PRIMARY KEY (store_id, subject_id),
        KEY idx_comm_name (store_id, subject_name),
        CONSTRAINT fk_comm_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public const BOX_TARIFFS_DDL = 'CREATE TABLE IF NOT EXISTS wb_box_tariffs (
        warehouse_name VARCHAR(160) NOT NULL,
        geo_name       VARCHAR(160) NULL,
        delivery_base  DECIMAL(10,2) NULL,
        delivery_liter DECIMAL(10,2) NULL,
        delivery_coef  DECIMAL(7,2) NULL,
        mp_base        DECIMAL(10,2) NULL,
        mp_liter       DECIMAL(10,2) NULL,
        mp_coef        DECIMAL(7,2) NULL,
        updated_at     DATETIME NOT NULL,
        PRIMARY KEY (warehouse_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

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
