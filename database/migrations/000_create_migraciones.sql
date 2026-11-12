-- Tabla de control de migraciones. Es la primera y no depende de nada.
CREATE TABLE IF NOT EXISTS migraciones (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(200) NOT NULL UNIQUE,
    aplicada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
