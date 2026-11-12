<?php

declare(strict_types=1);

namespace App\Modules\Logistica;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use PDO;

final class TrackingController
{
    public function __construct(
        private readonly TrackingService $tracking,
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

    /** POST /tracking/posicion */
    public function posicion(Request $peticion): Response
    {
        $repartidorId = $this->repartidorId();

        if ($repartidorId === null) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PROHIBIDO', 'message' => 'Solo repartidores.']], 403);
        }

        $cuerpo = file_get_contents('php://input');
        $puntos = json_decode($cuerpo === false ? '[]' : $cuerpo, true);

        if (!is_array($puntos)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'PAYLOAD_INVALIDO', 'message' => 'Se esperaba una lista de puntos.']], 422);
        }

        // Un solo punto también se acepta.
        if (isset($puntos['lat'])) {
            $puntos = [$puntos];
        }

        $resultado = $this->tracking->registrar($repartidorId, $puntos);

        if (isset($resultado['motivo'])) {
            return Response::json(['ok' => false, 'error' => ['code' => 'SIN_VIAJE', 'message' => $resultado['motivo']]], 409);
        }

        return Response::json(['ok' => true, 'data' => $resultado]);
    }

    /** GET /pedidos/{uuid}/tracking — JSON con ETag. */
    public function json(Request $peticion, array $args): Response
    {
        $resultado = $this->tracking->consultar((string) $args['uuid'], $this->usuarioId());

        if (!$resultado['ok']) {
            $estado = $resultado['codigo'] === 'NO_ENCONTRADO' ? 404 : 403;

            return Response::json(['ok' => false, 'error' => [
                'code' => $resultado['codigo'], 'message' => $resultado['mensaje'],
            ]], $estado);
        }

        $etag = (string) $resultado['etag'];
        $enviado = (string) $peticion->header('If-None-Match');

        // Si nada cambió, 304 sin cuerpo: ahorra datos en el celular.
        if ($enviado !== '' && $enviado === $etag) {
            return Response::html('', 304)->withHeader('ETag', $etag);
        }

        return Response::json(['ok' => true, 'data' => $resultado['data']])->withHeader('ETag', $etag);
    }

    /** GET /pedidos/{uuid}/seguimiento — mapa. */
    public function mapa(Request $peticion, array $args): Response
    {
        $uuid = (string) $args['uuid'];
        $resultado = $this->tracking->consultar($uuid, $this->usuarioId());

        $stmt = $this->pdo->prepare(
            'SELECT p.uuid, p.estado, m.nombre_comercial AS mayorista
             FROM pedidos p JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             WHERE p.uuid = ? LIMIT 1'
        );
        $stmt->execute([$uuid]);
        $pedido = $stmt->fetch();

        if ($pedido === false || (int) ($this->comercioDueno($uuid) ?? 0) !== $this->usuarioId()) {
            return $this->vista('tracking/no-disponible', ['titulo' => 'Seguimiento'], 404);
        }

        return $this->vista('tracking/mapa', [
            'titulo'       => 'Seguimiento',
            'pedido'       => $pedido,
            'disponible'   => $resultado['ok'],
            'motivo'       => $resultado['ok'] ? null : $resultado['mensaje'],
            'datosInicial' => $resultado['ok'] ? $resultado['data'] : null,
        ]);
    }

    private function comercioDueno(string $uuid): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.usuario_id FROM pedidos p JOIN comercios_perfil c ON c.id = p.comercio_id WHERE p.uuid = ? LIMIT 1'
        );
        $stmt->execute([$uuid]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
