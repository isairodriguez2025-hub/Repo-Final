-- ---------------------------------------------------------------------------
-- BLK-18: Post-MVP
-- Consolidación de fletes, BNPL (pagos a plazos), licitaciones.
-- ---------------------------------------------------------------------------

-- Consolidación de fletes: agrupa envíos de un mismo mayorista en un solo flete
CREATE TABLE consolidacion_fletes (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            VARCHAR(36) NOT NULL,
    mayorista_id    BIGINT UNSIGNED NOT NULL,
    pedidos_ids     JSON NOT NULL,
    flete_original  DECIMAL(12,2) NOT NULL,
    flete_consolidado DECIMAL(12,2) NOT NULL,
    ahorro          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado          ENUM('activa','completada','cancelada') NOT NULL DEFAULT 'activa',
    creado_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completado_at   DATETIME NULL,
    UNIQUE KEY uq_consolidacion_uuid (uuid),
    CONSTRAINT fk_cf_mayorista FOREIGN KEY (mayorista_id) REFERENCES mayoristas_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pagos BNPL (Buy Now Pay Later): divide el total en cuotas
CREATE TABLE bnpl_plan (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            VARCHAR(36) NOT NULL,
    pedido_id       BIGINT UNSIGNED NOT NULL,
    total           DECIMAL(12,2) NOT NULL,
    cuotas          INT UNSIGNED NOT NULL DEFAULT 3,
    cuota_monto     DECIMAL(12,2) NOT NULL,
    cuota_actual    INT UNSIGNED NOT NULL DEFAULT 1,
    estado          ENUM('pendiente','activo','completado','moroso','cancelado') NOT NULL DEFAULT 'pendiente',
    primera_cuota   DATE NOT NULL,
    ultima_cuota    DATE NOT NULL,
    creado_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bnpl_uuid (uuid),
    CONSTRAINT fk_bnpl_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de pagos BNPL
CREATE TABLE bnpl_pagos (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bnpl_plan_id    BIGINT UNSIGNED NOT NULL,
    cuota           INT UNSIGNED NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,
    pagado          BOOLEAN NOT NULL DEFAULT FALSE,
    pagado_at       DATETIME NULL,
    referencia      VARCHAR(100) NULL,
    creado_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bnplp_plan FOREIGN KEY (bnpl_plan_id) REFERENCES bnpl_plan(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Licitaciones (RFQ): el mayorista publica una licitación y los comercios cotizan
CREATE TABLE licitaciones (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            VARCHAR(36) NOT NULL,
    mayorista_id    BIGINT UNSIGNED NOT NULL,
    titulo          VARCHAR(255) NOT NULL,
    descripcion     TEXT NULL,
    monto_minimo    DECIMAL(12,2) NULL,
    monto_maximo    DECIMAL(12,2) NULL,
    estado          ENUM('abierta','cerrada','adjudicada','cancelada') NOT NULL DEFAULT 'abierta',
    vence_en        DATETIME NOT NULL,
    adjudicada_a    BIGINT UNSIGNED NULL,
    creado_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_licitacion_uuid (uuid),
    CONSTRAINT fk_lic_mayorista FOREIGN KEY (mayorista_id) REFERENCES mayoristas_perfil(id),
    CONSTRAINT fk_lic_adjudicada FOREIGN KEY (adjudicada_a) REFERENCES comercios_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cotizaciones a licitaciones
CREATE TABLE licitaciones_cotizaciones (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    licitacion_id   BIGINT UNSIGNED NOT NULL,
    comercio_id     BIGINT UNSIGNED NOT NULL,
    monto           DECIMAL(12,2) NOT NULL,
    plazo_entrega   INT UNSIGNED NULL COMMENT 'Días hábiles',
    observaciones   TEXT NULL,
    estado          ENUM('activa','ganadora','rechazada') NOT NULL DEFAULT 'activa',
    creado_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cotizacion_licitacion_comercio (licitacion_id, comercio_id),
    CONSTRAINT fk_lc_licitacion FOREIGN KEY (licitacion_id) REFERENCES licitaciones(id) ON DELETE CASCADE,
    CONSTRAINT fk_lc_comercio FOREIGN KEY (comercio_id) REFERENCES comercios_perfil(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar parámetros Post-MVP
INSERT INTO parametros (clave, valor_actual, tipo, descripcion) VALUES
    ('bnpl.cuotas_max', '6', 'int', 'Máximo de cuotas BNPL'),
    ('bnpl.interes_tasa', '0.00', 'float', 'Tasa de interés mensual para BNPL'),
    ('licitacion.ventana_horas', '72', 'int', 'Ventana de licitaciones en horas'),
    ('flete.consolidacion_min_pedidos', '3', 'int', 'Mínimo de pedidos para consolidar flete');
