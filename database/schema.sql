CREATE DATABASE IF NOT EXISTS encontro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE encontro;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firebase_uid VARCHAR(128) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL,
    name VARCHAR(120) NOT NULL DEFAULT '',
    phone VARCHAR(25) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE spaces (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    city VARCHAR(120) NOT NULL,
    neighborhood VARCHAR(120) NOT NULL DEFAULT '',
    address VARCHAR(250) NOT NULL DEFAULT '',
    type VARCHAR(40) NOT NULL,
    capacity INT UNSIGNED NOT NULL,
    description TEXT NULL,
    rules TEXT NULL,
    daily_price DECIMAL(10,2) NOT NULL,
    weekend_price DECIMAL(10,2) NULL COMMENT 'Pacote de 48h; NULL usa duas diárias',
    check_in_time TIME NOT NULL DEFAULT '09:00:00',
    check_out_time TIME NOT NULL DEFAULT '09:00:00',
    minimum_days INT UNSIGNED NOT NULL DEFAULT 1,
    cleanup_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    photos JSON NULL,
    event_types JSON NULL,
    amenities JSON NULL,
    active BOOLEAN NOT NULL DEFAULT FALSE,
    is_demo BOOLEAN NOT NULL DEFAULT TRUE,
    owner_authorized_at DATETIME NULL,
    owner_authorization_version VARCHAR(40) NULL,
    owner_authorization_snapshot TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (owner_id) REFERENCES users(id),
    INDEX owner_spaces (owner_id),
    INDEX public_spaces (active, is_demo, id)
);

CREATE TABLE reservations (
    id CHAR(32) PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    space_id BIGINT UNSIGNED NOT NULL,
    event_date DATE NOT NULL,
    check_in DATETIME NOT NULL,
    check_out DATETIME NOT NULL,
    blocked_until DATETIME NOT NULL,
    plan ENUM('daily','weekend') NOT NULL DEFAULT 'daily',
    billable_days INT UNSIGNED NOT NULL,
    contact_name VARCHAR(120) NOT NULL,
    guests INT UNSIGNED NOT NULL,
    phone VARCHAR(25) NOT NULL,
    message TEXT NOT NULL,
    terms_version VARCHAR(40) NOT NULL DEFAULT 'legacy',
    terms_accepted_at DATETIME NULL,
    agreement_snapshot JSON NULL,
    total DECIMAL(10,2) NOT NULL,
    status ENUM('creating','pending','approved','failed','cancelled','expired') NOT NULL DEFAULT 'creating',
    preference_id VARCHAR(100) NULL,
    payment_id VARCHAR(100) NULL,
    hold_expires_at DATETIME NULL,
    payment_status VARCHAR(32) NULL,
    payment_status_detail VARCHAR(100) NULL,
    payment_updated_at DATETIME NULL,
    payment_attention ENUM('none','review_required','refund_required','chargeback') NOT NULL DEFAULT 'none',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (space_id) REFERENCES spaces(id),
    INDEX availability (space_id, status, check_in, check_out),
    INDEX stale_payment_holds (status, hold_expires_at),
    CHECK (check_out > check_in)
);

CREATE TABLE reservation_payment_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reservation_id CHAR(32) NOT NULL,
    payment_id VARCHAR(100) NOT NULL UNIQUE,
    provider_status VARCHAR(32) NOT NULL,
    status_detail VARCHAR(100) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL,
    event_count INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    FOREIGN KEY (reservation_id) REFERENCES reservations(id),
    INDEX payment_events_reservation (reservation_id, last_seen_at)
);