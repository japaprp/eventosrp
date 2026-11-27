-- Executar somente após backup restaurável e preflight do schema real.
-- Contas existentes começam sem aprovação e precisam solicitar/receber aprovação antes de publicar.
ALTER TABLE users
    ADD COLUMN owner_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' AFTER phone,
    ADD INDEX users_owner_status (owner_status, created_at);
