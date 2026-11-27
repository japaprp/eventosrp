-- Reservas RP: diagnóstico pré-migração somente leitura.
-- Executar depois que o MySQL estiver acessível e antes de qualquer migration.
-- Não contém INSERT, UPDATE, DELETE, ALTER, CREATE, DROP ou TRUNCATE.

SELECT DATABASE() AS selected_database, CURRENT_USER() AS authenticated_user, VERSION() AS mysql_version;

SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_ROWS AS estimated_rows,
    ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_mb,
    ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_mb
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY TABLE_NAME;

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'spaces', 'reservations', 'reservation_payment_events')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'spaces', 'reservations', 'reservation_payment_events')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT
    TABLE_NAME,
    CONSTRAINT_NAME,
    COLUMN_NAME,
    REFERENCED_TABLE_NAME,
    REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE()
  AND REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;

-- Contagens exatas, sem retornar dados pessoais.
-- Se alguma tabela ainda não existir, interrompa e compare o schema antes de continuar.
SELECT 'users' AS table_name, COUNT(*) AS exact_rows FROM users
UNION ALL
SELECT 'spaces', COUNT(*) FROM spaces
UNION ALL
SELECT 'reservations', COUNT(*) FROM reservations
UNION ALL
SELECT 'reservation_payment_events', COUNT(*) FROM reservation_payment_events;

-- Verifique a distribuição dos estados sem expor dados de clientes.
SELECT status, COUNT(*) AS reservations
FROM reservations
GROUP BY status
ORDER BY status;

SELECT is_demo, active, COUNT(*) AS spaces
FROM spaces
GROUP BY is_demo, active
ORDER BY is_demo, active;
