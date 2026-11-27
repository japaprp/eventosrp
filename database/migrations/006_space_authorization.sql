-- Aplicar uma vez após as migrações 002 a 005, depois de backup verificado.
ALTER TABLE spaces
    MODIFY COLUMN active BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN owner_authorized_at DATETIME NULL,
    ADD COLUMN owner_authorization_version VARCHAR(40) NULL,
    ADD COLUMN owner_authorization_snapshot TEXT NULL;