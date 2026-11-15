<?php

declare(strict_types=1);

namespace App\Modules\Facturacion;

use PDO;

/**
 * Facturación CFDI 4.0.
 *
 * Genera el XML del comprobante (cfdi 4.0) y lo "timbra" con un PAC de
 * práctica. El contrato es el mismo que el de un PAC real: el XML timbrado
 * lleva el complemento TimbreFiscalDigital con UUID, fecha y sellos.
 *
 * Nota: en producción se intercambia PacPracticeGateway por la integración
 * real del proveedor autorizado (misma interfaz).
 */
final class FacturacionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------
    // Datos fiscales
    // ------------------------------------------------------------------

    /** Guarda o actualiza los datos fiscales de un usuario. */
    public function guardarDatosFiscales(int $usuarioId, array $d): array
    {
        $rfc = mb_strtoupper(trim((string) ($d['rfc'] ?? '')));
        $razonSocial = trim((string) ($d['razon_social'] ?? ''));
        $regimen = mb_strtoupper(trim((string) ($d['regimen_fiscal'] ?? '')));
        $cp = preg_replace('/\D/', '', (string) ($d['cp'] ?? ''));
        $uso = mb_strtoupper(trim((string) ($d['uso_cfdi'] ?? '')));

        if (!preg_match('/^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/', $rfc)) {
            return ['ok' => false, 'codigo' => 'RFC_INVALIDO', 'mensaje' => 'El RFC no es válido.'];
        }
        if ($razonSocial === '') {
            return ['ok' => false, 'codigo' => 'RAZON_SOCIAL_REQUERIDA', 'mensaje' => 'La razón social es obligatoria.'];
        }
        if (!preg_match('/^\d{5}$/', $cp)) {
            return ['ok' => false, 'codigo' => 'CP_INVALIDO', 'mensaje' => 'El código postal debe tener 5 dígitos.'];
        }
        if (!$this->existeRegimen($regimen)) {
            return ['ok' => false, 'codigo' => 'REGIMEN_INVALIDO', 'mensaje' => 'El régimen fiscal no existe en el catálogo del SAT.'];
        }
        if (!$this->existeUso($uso)) {
            return ['ok' => false, 'codigo' => 'USO_INVALIDO', 'mensaje' => 'El uso de CFDI no existe en el catálogo del SAT.'];
        }

        // ¿Ya tiene datos fiscales? → UPDATE; si no → INSERT.
        $stmt = $this->pdo->prepare('SELECT id FROM datos_fiscales WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $existe = (bool) $stmt->fetchColumn();

        if ($existe) {
            $sql = 'UPDATE datos_fiscales
                    SET rfc = ?, razon_social = ?, regimen_fiscal = ?, cp = ?, uso_cfdi = ?, correo_receptor = ?
                    WHERE usuario_id = ?';
            $this->pdo->prepare($sql)->execute([
                $rfc, $razonSocial, $regimen, $cp, $uso, $d['correo_receptor'] ?? null, $usuarioId,
            ]);
        } else {
            $sql = 'INSERT INTO datos_fiscales (usuario_id, rfc, razon_social, regimen_fiscal, cp, uso_cfdi, correo_receptor)
                    VALUES (?, ?, ?, ?, ?, ?, ?)';
            $this->pdo->prepare($sql)->execute([
                $usuarioId, $rfc, $razonSocial, $regimen, $cp, $uso, $d['correo_receptor'] ?? null,
            ]);
        }

        return ['ok' => true, 'mensaje' => 'Datos fiscales guardados.'];
    }

    public function datosFiscales(int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM datos_fiscales WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    // ------------------------------------------------------------------
    // Generación de facturas
    // ------------------------------------------------------------------

    /**
     * Genera y timbra la factura de un pedido.
     *
     * El emisor es el mayorista (vende), el receptor el comercio (compra).
     * Solo se facturan pedidos completados y se emite una sola factura
     * por pedido.
     */
    public function facturar(int $pedidoId): array
    {
        $pedido = $this->pedidoConPerfiles($pedidoId);

        if ($pedido === null) {
            return ['ok' => false, 'codigo' => 'PEDIDO_NO_ENCONTRADO', 'mensaje' => 'El pedido no existe.'];
        }
        if ($pedido['estado'] !== 'completado') {
            return ['ok' => false, 'codigo' => 'PEDIDO_NO_COMPLETADO', 'mensaje' => 'Solo se facturan pedidos completados.'];
        }

        // Una sola factura por pedido.
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM facturas WHERE pedido_id = ?');
        $stmt->execute([$pedidoId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return ['ok' => false, 'codigo' => 'PEDIDO_YA_FACTURADO', 'mensaje' => 'Este pedido ya tiene una factura.'];
        }

        $emisor = $this->datosFiscales((int) $pedido['mayorista_usuario']);
        $receptor = $this->datosFiscales((int) $pedido['comercio_usuario']);

        if ($emisor === null) {
            return ['ok' => false, 'codigo' => 'EMISOR_SIN_DATOS', 'mensaje' => 'El mayorista no tiene datos fiscales registrados.'];
        }
        if ($receptor === null) {
            return ['ok' => false, 'codigo' => 'RECEPTOR_SIN_DATOS', 'mensaje' => 'El comercio no tiene datos fiscales registrados.'];
        }

        // Folio consecutivo por serie.
        $serie = 'A';
        $stmt = $this->pdo->prepare('SELECT COUNT(*) + 1 FROM facturas WHERE serie = ?');
        $stmt->execute([$serie]);
        $folio = (int) $stmt->fetchColumn();

        $envio = (float) $pedido['envio'];
        $subtotalFactura = (float) $pedido['subtotal'] + $envio;

        $xml = $this->generarXml([
            'serie' => $serie,
            'folio' => $folio,
            'fecha' => gmdate('Y-m-d\TH:i:s'),
            'metodo_pago' => $pedido['metodo_pago'] ?? 'PUE',
            'forma_pago' => $pedido['forma_pago'] ?? '03',
            'lugar_expedicion' => $emisor['cp'],
            'emisor' => $emisor,
            'receptor' => $receptor,
            'conceptos' => $this->conceptosDelPedido($pedidoId, $envio),
            'subtotal' => $subtotalFactura,
            'iva' => (float) $pedido['iva'],
            'total' => (float) $pedido['total'],
        ]);

        $timbrado = $this->timbrar($xml, $emisor);

        $uuid = bin2hex(random_bytes(16));
        $uuid = substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4)
            . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20, 12);
        // El UUID del comprobante (no el del timbre) lo genera la app.

        $this->pdo->prepare(
            'INSERT INTO facturas
                (uuid, pedido_id, emisor_usuario_id, receptor_usuario_id, serie, folio,
                 lugar_expedicion, metodo_pago, forma_pago,
                 rfc_emisor, razon_social_emisor, regimen_fiscal_emisor,
                 rfc_receptor, razon_social_receptor, uso_cfdi_receptor, cp_receptor,
                 subtotal, iva, total, xml_texto, uuid_timbrado, sello_cfd, sello_sat, fecha_timbrado, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $uuid, $pedidoId, $pedido['mayorista_usuario'], $pedido['comercio_usuario'],
            $serie, $folio, $emisor['cp'], $pedido['metodo_pago'] ?? 'PUE', $pedido['forma_pago'] ?? '03',
            $emisor['rfc'], $emisor['razon_social'], $emisor['regimen_fiscal'],
            $receptor['rfc'], $receptor['razon_social'], $receptor['uso_cfdi'], $receptor['cp'],
            $pedido['subtotal'] + $envio, $pedido['iva'], $pedido['total'],
            $timbrado['xml'], $timbrado['uuid'], $timbrado['sello_cfd'], $timbrado['sello_sat'],
            $timbrado['fecha'], 'timbrada',
        ]);

        return ['ok' => true, 'mensaje' => 'Factura timbrada.', 'uuid' => $uuid, 'folio' => $folio];
    }

    /** Lista las facturas en las que el usuario es emisor o receptor. */
    public function listarFacturas(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.*, p.estado AS pedido_estado,
                    (SELECT u.email FROM usuarios u WHERE u.id = f.receptor_usuario_id) AS receptor_email,
                    (SELECT u.email FROM usuarios u WHERE u.id = f.emisor_usuario_id) AS emisor_email
             FROM facturas f
             JOIN pedidos p ON p.id = f.pedido_id
             WHERE f.emisor_usuario_id = ? OR f.receptor_usuario_id = ?
             ORDER BY f.created_at DESC'
        );
        $stmt->execute([$usuarioId, $usuarioId]);

        return $stmt->fetchAll() ?: [];
    }

    public function pedidosFacturables(int $usuarioId): array
    {
        // El comercio puede facturar sus pedidos completados que aún no tienen factura.
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.uuid, p.total, p.created_at, m.nombre_comercial AS mayorista
             FROM pedidos p
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             LEFT JOIN facturas f ON f.pedido_id = p.id
             WHERE c.usuario_id = ? AND p.estado = "completado" AND f.id IS NULL
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$usuarioId]);

        return $stmt->fetchAll() ?: [];
    }

    // ------------------------------------------------------------------
    // XML CFDI 4.0
    // ------------------------------------------------------------------

    /** @return array{descripcion:string,cantidad:int,valor_unitario:float,importe:float,iva_tasa:float} */
    private function conceptosDelPedido(int $pedidoId, float $envio = 0.0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT nombre_producto, cantidad, precio_unitario, subtotal
             FROM detalle_pedido WHERE pedido_id = ?'
        );
        $stmt->execute([$pedidoId]);
        $filas = $stmt->fetchAll() ?: [];

        $conceptos = [];
        foreach ($filas as $f) {
            $conceptos[] = [
                'descripcion' => $f['nombre_producto'],
                'cantidad' => (int) $f['cantidad'],
                'valor_unitario' => (float) $f['precio_unitario'],
                'importe' => (float) $f['subtotal'],
                'iva_tasa' => 0.16,
            ];
        }

        // El flete es otro concepto del comprobante (tasa 0 o la que aplique).
        if ($envio > 0.0) {
            $conceptos[] = [
                'descripcion' => 'Flete / envío del pedido',
                'cantidad' => 1,
                'valor_unitario' => $envio,
                'importe' => $envio,
                'iva_tasa' => 0.0,
            ];
        }

        return $conceptos;
    }

    private function generarXml(array $d): string
    {
        $e = $d['emisor'];
        $r = $d['receptor'];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd"'
            . ' Version="4.0" Serie="' . $d['serie'] . '" Folio="' . $d['folio'] . '"'
            . ' Fecha="' . $d['fecha'] . '"'
            . ' FormaPago="' . $d['forma_pago'] . '"'
            . ' MetodoPago="' . $d['metodo_pago'] . '"'
            . ' TipoDeComprobante="I"'
            . ' LugarExpedicion="' . $e['cp'] . '"'
            . ' SubTotal="' . number_format($d['subtotal'], 2) . '"'
            . ' Total="' . number_format($d['total'], 2) . '"'
            . ' Moneda="MXN" Exportacion="01">' . "\n";

        $xml .= '  <cfdi:Emisor Rfc="' . $e['rfc'] . '" Nombre="' . $this->xmlEscape($e['razon_social'])
            . '" RegimenFiscal="' . $e['regimen_fiscal'] . '"/>' . "\n";

        $xml .= '  <cfdi:Receptor Rfc="' . $r['rfc'] . '" Nombre="' . $this->xmlEscape($r['razon_social'])
            . '" DomicilioFiscalReceptor="' . $r['cp'] . '"'
            . ' RegimenFiscalReceptor="' . $e['regimen_fiscal'] . '"'
            . ' UsoCFDI="' . $r['uso_cfdi'] . '"/>' . "\n";

        $xml .= '  <cfdi:Conceptos>' . "\n";
        foreach ($d['conceptos'] as $c) {
            $xml .= '    <cfdi:Concepto ClaveProdServ="01010101" Cantidad="' . $c['cantidad']
                . '" ClaveUnidad="KGM" Descripcion="' . $this->xmlEscape($c['descripcion'])
                . '" ValorUnitario="' . number_format($c['valor_unitario'], 2)
                . '" Importe="' . number_format($c['importe'], 2) . '">' . "\n";
            // El flete puede tener tasa 0: sin traslado en el concepto.
            if ((float) $c['iva_tasa'] > 0.0) {
                $xml .= '      <cfdi:Impuestos>' . "\n";
                $xml .= '        <cfdi:Traslados>' . "\n";
                $xml .= '          <cfdi:Traslado Base="' . number_format($c['importe'], 2)
                    . '" Impuesto="002" TipoFactor="Tasa" TasaOCuota="' . number_format((float) $c['iva_tasa'], 6) . '"'
                    . ' Importe="' . number_format($c['importe'] * (float) $c['iva_tasa'], 2) . '"/>' . "\n";
                $xml .= '        </cfdi:Traslados>' . "\n";
                $xml .= '      </cfdi:Impuestos>' . "\n";
            }
            $xml .= '    </cfdi:Concepto>' . "\n";
        }
        $xml .= '  </cfdi:Conceptos>' . "\n";

        $xml .= '  <cfdi:Impuestos TotalImpuestosTrasladados="' . number_format($d['iva'], 2) . '">' . "\n";
        $xml .= '    <cfdi:Traslados>' . "\n";
        $xml .= '      <cfdi:Traslado Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.160000"'
            . ' Importe="' . number_format($d['iva'], 2) . '"/>' . "\n";
        $xml .= '    </cfdi:Traslados>' . "\n";
        $xml .= '  </cfdi:Impuestos>' . "\n";

        $xml .= '</cfdi:Comprobante>';

        return $xml;
    }

    /**
     * Timbra el CFDI (PAC de práctica). Devuelve el XML con el complemento
     * TimbreFiscalDigital. El sello se calcula con SHA-256 del XML canónico
     * (simplificado) — en producción el PAC real firma con su certificado.
     */
    private function timbrar(string $xml, array $emisor): array
    {
        $uuidTimbrado = strtoupper($this->uuidV4());
        $fechaXml = gmdate('Y-m-d\TH:i:s');
        $fechaBd = gmdate('Y-m-d H:i:s');
        $selloCfd = hash('sha256', $xml);
        $selloSat = hash('sha256', $selloCfd . $uuidTimbrado);

        $complemento = '  <cfdi:Complemento>' . "\n"
            . '    <tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xsi:schemaLocation="http://www.sat.gob.mx/TimbreFiscalDigital http://www.sat.gob.mx/sitio_internet/cfd/TimbreFiscalDigital/TimbreFiscalDigitalv11.xsd"'
            . ' Version="1.1" UUID="' . $uuidTimbrado . '"'
            . ' FechaTimbrado="' . $fechaXml . '"'
            . ' RfcProvCertif="PAC001001"'
            . ' SelloCFD="' . $selloCfd . '"'
            . ' NoCertificado="00001000000000000000"'
            . ' SelloSAT="' . $selloSat . '"/>' . "\n"
            . '  </cfdi:Complemento>' . "\n";

        // Insertar el complemento antes del cierre del comprobante.
        $xmlTimbrado = str_replace('</cfdi:Comprobante>', $complemento . '</cfdi:Comprobante>', $xml);

        return [
            'xml' => $xmlTimbrado,
            'uuid' => $uuidTimbrado,
            'sello_cfd' => $selloCfd,
            'sello_sat' => $selloSat,
            'fecha' => $fechaBd,
        ];
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    private function uuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function existeRegimen(string $clave): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sat_regimenes_fiscales WHERE clave = ?');
        $stmt->execute([$clave]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function existeUso(string $clave): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sat_usos_cfdi WHERE clave = ?');
        $stmt->execute([$clave]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function pedidoConPerfiles(int $pedidoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.usuario_id AS comercio_usuario, m.usuario_id AS mayorista_usuario
             FROM pedidos p
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }
}
