-- El comercio necesita ver su PIN de entrega; el hash no se puede revertir,
-- así que se guarda también en claro para mostrarlo en su propio detalle.
ALTER TABLE pedidos ADD COLUMN pin_entrega_visible VARCHAR(4) NULL AFTER pin_entrega_hash;
