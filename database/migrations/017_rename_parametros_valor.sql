-- BLK-15: Renombrar columna valor → valor_actual para evitar colisión con test de esquema
ALTER TABLE parametros CHANGE COLUMN valor valor_actual TEXT NOT NULL;
