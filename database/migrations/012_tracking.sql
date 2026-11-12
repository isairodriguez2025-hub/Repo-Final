-- ---------------------------------------------------------------------------
-- BLK-11: tracking en vivo
-- ---------------------------------------------------------------------------

CREATE TABLE tracking_puntos (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    envio_id     BIGINT UNSIGNED NOT NULL,
    latitud      DECIMAL(9,6) NOT NULL,
    longitud     DECIMAL(9,6) NOT NULL,
    precision_m  SMALLINT UNSIGNED NULL,
    capturado_en DATETIME NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_envio (envio_id, capturado_en),
    FOREIGN KEY (envio_id) REFERENCES envios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
