-- Лента заказов WB — схема базы данных (MySQL 5.7+ / 8.x, MariaDB 10.3+)
-- Применяется автоматически веб-установщиком (/install) или вручную:
--   mysql -u USER -p DBNAME < database/schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  name            VARCHAR(120) NOT NULL DEFAULT '',
  phone           VARCHAR(20) NULL,               -- телефон клиента (обязателен для владельцев кабинетов)
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_super        TINYINT(1) NOT NULL DEFAULT 0, -- супер-админ (владелец сервиса): всё бесплатно и без ограничений
  is_blocked      TINYINT(1) NOT NULL DEFAULT 0,
  plan_code       VARCHAR(32) NOT NULL DEFAULT 'free',
  plan_expires_at DATETIME NULL,
  extra_stores    SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- доп. магазины к тарифу, оплаченные до конца подписки
  tg_chat_id      BIGINT NULL,
  tg_link_code    VARCHAR(32) NULL,
  max_user_id     BIGINT NULL,                -- MAX: id пользователя в диалоге с ботом
  max_link_code   VARCHAR(32) NULL,           -- MAX: код привязки из ссылки max.ru/<бот>?start=<код>
  tg_events       VARCHAR(64) NOT NULL DEFAULT 'created',
  tg_stores       TEXT NULL,                  -- JSON: id магазинов для уведомлений Telegram (NULL — все доступные)
  owner_id        INT UNSIGNED NULL,          -- сотрудник: id владельца кабинета
  store_access    TEXT NULL,                  -- сотрудник: JSON id магазинов, к которым есть доступ (NULL — все магазины кабинета)
  note            VARCHAR(500) NULL,          -- заметка администратора о клиенте
  profit_settings TEXT NULL,                  -- JSON: комиссия, логистика, налог, прочие расходы
  show_rub        TINYINT(1) NOT NULL DEFAULT 0, -- показывать суммы в рублях по курсу ЦБ
  remind_sent_for DATETIME NULL,              -- для какого срока тарифа уже отправлено напоминание
  created_at      DATETIME NOT NULL,
  last_login_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_tg_code (tg_link_code),
  KEY idx_users_owner (owner_id),
  CONSTRAINT fk_users_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stores (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,
  seller_name     VARCHAR(255) NOT NULL DEFAULT '',
  seller_sid      VARCHAR(64) NOT NULL DEFAULT '',
  seller_tin      VARCHAR(20) NOT NULL DEFAULT '',
  seller_country  VARCHAR(8) NOT NULL DEFAULT '',
  currency        VARCHAR(8) NULL,                -- валюта кабинета WB: RUB, KGS, KZT…
  stats_rate      DECIMAL(10,4) NULL,             -- курс: цена Ленты (валюта кабинета) / цена Статистики (рубли)
  tax_rate        DECIMAL(5,2) NULL,              -- налог магазина, % (УСН «доходы» — 6)
  vat_rate        DECIMAL(5,2) NULL,              -- НДС магазина, % (0 — без НДС)
  weekly_expense  DECIMAL(12,2) NULL,             -- фиксированные расходы магазина в неделю (зарплата, аренда…)
  token_enc       TEXT NOT NULL,
  token_hint      VARCHAR(16) NOT NULL DEFAULT '',
  token_expires_at DATETIME NULL,
  scopes          VARCHAR(255) NOT NULL DEFAULT '{}',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  initial_done    TINYINT(1) NOT NULL DEFAULT 0,
  feed_state      TEXT NULL,
  feed_next_at    DATETIME NULL,
  feed_ok_at      DATETIME NULL,
  stats_cursor    VARCHAR(40) NULL,
  stats_next_at   DATETIME NULL,
  stats_ok_at     DATETIME NULL,
  sales_cursor    VARCHAR(40) NULL,
  sales_next_at   DATETIME NULL,
  sales_ok_at     DATETIME NULL,
  mp_next_at      DATETIME NULL,
  mp_ok_at        DATETIME NULL,
  cards_next_at   DATETIME NULL,
  remains_state   TEXT NULL,
  remains_next_at DATETIME NULL,
  remains_ok_at   DATETIME NULL,
  tariffs_next_at DATETIME NULL,
  tariffs_ok_at   DATETIME NULL,
  fin_state       TEXT NULL,
  fin_next_at     DATETIME NULL,
  fin_ok_at       DATETIME NULL,
  storage_state   TEXT NULL,
  storage_next_at DATETIME NULL,
  storage_ok_at   DATETIME NULL,
  ads_state       TEXT NULL,
  ads_next_at     DATETIME NULL,
  ads_ok_at       DATETIME NULL,
  last_error      VARCHAR(500) NULL,
  last_error_at   DATETIME NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_stores_seller_sid (seller_sid),
  KEY idx_stores_user (user_id),
  CONSTRAINT fk_stores_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id         INT UNSIGNED NOT NULL,
  srid             VARCHAR(64) NOT NULL,
  scheme           VARCHAR(8) NULL,          -- FBO | FBS | DBS | DBW | CC | MP (склад продавца, схема не уточнена)
  status           VARCHAR(20) NOT NULL DEFAULT 'created', -- created | buyout | cancel | return | returnDefective
  cancel_type      VARCHAR(16) NULL,
  nm_id            BIGINT NULL,
  chrt_id          BIGINT NULL,
  mp_order_id      BIGINT NULL,              -- ID сборочного задания (FBS/DBS/DBW/Самовывоз)
  article          VARCHAR(120) NULL,
  barcode          VARCHAR(64) NULL,
  brand            VARCHAR(120) NULL,
  subject          VARCHAR(120) NULL,
  category         VARCHAR(120) NULL,
  tech_size        VARCHAR(40) NULL,
  warehouse        VARCHAR(160) NULL,
  warehouse_region VARCHAR(120) NULL,
  is_mp            TINYINT(1) NULL,
  dest_city        VARCHAR(160) NULL,
  dest_district    VARCHAR(120) NULL,
  region           VARCHAR(160) NULL,
  seller_price     DECIMAL(12,2) NULL,
  feed_price       DECIMAL(12,2) NULL,              -- цена продавца из Ленты заказов (валюта кабинета)
  stats_price      DECIMAL(12,2) NULL,              -- priceWithDisc из Статистики (рубли)
  finished_price   DECIMAL(12,2) NULL,
  total_price      DECIMAL(12,2) NULL,
  spp              DECIMAL(5,2) NULL,
  is_b2b           TINYINT(1) NOT NULL DEFAULT 0,
  in_feed          TINYINT(1) NOT NULL DEFAULT 0,   -- 1 — заказ есть в Ленте заказов WB (так считает кабинет)
  order_created_at DATETIME NOT NULL,
  status_at        DATETIME NULL,
  first_seen_at    DATETIME NOT NULL,
  notified_status  VARCHAR(20) NULL,
  row_updated_at   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_store_srid (store_id, srid),
  KEY idx_orders_store_created (store_id, order_created_at),
  KEY idx_orders_store_updated (store_id, row_updated_at),
  KEY idx_orders_store_status_at (store_id, status_at),
  KEY idx_orders_notify (notified_status, first_seen_at),
  CONSTRAINT fk_orders_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  store_id    INT UNSIGNED NOT NULL,
  nm_id       BIGINT NOT NULL,
  vendor_code VARCHAR(120) NULL,
  title       VARCHAR(255) NULL,
  brand       VARCHAR(120) NULL,
  subject     VARCHAR(120) NULL,
  subject_id  INT NULL,
  photo       VARCHAR(500) NULL,
  volume_l    DECIMAL(10,3) NULL,             -- объём товара в упаковке, л
  volume_src  VARCHAR(16) NULL,               -- откуда объём: remains (отчёт остатков WB) | card (габариты карточки)
  dims        VARCHAR(40) NULL,               -- габариты Д×Ш×В, см
  stock_wb    INT NULL,                       -- остаток на складах WB, шт
  stock_to_client INT NULL,                   -- в пути к покупателям
  stock_from_client INT NULL,                 -- в пути возвраты на склад
  stock_at    DATETIME NULL,
  cost_price  DECIMAL(12,2) NULL,             -- себестоимость единицы, ₽
  commission_pct DECIMAL(5,2) NULL,           -- комиссия WB для товара, % (пусто — из общих настроек)
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (store_id, nm_id),
  CONSTRAINT fk_products_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  plan_code   VARCHAR(32) NOT NULL,
  kind        VARCHAR(16) NOT NULL DEFAULT 'plan', -- plan — тариф | stores — доп. магазины | transfer — передача аккаунта
  months      TINYINT UNSIGNED NOT NULL,
  extra_stores SMALLINT UNSIGNED NOT NULL DEFAULT 0, -- доп. магазинов в платеже
  ref_id      INT UNSIGNED NULL,           -- передача аккаунта: id сотрудника, которому передаётся кабинет
  amount      DECIMAL(10,2) NOT NULL,
  provider    VARCHAR(20) NOT NULL DEFAULT 'yookassa',
  provider_id VARCHAR(64) NULL,
  status      VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending | succeeded | canceled | manual
  created_at  DATETIME NOT NULL,
  paid_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_payments_user (user_id),
  KEY idx_payments_provider (provider_id),
  CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
  token_hash CHAR(64) NOT NULL,
  user_id    INT UNSIGNED NOT NULL,
  expires_at DATETIME NOT NULL,
  PRIMARY KEY (token_hash),
  KEY idx_resets_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip           VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  store_id   INT UNSIGNED NOT NULL,
  source     VARCHAR(16) NOT NULL,
  ok         TINYINT(1) NOT NULL,
  rows_count INT NOT NULL DEFAULT 0,
  message    VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sync_store (store_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  name  VARCHAR(64) NOT NULL,
  value TEXT NULL,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Комиссии WB по предметам (загружаются токеном магазина: у продавцов из разных стран свои ставки)
CREATE TABLE IF NOT EXISTS wb_commissions (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Тарифы WB для коробов по складам (логистика с учётом коэффициента склада)
CREATE TABLE IF NOT EXISTS wb_box_tariffs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Финансы: отчёты реализации WB (детализация), платное хранение, реклама, прочие расходы, самовыкупы
CREATE TABLE IF NOT EXISTS wb_reports (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wb_report_rows (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wb_storage (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wb_ad_costs (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wb_ad_nms (
  store_id  INT UNSIGNED NOT NULL,
  advert_id BIGINT NOT NULL,
  nm_id     BIGINT NOT NULL,
  manual    TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (store_id, advert_id, nm_id),
  CONSTRAINT fk_adnm_store FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_expenses (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fin_selfbuys (
  user_id    INT UNSIGNED NOT NULL,
  srid       VARCHAR(80) NOT NULL,
  extra_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  note       VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (user_id, srid),
  CONSTRAINT fk_sb_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ключи API (REST и MCP для Claude); хранится только SHA-256 ключа
CREATE TABLE IF NOT EXISTS api_keys (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Курсы ЦБ РФ (рублей за 1 единицу валюты) для пересчёта сумм в рубли
CREATE TABLE IF NOT EXISTS fx_rates (
  code  CHAR(3) NOT NULL,
  day   DATE NOT NULL,
  rate  DECIMAL(16,8) NOT NULL,
  PRIMARY KEY (code, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Нагрузка магазина на сервер: время синхронизации (мс) и число проходов cron по дням
CREATE TABLE IF NOT EXISTS store_load (
  store_id INT UNSIGNED NOT NULL,
  day      DATE NOT NULL,
  runs     INT UNSIGNED NOT NULL DEFAULT 0,
  ms       INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (store_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Временный доступ агентства к кабинету клиента (выдаёт владелец по ID агентства)
CREATE TABLE IF NOT EXISTS account_grants (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id   INT UNSIGNED NOT NULL,
  agency_id  INT UNSIGNED NOT NULL,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grant (owner_id, agency_id),
  KEY idx_grant_agency (agency_id),
  CONSTRAINT fk_grant_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_grant_agency FOREIGN KEY (agency_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
