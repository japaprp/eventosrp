-- Aplicar UMA VEZ depois da migração 003; fazer backup antes.
USE encontro;
ALTER TABLE reservations
 ADD COLUMN terms_snapshot LONGTEXT NULL,
 ADD COLUMN terms_accepted_at DATETIME NULL;
