<?php

declare(strict_types=1);

namespace App\Modules\Pagos;

use App\Core\Request;
use App\Core\Response;

/**
 * POST /pagos/webhook
 *
 * El cuerpo se lee crudo (php://input) porque la firma se calcula sobre los
 * bytes exactos que envió la pasarela: si se reconstruye el JSON, cambia.
 */
final class WebhookController
{
    public function __construct(private readonly PagoService $pagos)
    {
    }

    public function recibir(Request $peticion): Response
    {
        $payload = file_get_contents('php://input');
        $payload = $payload === false ? '' : $payload;

        $firma = (string) ($peticion->header('X-Signature') ?? '');
        $timestamp = (string) ($peticion->header('X-Timestamp') ?? '');
        $eventoId = (string) ($peticion->header('X-Event-Id') ?? '');

        $resultado = $this->pagos->procesarWebhook($payload, $firma, $timestamp, $eventoId);

        if (!$resultado['ok']) {
            return Response::json([
                'ok'    => false,
                'error' => ['code' => $resultado['codigo'], 'message' => $resultado['mensaje']],
            ], 401);
        }

        return Response::json(['ok' => true, 'resultado' => $resultado['codigo']]);
    }
}
