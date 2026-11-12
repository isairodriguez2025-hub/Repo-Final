-- ---------------------------------------------------------------------------
-- BLK-06: uuid público del mayorista (para /mayoristas/{uuid})
-- ---------------------------------------------------------------------------

ALTER TABLE mayoristas_perfil ADD COLUMN uuid CHAR(36) NULL AFTER id;

UPDATE mayoristas_perfil SET uuid = UUID() WHERE uuid IS NULL OR uuid = '';

ALTER TABLE mayoristas_perfil
    MODIFY COLUMN uuid CHAR(36) NOT NULL,
    ADD UNIQUE KEY uq_mayorista_uuid (uuid);
