-- Aplicar UMA VEZ depois da migração 002; fazer backup antes.
USE encontro;
ALTER TABLE spaces
 ADD COLUMN owner_id BIGINT UNSIGNED NULL,
 ADD COLUMN neighborhood VARCHAR(120) NOT NULL DEFAULT '',
 ADD COLUMN address VARCHAR(250) NOT NULL DEFAULT '',
 ADD COLUMN description TEXT NULL, ADD COLUMN rules TEXT NULL,
 ADD COLUMN photos JSON NULL, ADD COLUMN event_types JSON NULL, ADD COLUMN amenities JSON NULL,
 ADD COLUMN check_in_time TIME NOT NULL DEFAULT '09:00:00',
 ADD COLUMN check_out_time TIME NOT NULL DEFAULT '09:00:00',
 ADD COLUMN minimum_days INT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN cleanup_minutes INT UNSIGNED NOT NULL DEFAULT 0,
 ADD COLUMN active BOOLEAN NOT NULL DEFAULT TRUE,
 ADD COLUMN is_demo BOOLEAN NOT NULL DEFAULT TRUE,
 ADD FOREIGN KEY(owner_id) REFERENCES users(id), ADD INDEX owner_spaces(owner_id);
ALTER TABLE reservations ADD COLUMN blocked_until DATETIME NULL;
UPDATE reservations SET blocked_until=check_out;
ALTER TABLE reservations MODIFY blocked_until DATETIME NOT NULL;
