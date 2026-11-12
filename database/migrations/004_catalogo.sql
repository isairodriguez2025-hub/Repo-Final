-- ---------------------------------------------------------------------------
-- BLK-04: catálogo e inventario
-- ---------------------------------------------------------------------------

CREATE TABLE categorias (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36) NOT NULL UNIQUE,
    padre_id    INT UNSIGNED NULL,
    nombre      VARCHAR(80) NOT NULL,
    slug        VARCHAR(80) NOT NULL UNIQUE,
    restringida TINYINT(1) NOT NULL DEFAULT 0,
    activa      TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (padre_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE productos (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid             CHAR(36) NOT NULL UNIQUE,
    mayorista_id     BIGINT UNSIGNED NOT NULL,
    categoria_id     INT UNSIGNED NOT NULL,
    nombre           VARCHAR(160) NOT NULL,
    descripcion      TEXT NULL,
    precio_base      DECIMAL(12,2) NOT NULL,
    iva_tasa         DECIMAL(4,2) NOT NULL DEFAULT 16.00,
    unidad_venta     VARCHAR(30) NOT NULL,
    contenido        VARCHAR(60) NOT NULL,
    peso_kg          DECIMAL(10,3) NOT NULL,
    volumen_m3       DECIMAL(10,4) NOT NULL,
    cantidad_minima  INT UNSIGNED NOT NULL DEFAULT 1,
    multiplo_venta   INT UNSIGNED NOT NULL DEFAULT 1,
    perecedero       TINYINT(1) NOT NULL DEFAULT 0,
    requiere_frio    TINYINT(1) NOT NULL DEFAULT 0,
    inflamable       TINYINT(1) NOT NULL DEFAULT 0,
    restringido      TINYINT(1) NOT NULL DEFAULT 0,
    stock_disponible INT UNSIGNED NOT NULL DEFAULT 0,
    stock_reservado  INT UNSIGNED NOT NULL DEFAULT 0,
    activo           TINYINT(1) NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME NULL,
    INDEX idx_mayo (mayorista_id, activo),
    INDEX idx_cat  (categoria_id, activo, stock_disponible),
    FULLTEXT ft_prod (nombre, descripcion),
    CONSTRAINT chk_precio CHECK (precio_base > 0),
    CONSTRAINT chk_peso   CHECK (peso_kg > 0),
    CONSTRAINT chk_vol    CHECK (volumen_m3 > 0),
    FOREIGN KEY (mayorista_id) REFERENCES mayoristas_perfil(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE producto_imagenes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id BIGINT UNSIGNED NOT NULL,
    ruta        VARCHAR(255) NOT NULL,
    orden       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prod (producto_id, orden),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventario_movimientos (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id     BIGINT UNSIGNED NOT NULL,
    tipo            ENUM('entrada','venta','ajuste','reserva','liberacion','csv') NOT NULL,
    cantidad        INT NOT NULL,
    cantidad_previa INT UNSIGNED NOT NULL,
    cantidad_nueva  INT UNSIGNED NOT NULL,
    usuario_id      BIGINT UNSIGNED NULL,
    pedido_id       BIGINT UNSIGNED NULL,
    motivo          VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prod (producto_id, created_at),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id)  REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Categorías base del giro (dos niveles: padre e hijas).
INSERT INTO categorias (uuid, nombre, slug, restringida) VALUES
(UUID(), 'Abarrotes', 'abarrotes', 0),
(UUID(), 'Limpieza', 'limpieza', 0),
(UUID(), 'Ferretería', 'ferreteria', 0),
(UUID(), 'Perecederos', 'perecederos', 1),
(UUID(), 'Inflamables', 'inflamables', 1);
