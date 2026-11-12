-- ---------------------------------------------------------------------------
-- BLK-10: logística, asignación de repartidor y pruebas de recolección
-- ---------------------------------------------------------------------------

CREATE TABLE tarifas_logistica (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo_vehiculo    ENUM('moto','bicicleta','auto','camioneta','pickup') NOT NULL,
    tarifa_base      DECIMAL(12,2) NOT NULL,
    precio_por_km    DECIMAL(12,2) NOT NULL,
    recargo_por_peso DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    recargo_espera   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    vigente_desde    DATE NOT NULL,
    vigente_hasta    DATE NULL,
    UNIQUE KEY uq_tar (tipo_vehiculo, vigente_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE envios (
    id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id               BIGINT UNSIGNED NOT NULL UNIQUE,
    repartidor_id           BIGINT UNSIGNED NULL,
    tarifa                  DECIMAL(12,2) NULL,
    distancia_km            DECIMAL(7,2) NULL,
    peso_total_kg           DECIMAL(10,3) NULL,
    estado                  VARCHAR(30) NOT NULL DEFAULT 'pendiente',
    codigo_recoleccion_hash VARCHAR(255) NULL,
    hora_llegada_estimada   DATETIME NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rep (repartidor_id, estado),
    FOREIGN KEY (pedido_id)     REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (repartidor_id) REFERENCES repartidores_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ofertas_envio (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    envio_id      BIGINT UNSIGNED NOT NULL,
    repartidor_id BIGINT UNSIGNED NOT NULL,
    tarifa        DECIMAL(12,2) NOT NULL,
    distancia_km  DECIMAL(7,2) NOT NULL,
    ronda         TINYINT UNSIGNED NOT NULL DEFAULT 1,
    estado        ENUM('pendiente','aceptada','rechazada','expirada') NOT NULL DEFAULT 'pendiente',
    expira_en     DATETIME NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ofe (envio_id, estado, expira_en),
    UNIQUE KEY uq_oferta (envio_id, repartidor_id),
    FOREIGN KEY (envio_id)      REFERENCES envios(id) ON DELETE CASCADE,
    FOREIGN KEY (repartidor_id) REFERENCES repartidores_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE entrega_incidentes (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id  BIGINT UNSIGNED NOT NULL,
    motivo     VARCHAR(255) NOT NULL,
    foto       VARCHAR(255) NULL,
    reportado_por BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifas iniciales por tipo de vehículo.
INSERT INTO tarifas_logistica (tipo_vehiculo, tarifa_base, precio_por_km, recargo_por_peso, recargo_espera, vigente_desde) VALUES
('bicicleta', 25.00, 4.00, 0.00, 0.50, '2026-01-01'),
('moto',      35.00, 5.00, 0.50, 1.00, '2026-01-01'),
('auto',      55.00, 7.00, 0.80, 1.50, '2026-01-01'),
('camioneta', 90.00, 9.00, 1.20, 2.00, '2026-01-01'),
('pickup',   110.00, 11.00, 1.50, 2.50, '2026-01-01');
