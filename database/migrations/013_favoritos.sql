-- ---------------------------------------------------------------------------
-- BLK-12: favoritos del comercio
-- ---------------------------------------------------------------------------

CREATE TABLE favoritos (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comercio_id BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    carpeta     VARCHAR(60) NULL DEFAULT 'General',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fav (comercio_id, producto_id),
    INDEX idx_carpeta (comercio_id, carpeta),
    FOREIGN KEY (comercio_id) REFERENCES comercios_perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id)  REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
