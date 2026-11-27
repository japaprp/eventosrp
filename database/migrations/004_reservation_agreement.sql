-- Aplicar uma vez após as migrações 002 e 003, sempre depois de backup verificado.
ALTER TABLE reservations
    ADD COLUMN terms_version VARCHAR(40) NOT NULL DEFAULT 'legacy',
    ADD COLUMN terms_accepted_at DATETIME NULL,
    ADD COLUMN agreement_snapshot JSON NULL;
