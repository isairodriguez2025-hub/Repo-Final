-- ---------------------------------------------------------------------------
-- BLK-14: Facturación CFDI 4.0
-- Datos fiscales del contribuyente + facturas emitidas/timbradas.
-- El timbrado se hace contra el PAC (aquí un PAC de práctica; en producción
-- se intercambia PacGateway por la integración real del proveedor).
-- ---------------------------------------------------------------------------

-- Parámetros del catálogo del SAT (versión mínima para el CFDI 4.0)
CREATE TABLE sat_regimenes_fiscales (
    clave  VARCHAR(4)  NOT NULL PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sat_usos_cfdi (
    clave  VARCHAR(4)   NOT NULL PRIMARY KEY,
    nombre VARCHAR(150)  NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Datos fiscales por usuario (emisor o receptor)
CREATE TABLE datos_fiscales (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    rfc             VARCHAR(13) NOT NULL,
    razon_social     VARCHAR(200) NOT NULL,
    regimen_fiscal    VARCHAR(4) NOT NULL,
    cp               VARCHAR(5) NOT NULL,
    uso_cfdi         VARCHAR(4) NOT NULL,
    correo_receptor  VARCHAR(190) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_df_usuario (usuario_id),
    UNIQUE KEY uq_df_rfc (rfc),
    CONSTRAINT chk_df_rfc CHECK (rfc REGEXP '^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$'),
    CONSTRAINT fk_df_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Facturas emitidas. Los datos del emisor/receptor se copian aquí para que la
-- factura quede inmutable aunque el perfil cambie después.
CREATE TABLE facturas (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid                CHAR(36) NOT NULL UNIQUE,
    pedido_id           BIGINT UNSIGNED NOT NULL,
    emisor_usuario_id   BIGINT UNSIGNED NOT NULL,
    receptor_usuario_id BIGINT UNSIGNED NOT NULL,
    serie               VARCHAR(10) NOT NULL DEFAULT 'A',
    folio               INT UNSIGNED NOT NULL,
    lugar_expedicion    VARCHAR(5) NOT NULL,
    metodo_pago         VARCHAR(3) NOT NULL DEFAULT 'PUE',
    forma_pago          VARCHAR(35) NOT NULL DEFAULT '03',
    rfc_emisor         VARCHAR(13) NOT NULL,
    razon_social_emisor VARCHAR(200) NOT NULL,
    regimen_fiscal_emisor VARCHAR(4) NOT NULL,
    rfc_receptor        VARCHAR(13) NOT NULL,
    razon_social_receptor VARCHAR(200) NOT NULL,
    uso_cfdi_receptor   VARCHAR(4) NOT NULL,
    cp_receptor         VARCHAR(5) NOT NULL,
    subtotal            DECIMAL(12,2) NOT NULL,
    iva                DECIMAL(12,2) NOT NULL,
    total               DECIMAL(12,2) NOT NULL,
    xml_texto           MEDIUMTEXT NULL,
    uuid_timbrado       CHAR(36) NULL,
    sello_cfd           TEXT NULL,
    sello_sat           TEXT NULL,
    fecha_timbrado      DATETIME NULL,
    estado              ENUM('generada','timbrada','cancelada') NOT NULL DEFAULT 'generada',
    cancelacion_motivo  VARCHAR(200) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fac_pedido (pedido_id),
    CONSTRAINT chk_fac_total CHECK (total = subtotal + iva),
    CONSTRAINT fk_fac_pedido FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fac_emisor FOREIGN KEY (emisor_usuario_id) REFERENCES usuarios(id),
    CONSTRAINT fk_fac_receptor FOREIGN KEY (receptor_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo SAT mínimo
INSERT INTO sat_regimenes_fiscales (clave, nombre) VALUES
    ('601', 'General de Ley Personas Morales'),
    ('603', 'Personas Morales con Fines no Lucrativos'),
    ('605', 'Sueldos y Salarios e Ingresos Asimilados a Salarios'),
    ('606', 'Arrendamiento'),
    ('608', 'Demás ingresos'),
    ('610', 'Residentes en el Extranjero'),
    ('611', 'Ingresos por Dividendos (socios y accionistas)'),
    ('612', 'Personas Físicas con Actividades Empresariales y Profesionales'),
    ('614', 'Ingresos por intereses'),
    ('615', 'Ingresos por obtención de premios'),
    ('616', 'Sin obligaciones fiscales'),
    ('621', 'Incorporación Fiscal'),
    ('625', 'Régimen de las Actividades Empresariales con Ingresos a través de Plataformas Tecnológicas'),
    ('626', 'Régimen Simplificado de Confianza');

INSERT INTO sat_usos_cfdi (clave, nombre) VALUES
    ('G01', 'Adquisición de mercancías'),
    ('G02', 'Devoluciones, descuentos o bonificaciones'),
    ('G03', 'Gastos en general'),
    ('I01', 'Construcciones'),
    ('I02', 'Mobiliario y equipo de oficina por inversiones'),
    ('I03', 'Equipo de transporte'),
    ('I04', 'Equipo de cómputo y accesorios'),
    ('I05', 'Dados, troqueles, moldes, matrices y herramiental'),
    ('I06', 'Comunicaciones telefónicas'),
    ('I07', 'Comunicaciones satelitales'),
    ('I08', 'Otra maquinaria y equipo'),
    ('D01', 'Honorarios médicos, dentales y gastos hospitalarios'),
    ('D02', 'Gastos médicos por incapacidad o discapacidad'),
    ('D03', 'Gastos funerales'),
    ('D04', 'Donativos'),
    ('D05', 'Intereses reales efectivamente pagados por créditos hipotecarios (casa habitación)'),
    ('D06', 'Aportaciones voluntarias al SAR'),
    ('D07', 'Primas por seguros de gastos médicos'),
    ('D08', 'Gastos de transportación escolar obligatoria'),
    ('D09', 'Depósitos en cuentas para el ahorro, primas que tengan como base planes de pensiones'),
    ('D10', 'Pagos por servicios educativos (colegiaturas)'),
    ('P01', 'Por definir'),
    ('S01', 'Sin efectos fiscales'),
    ('CP01', 'Pagos');
