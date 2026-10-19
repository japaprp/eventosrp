-- Apply once after migrations 002, 003, and 004. Back up the database first.
USE encontro;
ALTER TABLE reservations
 MODIFY COLUMN status ENUM('creating','pending','approved','failed','cancelled','expired') NOT NULL DEFAULT 'creating',
 ADD COLUMN hold_expires_at DATETIME NULL,
 ADD COLUMN payment_status VARCHAR(32) NULL,
 ADD COLUMN payment_status_detail VARCHAR(100) NULL,
 ADD COLUMN payment_updated_at DATETIME NULL,
 ADD COLUMN payment_attention ENUM('none','review_required','refund_required','chargeback') NOT NULL DEFAULT 'none',
 ADD INDEX stale_payment_holds(status,hold_expires_at);
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
 FOREIGN KEY(reservation_id) REFERENCES reservations(id),
 INDEX payment_events_reservation(reservation_id,last_seen_at)
);
