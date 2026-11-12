-- ---------------------------------------------------------------------------
-- BLK-03: perfiles por rol, direcciones, zonas y KYC
-- El orden importa: direcciones primero porque los perfiles la referencian.
-- ---------------------------------------------------------------------------

CREATE TABLE direcciones (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id   BIGINT UNSIGNED NOT NULL,
    alias        VARCHAR(60) NULL,
    calle        VARCHAR(190) NOT NULL,
    colonia      VARCHAR(100) NULL,
    ciudad       VARCHAR(100) NOT NULL,
    estado_mx    VARCHAR(60) NULL,
    cp           CHAR(5) NULL,
    latitud      DECIMAL(9,6) NOT NULL,
    longitud     DECIMAL(9,6) NOT NULL,
    referencias  VARCHAR(255) NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at   DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_geo (latitud, longitud),
    INDEX idx_usuario (usuario_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comercios_perfil (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id        BIGINT UNSIGNED NOT NULL UNIQUE,
    nombre_negocio    VARCHAR(150) NOT NULL,
    giro              VARCHAR(60) NOT NULL DEFAULT '',
    telefono_contacto VARCHAR(20) NULL,
    gasto_mes_actual  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mayoristas_perfil (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id        BIGINT UNSIGNED NOT NULL UNIQUE,
    nombre_comercial  VARCHAR(150) NOT NULL,
    razon_social      VARCHAR(190) NULL,
    rfc_cifrado       VARBINARY(255) NULL,
    categoria_giro    VARCHAR(60) NOT NULL DEFAULT '',
    direccion_id      BIGINT UNSIGNED NULL,
    latitud           DECIMAL(9,6) NOT NULL DEFAULT 0,
    longitud          DECIMAL(9,6) NOT NULL DEFAULT 0,
    monto_minimo      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    estado_kyc        ENUM('pendiente','en_revision','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    reputacion        DECIMAL(3,2) NULL,
    horario_apertura  TIME NULL,
    horario_cierre    TIME NULL,
    capacidad_max_dia INT UNSIGNED NULL,
    activo            TINYINT(1) NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_geo (latitud, longitud),
    INDEX idx_giro (categoria_giro, estado_kyc),
    FOREIGN KEY (usuario_id)   REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (direccion_id) REFERENCES direcciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE repartidores_perfil (
    id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id            BIGINT UNSIGNED NOT NULL UNIQUE,
    calificacion_promedio DECIMAL(3,2) NULL,
    disponible            TINYINT(1) NOT NULL DEFAULT 0,
    ultima_posicion_lat   DECIMAL(9,6) NULL,
    ultima_posicion_lng   DECIMAL(9,6) NULL,
    ultima_posicion_at    DATETIME NULL,
    estado_kyc            ENUM('pendiente','en_revision','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vehiculos (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    repartidor_id        BIGINT UNSIGNED NOT NULL,
    tipo                 ENUM('moto','bicicleta','auto','camioneta','pickup') NOT NULL,
    placa                VARCHAR(10) NOT NULL,
    capacidad_peso_kg    DECIMAL(10,3) NOT NULL,
    capacidad_volumen_m3 DECIMAL(10,4) NOT NULL,
    tiene_refrigeracion  TINYINT(1) NOT NULL DEFAULT 0,
    activo               TINYINT(1) NOT NULL DEFAULT 1,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repartidor_id) REFERENCES repartidores_perfil(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE zonas_cobertura (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre     VARCHAR(100) NOT NULL,
    activa     TINYINT(1) NOT NULL DEFAULT 1,
    poligono   JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE kyc_documentos (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id     BIGINT UNSIGNED NOT NULL,
    tipo           ENUM('identificacion','licencia','tarjeta_circulacion','antecedentes',
                        'selfie','constancia_sat','comprobante_domicilio','rfc_documento') NOT NULL,
    ruta_archivo   VARCHAR(255) NOT NULL,
    mime           VARCHAR(60) NOT NULL DEFAULT '',
    tamano_bytes   INT UNSIGNED NOT NULL DEFAULT 0,
    estado         ENUM('pendiente','aprobado','rechazado','vencido') NOT NULL DEFAULT 'pendiente',
    revisado_por   BIGINT UNSIGNED NULL,
    motivo_rechazo VARCHAR(500) NULL,
    vence_en       DATE NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cola (estado, created_at),
    FOREIGN KEY (usuario_id)   REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
