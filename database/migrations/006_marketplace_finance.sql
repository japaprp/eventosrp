-- Apply once after migrations 002, 003, 004 and 005. Back up the database first.
USE encontro;

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
