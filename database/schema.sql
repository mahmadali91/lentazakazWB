-- Лента заказов WB — схема базы данных (MySQL 5.7+ / 8.x, MariaDB 10.3+)
-- Применяется автоматически веб-установщиком (/install) или вручную:
--   mysql -u USER -p DBNAME < database/schema.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  name            VARCHAR(120) NOT NULL DEFAULT '',
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  is_blocked      TINYINT(1) NOT NULL DEFAULT 0,
  plan_code       VARCHAR(32) NOT NULL DEFAULT 'free',
  plan_expires_at DATETIME NULL,
  tg_chat_id      BIGINT NULL,
  tg_link_code    VARCHAR(32) NULL,
  tg_events       VARCHAR(64) NOT NULL DEFAULT 'created',
  owner_id        INT UNSIGNED NULL,          -- сотрудник: id владельца кабинета
  note            VARCHAR(500) NULL,          -- заметка администратора о клиенте
  profit_settings TEXT NULL,                  -- JSON: комиссия, логистика, налог, прочие расходы
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
  mp_next_at      DATETIME NULL,
  mp_ok_at        DATETIME NULL,
  cards_next_at   DATETIME NULL,
  remains_state   TEXT NULL,
  remains_next_at DATETIME NULL,
  remains_ok_at   DATETIME NULL,
  tariffs_next_at DATETIME NULL,
  tariffs_ok_at   DATETIME NULL,
  last_error      VARCHAR(500) NULL,
  last_error_at   DATETIME NULL,
  created_at      DATETIME NOT NULL,
  PRIMARY KEY (id),
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
  finished_price   DECIMAL(12,2) NULL,
  total_price      DECIMAL(12,2) NULL,
  spp              DECIMAL(5,2) NULL,
  is_b2b           TINYINT(1) NOT NULL DEFAULT 0,
  order_created_at DATETIME NOT NULL,
  status_at        DATETIME NULL,
  first_seen_at    DATETIME NOT NULL,
  notified_status  VARCHAR(20) NULL,
  row_updated_at   TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_orders_store_srid (store_id, srid),
  KEY idx_orders_store_created (store_id, order_created_at),
  KEY idx_orders_store_updated (store_id, row_updated_at),
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
  months      TINYINT UNSIGNED NOT NULL,
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
