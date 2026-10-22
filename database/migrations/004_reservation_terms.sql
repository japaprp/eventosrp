-- Aplicar uma vez, após confirmar backup e selecionar explicitamente o banco da aplicação.
-- Não fixa o nome do banco: ambientes diferentes usam nomes diferentes (produção: railway).
ALTER TABLE reservations
 ADD COLUMN terms_snapshot LONGTEXT NULL,
 ADD COLUMN terms_accepted_at DATETIME NULL;
