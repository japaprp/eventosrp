CREATE DATABASE IF NOT EXISTS encontro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE encontro;
CREATE TABLE users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, firebase_uid VARCHAR(128) NOT NULL UNIQUE, email VARCHAR(254) NOT NULL, name VARCHAR(120) NOT NULL DEFAULT '', phone VARCHAR(25) NOT NULL DEFAULT '', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE spaces (owner_id BIGINT UNSIGNED NULL, neighborhood VARCHAR(120) NOT NULL DEFAULT '', address VARCHAR(250) NOT NULL DEFAULT '', description TEXT NULL, rules TEXT NULL, photos JSON NULL, event_types JSON NULL, amenities JSON NULL, check_in_time TIME NOT NULL DEFAULT '09:00:00', check_out_time TIME NOT NULL DEFAULT '09:00:00', minimum_days INT UNSIGNED NOT NULL DEFAULT 1, cleanup_minutes INT UNSIGNED NOT NULL DEFAULT 0, active BOOLEAN NOT NULL DEFAULT TRUE, is_demo BOOLEAN NOT NULL DEFAULT TRUE, FOREIGN KEY(owner_id) REFERENCES users(id), INDEX owner_spaces(owner_id), id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, capacity INT UNSIGNED NOT NULL, daily_price DECIMAL(10,2) NOT NULL, weekend_price DECIMAL(10,2) NULL COMMENT 'Pacote de 48h; NULL usa duas diárias', city VARCHAR(120) NOT NULL, type VARCHAR(40) NOT NULL);
CREATE TABLE reservations (id CHAR(32) PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,space_id BIGINT UNSIGNED NOT NULL,event_date DATE NOT NULL,check_in DATETIME NOT NULL,check_out DATETIME NOT NULL,blocked_until DATETIME NOT NULL,plan ENUM('daily','weekend') NOT NULL DEFAULT 'daily',billable_days INT UNSIGNED NOT NULL,contact_name VARCHAR(120) NOT NULL,guests INT UNSIGNED NOT NULL,phone VARCHAR(25) NOT NULL,message TEXT NOT NULL,terms_version VARCHAR(40) NOT NULL DEFAULT 'legacy',terms_accepted_at DATETIME NULL,agreement_snapshot JSON NULL,total DECIMAL(10,2) NOT NULL,status ENUM('creating','pending','approved','failed','cancelled','expired') NOT NULL DEFAULT 'creating',preference_id VARCHAR(100),payment_id VARCHAR(100),hold_expires_at DATETIME NULL,payment_status VARCHAR(32) NULL,payment_status_detail VARCHAR(100) NULL,payment_updated_at DATETIME NULL,payment_attention ENUM('none','review_required','refund_required','chargeback') NOT NULL DEFAULT 'none',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id),FOREIGN KEY(space_id) REFERENCES spaces(id),INDEX availability(space_id,status,check_in,check_out),INDEX stale_payment_holds(status,hold_expires_at),CHECK(check_out > check_in));
CREATE TABLE reservation_payment_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,reservation_id CHAR(32) NOT NULL,payment_id VARCHAR(100) NOT NULL UNIQUE,provider_status VARCHAR(32) NOT NULL,status_detail VARCHAR(100) NULL,amount DECIMAL(10,2) NOT NULL,currency VARCHAR(3) NOT NULL,event_count INT UNSIGNED NOT NULL DEFAULT 1,first_seen_at DATETIME NOT NULL,last_seen_at DATETIME NOT NULL,FOREIGN KEY(reservation_id) REFERENCES reservations(id),INDEX payment_events_reservation(reservation_id,last_seen_at));
-- OAuth/account tables are storage foundations only; no seller tokens are connected by this migration.
CREATE TABLE marketplace_seller_accounts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL UNIQUE,
 seller_user_id VARCHAR(100) NULL,
 access_token_encrypted TEXT NULL,
 refresh_token_encrypted TEXT NULL,
 token_expires_at DATETIME NULL,
 status ENUM('not_connected','connected','reauthorization_required','disconnected') NOT NULL DEFAULT 'not_connected',
 connected_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(owner_id) REFERENCES users(id),
 UNIQUE KEY marketplace_seller_user(seller_user_id)
);

