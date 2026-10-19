-- Apply once after migrations 002 and 003. Back up the database first.
USE encontro;
ALTER TABLE reservations
 ADD COLUMN terms_version VARCHAR(40) NOT NULL DEFAULT 'legacy',
 ADD COLUMN terms_accepted_at DATETIME NULL,
 ADD COLUMN agreement_snapshot JSON NULL;
