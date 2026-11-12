<?php

declare(strict_types=1);

namespace App\Modules\Logistica;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Pedidos\PedidoService;
use PDO;

final class RepartidorController
{
    public function __construct(
        private readonly AsignacionService $asignacion,
        private readonly PruebaEntregaService $pruebas,
        private readonly PedidoService $pedidos,
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

    private function repartidorId(): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM repartidores_perfil WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$this->usuarioId()]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function repartidor(): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM repartidores_perfil WHERE usuario_id = ? LIMIT 1');
        $stmt->execute([$this->usuarioId()]);

        return $stmt->fetch() ?: null;
    }

    /** GET /repartidor — panel con disponibilidad, ofertas y ruta del día. */
    public function panel(Request $peticion): Response
    {
        $repartidor = $this->repartidor();

        if ($repartidor === null) {
            return Response::redirect('/perfil');
        }

        $id = (int) $repartidor['id'];

        $stmt = $this->pdo->prepare(
            "SELECT p.uuid, p.estado, p.total, e.tarifa, e.distancia_km,
                    c.nombre_negocio AS comercio, m.nombre_comercial AS mayorista,
                    d.calle, d.colonia, d.ciudad
             FROM envios e
             JOIN pedidos p ON p.id = e.pedido_id
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             JOIN direcciones d ON d.id = p.direccion_entrega_id
             WHERE e.repartidor_id = ?
               AND p.estado IN ('repartidor_asignado','en_recoleccion','en_camino','en_puerta')
             ORDER BY p.created_at"
        );
        $stmt->execute([$id]);

        return $this->vista('repartidor/panel', [
            'titulo'      => 'Mi ruta',
            'repartidor'  => $repartidor,
            'ofertas'     => $this->asignacion->ofertasDe($id),
            'ruta'        => $stmt->fetchAll() ?: [],
            'mensaje'     => $peticion->query('ok') !== null ? 'Listo.' : null,
            'error'       => $peticion->query('error'),
        ]);
    }

    /** POST /repartidor/disponibilidad */
    public function disponibilidad(Request $peticion): Response
    {
        $repartidor = $this->repartidor();

        if ($repartidor === null) {
            return Response::redirect('/perfil');
        }

        $enLinea = (bool) $peticion->input('disponible', false);

        // Solo con KYC aprobado y vehículo activo se puede salir a repartir.
        if ($enLinea && $repartidor['estado_kyc'] !== 'aprobado') {
            return Response::redirect('/repartidor?error=' . urlencode('Tu verificación no está aprobada.'));
        }

        if ($enLinea) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM vehiculos WHERE repartidor_id = ? AND activo = 1');
            $stmt->execute([(int) $repartidor['id']]);

            if ((int) $stmt->fetchColumn() === 0) {
                return Response::redirect('/repartidor?error=' . urlencode('Necesitas un vehículo activo.'));
            }
        }

        $lat = $peticion->input('lat');
        $lng = $peticion->input('lng');

        $this->pdo->prepare(
            'UPDATE repartidores_perfil
             SET disponible = ?,
                 ultima_posicion_lat = COALESCE(?, ultima_posicion_lat),
                 ultima_posicion_lng = COALESCE(?, ultima_posicion_lng),
                 ultima_posicion_at = IF(? IS NULL, ultima_posicion_at, NOW())
             WHERE id = ?'
        )->execute([
            $enLinea ? 1 : 0,
            $lat !== null ? (float) $lat : null,
            $lng !== null ? (float) $lng : null,
            $lat,
            (int) $repartidor['id'],
        ]);

        return Response::redirect('/repartidor?ok=1');
    }

    /** POST /repartidor/ofertas/{id}/aceptar */
    public function aceptar(Request $peticion, array $args): Response
    {
        $repartidorId = $this->repartidorId();

        if ($repartidorId === null) {
            return Response::redirect('/perfil');
        }

        $r = $this->asignacion->aceptar((int) $args['id'], $repartidorId);

        return Response::redirect('/repartidor' . ($r['ok'] ? '?ok=1' : '?error=' . urlencode($r['mensaje'])));
    }

    /** POST /repartidor/pedidos/{uuid}/recolectar — valida PIN del mayorista. */
    public function recolectar(Request $peticion, array $args): Response
    {
        $pedido = $this->pedidoPropio((string) $args['uuid']);

        if ($pedido === null) {
            return Response::redirect('/repartidor?error=' . urlencode('Ese pedido no es tuyo.'));
        }

        $pin = (string) $peticion->input('pin', '');

        if (!$this->pruebas->validarRecoleccion((int) $pedido['id'], $pin)) {
            return Response::redirect('/pedidos/' . $pedido['uuid'] . '?error=' . urlencode('PIN de recolección incorrecto.'));
        }

        $this->pedidos->transicionar((int) $pedido['id'], 'en_recoleccion', $this->usuarioId(), ['repartidor'], 'Recolección confirmada');

        return Response::redirect('/pedidos/' . $pedido['uuid'] . '?ok=1');
    }

    /** POST /repartidor/pedidos/{uuid}/entregar — valida PIN del comercio. */
    public function entregar(Request $peticion, array $args): Response
    {
        $pedido = $this->pedidoPropio((string) $args['uuid']);

        if ($pedido === null) {
            return Response::redirect('/repartidor?error=' . urlencode('Ese pedido no es tuyo.'));
        }

        $pin = (string) $peticion->input('pin', '');

        if (!$this->pruebas->validarEntrega((int) $pedido['id'], $pin)) {
            return Response::redirect('/pedidos/' . $pedido['uuid'] . '?error=' . urlencode('PIN de entrega incorrecto.'));
        }

        $this->pedidos->transicionar((int) $pedido['id'], 'entregado', $this->usuarioId(), ['repartidor'], 'Entrega confirmada con PIN');

        return Response::redirect('/repartidor?ok=1');
    }

    private function pedidoPropio(string $uuid): ?array
    {
        $repartidorId = $this->repartidorId();

        if ($repartidorId === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.* FROM pedidos p JOIN envios e ON e.pedido_id = p.id
             WHERE p.uuid = ? AND e.repartidor_id = ? LIMIT 1'
        );
        $stmt->execute([$uuid, $repartidorId]);

        return $stmt->fetch() ?: null;
    }
}
