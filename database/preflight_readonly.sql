-- Reservas RP: diagnóstico pré-migração somente leitura.
-- Executar depois que o MySQL estiver acessível e antes de qualquer migration.
-- Não contém INSERT, UPDATE, DELETE, ALTER, CREATE, DROP ou TRUNCATE.
-- SET/PREPARE abaixo só montam consultas de leitura na sessão atual.

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

-- Contagens exatas apenas das tabelas existentes, sem retornar dados pessoais.
SELECT GROUP_CONCAT(
    CONCAT('SELECT ', QUOTE(TABLE_NAME), ' AS table_name, COUNT(*) AS exact_rows FROM `', REPLACE(TABLE_NAME, '`', '``'), '`')
    SEPARATOR ' UNION ALL '
)
INTO @reservas_rp_count_sql
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'spaces', 'reservations', 'reservation_payment_events');

SET @reservas_rp_count_sql = COALESCE(
    @reservas_rp_count_sql,
    'SELECT ''no_expected_tables'' AS table_name, 0 AS exact_rows'
);
PREPARE reservas_rp_count_stmt FROM @reservas_rp_count_sql;
EXECUTE reservas_rp_count_stmt;
DEALLOCATE PREPARE reservas_rp_count_stmt;

-- Distribuição de status, somente se a tabela/coluna existir.
SET @reservas_rp_status_sql = IF(
    EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'reservations'
          AND COLUMN_NAME = 'status'
    ),
    'SELECT status, COUNT(*) AS reservations FROM reservations GROUP BY status ORDER BY status',
    'SELECT ''reservations.status not present'' AS note'
);
PREPARE reservas_rp_status_stmt FROM @reservas_rp_status_sql;
EXECUTE reservas_rp_status_stmt;
DEALLOCATE PREPARE reservas_rp_status_stmt;

-- Distribuição de espaços, somente se ambas as colunas já existirem.
SET @reservas_rp_demo_sql = IF(
    (
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'spaces'
          AND COLUMN_NAME IN ('is_demo', 'active')
    ) = 2,
    'SELECT is_demo, active, COUNT(*) AS spaces FROM spaces GROUP BY is_demo, active ORDER BY is_demo, active',
    'SELECT ''spaces.is_demo/active not present'' AS note'
);
PREPARE reservas_rp_demo_stmt FROM @reservas_rp_demo_sql;
EXECUTE reservas_rp_demo_stmt;
DEALLOCATE PREPARE reservas_rp_demo_stmt;
