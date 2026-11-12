<?php

declare(strict_types=1);

namespace App\Modules\Jobs;

use App\Core\Request;
use App\Core\Response;

/**
 * GET /internal/tick
 *
 * Para hosting sin cron propio: cron-job.org o GitHub Actions llaman aquí cada
 * minuto. Se autentica con un token en la cabecera Authorization, no con sesión.
 */
final class TickController
{
    public function __construct(
        private readonly Worker $worker,
        private readonly string $token,
    ) {
    }

    public function tick(Request $peticion): Response
    {
        $cabecera = (string) $peticion->header('Authorization');
        $esperado = 'Bearer ' . $this->token;

        if ($this->token === '' || !hash_equals($esperado, $cabecera)) {
            return Response::json([
                'ok'    => false,
                'error' => ['code' => 'NO_AUTORIZADO', 'message' => 'Token de worker inválido.'],
            ], 401);
        }

        return Response::json(['ok' => true, 'tareas' => $this->worker->ejecutar()]);
    }
}
