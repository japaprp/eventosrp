-- Aplicar UMA VEZ no banco criado pela versão inicial. Faça backup antes.
USE encontro;
ALTER TABLE users ADD COLUMN name VARCHAR(120) NOT NULL DEFAULT '', ADD COLUMN phone VARCHAR(25) NOT NULL DEFAULT '';
ALTER TABLE spaces ADD COLUMN weekend_price DECIMAL(10,2) NULL COMMENT 'Pacote de 48h; NULL usa duas diárias';
ALTER TABLE reservations
 ADD COLUMN check_in DATETIME NULL, ADD COLUMN check_out DATETIME NULL,
 ADD COLUMN plan ENUM('daily','weekend') NOT NULL DEFAULT 'daily',
 ADD COLUMN billable_days INT UNSIGNED NOT NULL DEFAULT 1,
 ADD COLUMN contact_name VARCHAR(120) NOT NULL DEFAULT '';
-- Reservas antigas tinham apenas a data: preservar bloqueio do dia inteiro.
UPDATE reservations SET check_in=TIMESTAMP(event_date),check_out=TIMESTAMP(DATE_ADD(event_date,INTERVAL 1 DAY));
ALTER TABLE reservations MODIFY check_in DATETIME NOT NULL, MODIFY check_out DATETIME NOT NULL,
 DROP INDEX availability, ADD INDEX availability(space_id,status,check_in,check_out), ADD CHECK(check_out > check_in);
-- Inserir os exemplos somente se os IDs ainda não forem usados por outro espaço.
INSERT INTO spaces(id,name,capacity,daily_price,city,type)
 SELECT 7,'Recanto Ribeirão',150,1800,'Ribeirão Preto','Ao ar livre' WHERE NOT EXISTS (SELECT 1 FROM spaces WHERE id=7);
INSERT INTO spaces(id,name,capacity,daily_price,city,type)
 SELECT 8,'Espaço Ipê',200,2300,'Ribeirão Preto','Salão' WHERE NOT EXISTS (SELECT 1 FROM spaces WHERE id=8);
