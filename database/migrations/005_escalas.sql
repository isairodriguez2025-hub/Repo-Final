-- ---------------------------------------------------------------------------
-- BLK-05: escalas de descuento por volumen
-- ---------------------------------------------------------------------------

CREATE TABLE escala_descuentos (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id     BIGINT UNSIGNED NOT NULL,
    cantidad_minima INT UNSIGNED NOT NULL,
    tipo            ENUM('porcentaje','precio_fijo') NOT NULL,
    valor           DECIMAL(12,2) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_escala (producto_id, cantidad_minima),
    CONSTRAINT chk_escala_valor CHECK (valor > 0),
    CONSTRAINT chk_escala_cant  CHECK (cantidad_minima >= 1),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
