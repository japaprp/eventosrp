-- Pausa reversível: não apaga registros nem reservas. Revisar e exportar antes de qualquer exclusão.
UPDATE spaces
SET active = FALSE
WHERE is_demo = TRUE AND owner_id IS NULL;
