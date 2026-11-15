-- ---------------------------------------------------------------------------
-- BLK-15: Panel de administración
-- CRUD de usuarios, gestión de reclamos, parámetros versionados.
-- ---------------------------------------------------------------------------

-- Parámetros del sistema con versionado (audit trail de cambios)
CREATE TABLE parametros (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    clave           VARCHAR(100) NOT NULL,
    valor_actual    TEXT NOT NULL,
    tipo            ENUM('string','int','float','bool','json') NOT NULL DEFAULT 'string',
    descripcion     VARCHAR(255) NULL,
    version         INT UNSIGNED NOT NULL DEFAULT 1,
    cambiado_por    BIGINT UNSIGNED NULL,
    cambiado_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_parametros_clave (clave),
    CONSTRAINT fk_parametros_usuario FOREIGN KEY (cambiado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historial de cambios de parámetros (versionado)
CREATE TABLE parametros_historial (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parametro_id    BIGINT UNSIGNED NOT NULL,
    valor_anterior  TEXT NULL,
    valor_nuevo     TEXT NOT NULL,
    version         INT UNSIGNED NOT NULL,
    usuario_id      BIGINT UNSIGNED NULL,
    ip              VARCHAR(45) NULL,
    cambiado_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ph_parametro FOREIGN KEY (parametro_id) REFERENCES parametros(id) ON DELETE CASCADE,
    CONSTRAINT fk_ph_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valoraciones de reclamos (admin)
CREATE TABLE reclamo_valoraciones (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reclamo_id      BIGINT UNSIGNED NOT NULL,
    admin_id        BIGINT UNSIGNED NOT NULL,
    resolucion      ENUM('reembolso_total','reembolso_parcial','sin_reembolso') NOT NULL,
    monto           DECIMAL(12,2) NULL,
    notas           TEXT NULL,
    creado_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reclamo_valoracion (reclamo_id),
    CONSTRAINT fk_rv_reclamo FOREIGN KEY (reclamo_id) REFERENCES reclamos(id) ON DELETE CASCADE,
    CONSTRAINT fk_rv_admin FOREIGN KEY (admin_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar parámetros iniciales
INSERT INTO parametros (clave, valor_actual, tipo, descripcion) VALUES
    ('app.nombre',    'Didi2 B2B',              'string',  'Nombre de la plataforma'),
    ('app.version',   '0.14.0',                  'string',  'Versión actual'),
    ('comision.porcentaje', '5.00',              'float',   'Comisión de la plataforma (%)'),
    ('reclamo.ventana_horas', '48',              'int',     'Ventana de reclamos en horas'),
    ('reclamo.respuesta_horas', '24',            'int',     'Plazo de respuesta del mayorista'),
    ('envio.gratis_minimo',  '500.00',           'float',   'Envío gratis a partir de este monto'),
    ('envio.tarifa_base',    '0.00',             'float',   'Tarifa base de envío'),
    ('pago.limite_diario',   '50000.00',         'float',   'Límite diario de pagos por mayorista'),
    ('sistema.mantenimiento', 'false',           'bool',    'Modo mantenimiento'),
    ('sistema.contacto',     'soporte@did2.mx',  'string',  'Correo de contacto del sistema');