CREATE TABLE marketplace_oauth_states (
 state_hash CHAR(64) PRIMARY KEY,
 owner_id BIGINT UNSIGNED NOT NULL,
 code_verifier_encrypted TEXT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(owner_id) REFERENCES users(id),
 INDEX oauth_state_expiry(expires_at,used_at)
);

-- Append-only by application convention: reversals are new rows, never edits to prior entries.
CREATE TABLE marketplace_commission_ledger (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id CHAR(32) NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 provider_payment_id VARCHAR(100) NOT NULL,
 provider_event_id VARCHAR(150) NOT NULL,
 entry_type ENUM('commission_accrual','refund_reversal','chargeback_reversal','manual_adjustment') NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'BRL',
 gross_amount DECIMAL(10,2) NOT NULL,
 provider_fee_amount DECIMAL(10,2) NULL,
 commission_rate_bps SMALLINT UNSIGNED NULL,
 commission_amount DECIMAL(10,2) NOT NULL,
 host_net_estimate DECIMAL(10,2) NULL,
 idempotency_key CHAR(64) NOT NULL UNIQUE,
 reverses_entry_id BIGINT UNSIGNED NULL,
 metadata JSON NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reservation_id) REFERENCES reservations(id),
 FOREIGN KEY(owner_id) REFERENCES users(id),
 FOREIGN KEY(reverses_entry_id) REFERENCES marketplace_commission_ledger(id),
 INDEX commission_reservation(reservation_id,created_at),
 INDEX commission_payment(provider_payment_id,created_at),
 INDEX commission_owner(owner_id,created_at)
);

CREATE TABLE space_promotions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 space_id BIGINT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL,
 status ENUM('draft','pending_payment','active','expired','cancelled','review_required') NOT NULL DEFAULT 'draft',
 duration_days SMALLINT UNSIGNED NOT NULL,
 starts_at DATETIME NULL,
 ends_at DATETIME NULL,
 daily_price DECIMAL(10,2) NOT NULL,
 total_amount DECIMAL(10,2) NOT NULL,
 currency CHAR(3) NOT NULL DEFAULT 'BRL',
 sponsored_label VARCHAR(40) NOT NULL DEFAULT 'Patrocinado',
 preference_id VARCHAR(100) NULL,
 payment_id VARCHAR(100) NULL,
 payment_status VARCHAR(32) NULL,
 idempotency_key CHAR(64) NOT NULL UNIQUE,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(space_id) REFERENCES spaces(id),
 FOREIGN KEY(owner_id) REFERENCES users(id),
 INDEX promotion_publication(status,starts_at,ends_at),
 INDEX promotion_owner(owner_id,created_at),
 UNIQUE KEY promotion_preference(preference_id),
 UNIQUE KEY promotion_payment(payment_id),
 CHECK(duration_days BETWEEN 1 AND 30),
 CHECK(total_amount > 0),
 CHECK(daily_price > 0),
 CHECK(ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
);
\nINSERT INTO spaces(id,name,capacity,daily_price,city,type) VALUES (1,'Casa Jardim',150,2800,'São Paulo','Ao ar livre'),(2,'Espaço Aurora',300,4500,'São Paulo','Salão'),(3,'Terraço Horizonte',100,3200,'São Paulo','Rooftop'),(4,'Estação Criativa',50,1200,'Campinas','Corporativo'),(5,'Recanto das Oliveiras',200,3800,'São José dos Campos','Ao ar livre'),(6,'Salão Essência',200,2500,'Campinas','Salão'),(7,'Recanto Ribeirão',150,1800,'Ribeirão Preto','Ao ar livre'),(8,'Espaço Ipê',200,2300,'Ribeirão Preto','Salão');
