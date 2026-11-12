-- ---------------------------------------------------------------------------
-- BLK-13: reseñas, reclamos, chat y soporte
-- ---------------------------------------------------------------------------

CREATE TABLE resenas (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id       BIGINT UNSIGNED NOT NULL,
    autor_id        BIGINT UNSIGNED NOT NULL,
    destinatario_id BIGINT UNSIGNED NOT NULL,
    rol_autor       ENUM('comercio','mayorista','repartidor') NOT NULL,
    calificacion    TINYINT UNSIGNED NOT NULL,
    comentario      TEXT NULL,
    oculta          TINYINT(1) NOT NULL DEFAULT 0,
    motivo_oculta   VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_res (pedido_id, autor_id, rol_autor),
    CONSTRAINT chk_calif CHECK (calificacion BETWEEN 1 AND 5),
    FOREIGN KEY (pedido_id)       REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (autor_id)        REFERENCES usuarios(id),
    FOREIGN KEY (destinatario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reclamos (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid            CHAR(36) NOT NULL UNIQUE,
    pedido_id       BIGINT UNSIGNED NOT NULL,
    tipo            ENUM('faltante','danado','producto_incorrecto','calidad') NOT NULL,
    descripcion     TEXT NOT NULL,
    estado          ENUM('abierto','respondido','resuelto','rechazado') NOT NULL DEFAULT 'abierto',
    resolucion      ENUM('reembolso_total','reembolso_parcial','sin_reembolso') NULL,
    monto_reembolso DECIMAL(12,2) NULL,
    responsable     ENUM('mayorista','repartidor','plataforma') NULL,
    respuesta       TEXT NULL,
    resuelto_por    BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ped (pedido_id),
    FOREIGN KEY (pedido_id)    REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (resuelto_por) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reclamo_evidencias (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reclamo_id BIGINT UNSIGNED NOT NULL,
    ruta       VARCHAR(255) NOT NULL,
    tipo       ENUM('foto','otro') NOT NULL DEFAULT 'foto',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (reclamo_id) REFERENCES reclamos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mensajes (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id   BIGINT UNSIGNED NOT NULL,
    autor_id    BIGINT UNSIGNED NOT NULL,
    cuerpo      TEXT NOT NULL,
    tiene_alerta_desintermediacion TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_msg (pedido_id, created_at),
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (autor_id)  REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tickets (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid       CHAR(36) NOT NULL UNIQUE,
    usuario_id BIGINT UNSIGNED NOT NULL,
    categoria  ENUM('tecnico','pago','pedido','kyc','otro') NOT NULL,
    asunto     VARCHAR(200) NOT NULL,
    estado     ENUM('abierto','en_proceso','resuelto','cerrado') NOT NULL DEFAULT 'abierto',
    asignado_a BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (asignado_a) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ticket_mensajes (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id  BIGINT UNSIGNED NOT NULL,
    autor_id   BIGINT UNSIGNED NOT NULL,
    cuerpo     TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (autor_id)  REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE palabras_bloqueadas (
    id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    palabra VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO palabras_bloqueadas (palabra) VALUES
('estafa'), ('fraude'), ('ratero'), ('ladron'), ('mierda'), ('pendejo'), ('puto');
