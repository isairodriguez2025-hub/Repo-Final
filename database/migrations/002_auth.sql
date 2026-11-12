-- ---------------------------------------------------------------------------
-- BLK-02: autenticación, sesiones y control de acceso
-- ---------------------------------------------------------------------------

CREATE TABLE roles (
    id     TINYINT UNSIGNED PRIMARY KEY,
    nombre VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (id, nombre) VALUES
(1,'comercio'),(2,'mayorista'),(3,'repartidor'),
(10,'admin_total'),(11,'admin_kyc'),(12,'admin_soporte'),(13,'admin_finanzas');

CREATE TABLE usuarios (
    id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                   CHAR(36) NOT NULL UNIQUE,
    email                  VARCHAR(190) NOT NULL UNIQUE,
    password_hash          VARCHAR(255) NOT NULL,
    telefono               VARCHAR(20) NOT NULL DEFAULT '',
    telefono_verificado_at DATETIME NULL,
    email_verificado_at    DATETIME NULL,
    estado                 ENUM('pendiente','activo','suspendido','baneado') NOT NULL DEFAULT 'pendiente',
    ultimo_login_at        DATETIME NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at             DATETIME NULL,
    INDEX idx_estado (estado, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE usuario_roles (
    usuario_id          BIGINT UNSIGNED NOT NULL,
    rol_id              TINYINT UNSIGNED NOT NULL,
    estado_verificacion ENUM('sin_verificar','en_revision','aprobado','rechazado') NOT NULL DEFAULT 'sin_verificar',
    PRIMARY KEY (usuario_id, rol_id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (rol_id)     REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tokens_verificacion (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo       ENUM('email','otp_telefono','recuperacion_pass','2fa') NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expira_en  DATETIME NOT NULL,
    usado_at   DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tok (token_hash, tipo, expira_en),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE intentos_login (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email_hash VARCHAR(64) NOT NULL,
    ip         VARBINARY(16) NOT NULL,
    exitoso    TINYINT(1) NOT NULL DEFAULT 0,
    creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rate (email_hash, ip, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sesiones_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    ip         VARBINARY(16) NULL,
    user_agent VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NULL,
    accion     VARCHAR(60) NOT NULL,
    entidad    VARCHAR(40) NULL,
    entidad_id VARCHAR(40) NULL,
    ip         VARBINARY(16) NULL,
    detalle    JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aud (accion, created_at),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE idempotency_keys (
    llave      VARCHAR(64) NOT NULL PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    ruta       VARCHAR(100) NOT NULL,
    respuesta  JSON NOT NULL,
    expira_en  DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_exp (expira_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
