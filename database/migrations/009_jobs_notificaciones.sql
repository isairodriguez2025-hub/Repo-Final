-- ---------------------------------------------------------------------------
-- BLK-09: cola de trabajos y notificaciones
-- ---------------------------------------------------------------------------

CREATE TABLE jobs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo          VARCHAR(60) NOT NULL,
    payload       JSON NOT NULL,
    estado        ENUM('pendiente','en_proceso','completado','fallido') NOT NULL DEFAULT 'pendiente',
    intentos      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_intentos  TINYINT UNSIGNED NOT NULL DEFAULT 3,
    disponible_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    procesado_at  DATETIME NULL,
    error         TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jobs (estado, disponible_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notificaciones (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  BIGINT UNSIGNED NOT NULL,
    canal       ENUM('correo','push','bandeja') NOT NULL,
    tipo        VARCHAR(60) NOT NULL,
    asunto      VARCHAR(180) NOT NULL,
    cuerpo      TEXT NOT NULL,
    enlace      VARCHAR(255) NULL,
    estado      ENUM('pendiente','enviado','fallido','rebotado','leido') NOT NULL DEFAULT 'pendiente',
    error       TEXT NULL,
    enviado_at  DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_usuario (usuario_id, estado, created_at),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE dispositivos_push (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id   BIGINT UNSIGNED NOT NULL,
    endpoint     VARCHAR(500) NOT NULL,
    p256dh       VARCHAR(200) NOT NULL,
    auth         VARCHAR(100) NOT NULL,
    user_agent   VARCHAR(255) NULL,
    activo       TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_endpoint (endpoint(200)),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE preferencias_notificacion (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    tipo       VARCHAR(60) NOT NULL,
    correo     TINYINT(1) NOT NULL DEFAULT 1,
    push       TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_pref (usuario_id, tipo),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ejecuciones_worker (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inicio       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duracion_ms  INT UNSIGNED NOT NULL DEFAULT 0,
    tarea        VARCHAR(60) NOT NULL,
    resultado    VARCHAR(255) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tarea (tarea, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
