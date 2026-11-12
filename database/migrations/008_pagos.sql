-- ---------------------------------------------------------------------------
-- BLK-08: pagos, liquidación y webhooks
-- ---------------------------------------------------------------------------

CREATE TABLE pagos (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    checkout_id        BIGINT UNSIGNED NOT NULL,
    pasarela           VARCHAR(30) NOT NULL,
    referencia_externa VARCHAR(100) NOT NULL UNIQUE,
    metodo             ENUM('tarjeta','spei') NOT NULL,
    monto              DECIMAL(12,2) NOT NULL,
    estado             ENUM('creado','autorizado','capturado','fallido','reembolsado_parcial','reembolsado')
                       NOT NULL DEFAULT 'creado',
    ultimo_evento_id   VARCHAR(100) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (checkout_id) REFERENCES checkouts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pagos_dispersion (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id       BIGINT UNSIGNED NOT NULL,
    beneficiario_id BIGINT UNSIGNED NOT NULL,
    tipo            ENUM('mayorista','repartidor','plataforma') NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,
    referencia      VARCHAR(100) NULL,
    estado          ENUM('pendiente','enviado','fallido','simulado') NOT NULL DEFAULT 'pendiente',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ped (pedido_id),
    FOREIGN KEY (pedido_id)       REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (beneficiario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comisiones (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id  BIGINT UNSIGNED NOT NULL,
    tipo       ENUM('mayorista','logistica','servicio','pasarela') NOT NULL,
    base       DECIMAL(12,2) NOT NULL,
    porcentaje DECIMAL(5,2) NULL,
    monto      DECIMAL(12,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ped (pedido_id),
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reembolsos (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id          BIGINT UNSIGNED NOT NULL,
    pago_id            BIGINT UNSIGNED NOT NULL,
    monto              DECIMAL(12,2) NOT NULL,
    motivo             VARCHAR(255) NOT NULL,
    referencia_externa VARCHAR(100) NULL,
    estado             ENUM('pendiente','procesado','fallido') NOT NULL DEFAULT 'pendiente',
    creado_por         BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ped (pedido_id),
    FOREIGN KEY (pedido_id)  REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (pago_id)    REFERENCES pagos(id),
    FOREIGN KEY (creado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE finanzas_comercio (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comercio_id  BIGINT UNSIGNED NOT NULL,
    pedido_id    BIGINT UNSIGNED NOT NULL UNIQUE,
    categoria_id INT UNSIGNED NOT NULL,
    total        DECIMAL(12,2) NOT NULL,
    fecha        DATE NOT NULL,
    INDEX idx_fc (comercio_id, fecha),
    FOREIGN KEY (comercio_id) REFERENCES comercios_perfil(id),
    FOREIGN KEY (pedido_id)   REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotencia de webhooks: el mismo event_id no se procesa dos veces.
CREATE TABLE webhook_eventos (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pasarela        VARCHAR(30) NOT NULL,
    evento_id       VARCHAR(100) NOT NULL,
    tipo            VARCHAR(60) NOT NULL,
    payload         LONGTEXT NOT NULL,
    resultado       VARCHAR(60) NOT NULL,
    procesado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_evento (pasarela, evento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
