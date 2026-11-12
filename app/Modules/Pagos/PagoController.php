<?php

declare(strict_types=1);

namespace App\Modules\Pagos;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use PDO;

/**
 * Pantalla de pago simulado.
 *
 * Imita lo que hace la pasarela: muestra el monto y, al confirmar, manda un
 * webhook firmado al mismo endpoint que usaría Mercado Pago. Así se ejercita
 * el camino real (firma, antigüedad, idempotencia) sin credenciales.
 */
final class PagoController
{
    public function __construct(
        private readonly PagoService $pagos,
        private readonly SimuladoGateway $pasarela,
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

    public function mostrar(Request $peticion, array $args): Response
    {
        $referencia = (string) $args['referencia'];

        $stmt = $this->pdo->prepare(
            'SELECT p.*, c.uuid AS checkout_uuid FROM pagos p JOIN checkouts c ON c.id = p.checkout_id
             WHERE p.referencia_externa = ? LIMIT 1'
        );
        $stmt->execute([$referencia]);
        $pago = $stmt->fetch();

        if ($pago === false) {
            return $this->vista('pagos/no-encontrado', ['titulo' => 'Pago no encontrado'], 404);
        }

        return $this->vista('pagos/simulado', [
            'titulo' => 'Pago',
            'pago'   => $pago,
        ]);
    }

    /** Simula la confirmación de la pasarela: firma y dispara el webhook. */
    public function confirmar(Request $peticion, array $args): Response
    {
        $referencia = (string) $args['referencia'];

        $payload = json_encode([
            'tipo'       => 'pago.aprobado',
            'referencia' => $referencia,
            'metodo'     => 'tarjeta',
            'monto'      => (float) $peticion->input('monto', 0),
        ], JSON_UNESCAPED_UNICODE) ?: '{}';

        $timestamp = (string) time();
        $firma = $this->pasarela->firmar($payload, (int) $timestamp);
        $eventoId = 'evt_' . bin2hex(random_bytes(8));

        $resultado = $this->pagos->procesarWebhook($payload, $firma, $timestamp, $eventoId);

        if (!$resultado['ok']) {
            return $this->vista('pagos/simulado', [
                'titulo' => 'Pago',
                'pago'   => ['referencia_externa' => $referencia, 'monto' => $peticion->input('monto', 0)],
                'error'  => $resultado['mensaje'],
            ], 422);
        }

        return Response::redirect('/pedidos');
    }
}
