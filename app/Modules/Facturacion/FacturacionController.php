<?php

declare(strict_types=1);

namespace App\Modules\Facturacion;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use PDO;

/**
 * Punto de entrada de la facturación CFDI 4.0.
 *
 * - GET /facturacion  → formulario de datos fiscales + listado de facturas
 * - POST /facturacion/datos → guarda los datos fiscales
 * - POST /pedidos/{uuid}/factura → genera y timbra la factura del pedido
 * - GET /facturas/{uuid}/xml → descarga el XML timbrado
 */
final class FacturacionController
{
    public function __construct(
        private readonly FacturacionService $servicio,
        private readonly PDO $pdo,
        private readonly string $directorioVistas,
    ) {
    }

    private function vista(string $plantilla, array $datos, int $estado = 200): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $vista = new View($this->directorioVistas, $nonce);
        $datos['nonce'] ??= $nonce;
        $datos['csrf'] ??= Csrf::token();

        return Response::html($vista->render($plantilla, $datos), $estado)->withNonce($nonce);
    }

    private function usuarioId(): int
    {
        return (int) Session::obtener('user_id', 0);
    }

    private function roles(): array
    {
        $roles = Session::obtener('roles', []);

        return is_array($roles) ? array_values($roles) : [];
    }

    private function nombreUsuario(int $usuarioId): string
    {
        $stmt = $this->pdo->prepare('SELECT email FROM usuarios WHERE id = ? LIMIT 1');
        $stmt->execute([$usuarioId]);

        return (string) $stmt->fetchColumn();
    }

    public function index(Request $peticion): Response
    {
        $usuarioId = $this->usuarioId();
        $datos = $this->servicio->datosFiscales($usuarioId);

        return $this->vista('facturacion/index', [
            'titulo' => 'Facturación',
            'datosFiscales' => $datos,
            'regimenes' => $this->regimenes(),
            'usos' => $this->usos(),
            'facturas' => $this->servicio->listarFacturas($usuarioId),
            'pedidosFacturables' => $this->servicio->pedidosFacturables($usuarioId),
            'esComercio' => in_array('comercio', $this->roles(), true),
            'mensaje' => $peticion->query('ok'),
            'error' => $peticion->query('error'),
        ]);
    }

    public function guardarDatos(Request $peticion): Response
    {
        $r = $this->servicio->guardarDatosFiscales($this->usuarioId(), [
            'rfc' => (string) $peticion->input('rfc'),
            'razon_social' => (string) $peticion->input('razon_social'),
            'regimen_fiscal' => (string) $peticion->input('regimen_fiscal'),
            'cp' => (string) $peticion->input('cp'),
            'uso_cfdi' => (string) $peticion->input('uso_cfdi'),
            'correo_receptor' => (string) $peticion->input('correo_receptor'),
        ]);

        if (!$r['ok']) {
            return Response::redirect('/facturacion?error=' . urlencode($r['mensaje']));
        }

        return Response::redirect('/facturacion?ok=' . urlencode($r['mensaje']));
    }

    public function facturar(Request $peticion, array $args): Response
    {
        $uuid = (string) $args['uuid'];
        $pedido = $this->pedidoPorUuid($uuid);

        if ($pedido === null) {
            return Response::redirect('/facturacion?error=' . urlencode('El pedido no existe.'));
        }

        // El actor debe ser el comercio o el mayorista del pedido.
        $usuarioId = $this->usuarioId();
        if (!in_array($usuarioId, [(int) $pedido['comercio_usuario'], (int) $pedido['mayorista_usuario']], true)) {
            return Response::redirect('/facturacion?error=' . urlencode('No tienes permiso para facturar este pedido.'));
        }

        $r = $this->servicio->facturar((int) $pedido['id']);

        if (!$r['ok']) {
            return Response::redirect('/facturacion?error=' . urlencode($r['mensaje']));
        }

        return Response::redirect('/facturacion?ok=' . urlencode('Factura ' . $r['folio'] . ' timbrada.'));
    }

    public function xml(Request $peticion, array $args): Response
    {
        $uuid = (string) $args['uuid'];
        $stmt = $this->pdo->prepare('SELECT * FROM facturas WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $factura = $stmt->fetch();

        if ($factura === false) {
            return Response::json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADA', 'message' => 'Factura no encontrada.']], 404);
        }

        $usuarioId = $this->usuarioId();
        if (!in_array($usuarioId, [(int) $factura['emisor_usuario_id'], (int) $factura['receptor_usuario_id']], true)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'No tienes permiso.']], 403);
        }

        $xml = (string) $factura['xml_texto'];
        $archivo = 'cfdi_' . $factura['folio'] . '_' . $factura['uuid_timbrado'] . '.xml';

        return Response::html($xml, 200)
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $archivo . '"');
    }

    // ------------------------------------------------------------------
    // Apoyo
    // ------------------------------------------------------------------

    private function pedidoPorUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.usuario_id AS comercio_usuario, m.usuario_id AS mayorista_usuario
             FROM pedidos p
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             WHERE p.uuid = ? LIMIT 1'
        );
        $stmt->execute([$uuid]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    private function regimenes(): array
    {
        return $this->pdo->query('SELECT clave, nombre FROM sat_regimenes_fiscales ORDER BY clave')->fetchAll() ?: [];
    }

    private function usos(): array
    {
        return $this->pdo->query('SELECT clave, nombre FROM sat_usos_cfdi ORDER BY clave')->fetchAll() ?: [];
    }
}
