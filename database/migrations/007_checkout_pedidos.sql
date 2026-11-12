-- ---------------------------------------------------------------------------
-- BLK-07: carrito, checkout y pedidos
-- ---------------------------------------------------------------------------

CREATE TABLE carritos (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        CHAR(36) NOT NULL UNIQUE,
    comercio_id BIGINT UNSIGNED NOT NULL UNIQUE,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (comercio_id) REFERENCES comercios_perfil(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE carrito_items (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    carrito_id  BIGINT UNSIGNED NOT NULL,
    producto_id BIGINT UNSIGNED NOT NULL,
    cantidad    INT UNSIGNED NOT NULL,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_item (carrito_id, producto_id),
    CONSTRAINT chk_cantidad CHECK (cantidad > 0),
    FOREIGN KEY (carrito_id)  REFERENCES carritos(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE checkouts (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL UNIQUE,
    comercio_id     BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(64) NOT NULL UNIQUE,
    total           DECIMAL(12,2) NOT NULL,
    estado          ENUM('pendiente_pago','pagado','fallido','expirado') NOT NULL DEFAULT 'pendiente_pago',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (comercio_id) REFERENCES comercios_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedidos (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                     CHAR(36) NOT NULL UNIQUE,
    checkout_id              BIGINT UNSIGNED NOT NULL,
    comercio_id              BIGINT UNSIGNED NOT NULL,
    mayorista_id             BIGINT UNSIGNED NOT NULL,
    direccion_entrega_id     BIGINT UNSIGNED NOT NULL,
    subtotal                 DECIMAL(12,2) NOT NULL,
    iva                      DECIMAL(12,2) NOT NULL,
    envio                    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    comision_plataforma      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total                    DECIMAL(12,2) NOT NULL,
    estado                   VARCHAR(30) NOT NULL DEFAULT 'borrador',
    es_programado            TINYINT(1) NOT NULL DEFAULT 0,
    fecha_entrega_programada DATETIME NULL,
    acepta_antes_de          DATETIME NULL,
    pin_entrega_hash         VARCHAR(255) NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_com (comercio_id, estado, created_at),
    INDEX idx_may (mayorista_id, estado, created_at),
    FOREIGN KEY (checkout_id)          REFERENCES checkouts(id),
    FOREIGN KEY (mayorista_id)         REFERENCES mayoristas_perfil(id),
    FOREIGN KEY (comercio_id)          REFERENCES comercios_perfil(id),
    FOREIGN KEY (direccion_entrega_id) REFERENCES direcciones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE detalle_pedido (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id       BIGINT UNSIGNED NOT NULL,
    producto_id     BIGINT UNSIGNED NOT NULL,
    nombre_producto VARCHAR(160) NOT NULL,
    cantidad        INT UNSIGNED NOT NULL,
    precio_unitario DECIMAL(12,2) NOT NULL,
    escala_aplicada VARCHAR(60) NULL,
    iva_tasa        DECIMAL(4,2) NOT NULL,
    subtotal        DECIMAL(12,2) NOT NULL,
    INDEX idx_ped (pedido_id),
    FOREIGN KEY (pedido_id)   REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedido_eventos (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id        BIGINT UNSIGNED NOT NULL,
    estado_anterior  VARCHAR(30) NULL,
    estado_nuevo     VARCHAR(30) NOT NULL,
    actor_usuario_id BIGINT UNSIGNED NULL,
    nota             VARCHAR(255) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ped (pedido_id, created_at),
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventario_reservas (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id BIGINT UNSIGNED NOT NULL,
    pedido_id   BIGINT UNSIGNED NOT NULL,
    cantidad    INT UNSIGNED NOT NULL,
    estado      ENUM('activa','consumida','liberada') NOT NULL DEFAULT 'activa',
    expira_en   DATETIME NOT NULL,
    INDEX idx_exp (expira_en, estado),
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (pedido_id)   REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedidos_programados (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid          CHAR(36) NOT NULL UNIQUE,
    comercio_id   BIGINT UNSIGNED NOT NULL,
    mayorista_id  BIGINT UNSIGNED NOT NULL,
    frecuencia    ENUM('unica','semanal','quincenal','mensual') NOT NULL,
    dia_semana    TINYINT UNSIGNED NULL,
    hora          TIME NOT NULL,
    estado        ENUM('activa','pausada','cancelada') NOT NULL DEFAULT 'activa',
    proximo_envio DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (comercio_id)  REFERENCES comercios_perfil(id),
    FOREIGN KEY (mayorista_id) REFERENCES mayoristas_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pedidos_programados_items (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    programado_id BIGINT UNSIGNED NOT NULL,
    producto_id   BIGINT UNSIGNED NOT NULL,
    cantidad      INT UNSIGNED NOT NULL,
    FOREIGN KEY (programado_id) REFERENCES pedidos_programados(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id)   REFERENCES productos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
