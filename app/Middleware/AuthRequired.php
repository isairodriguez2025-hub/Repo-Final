<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Exige sesión activa. Si no hay, responde 401 en JSON para las rutas de API y
 * redirige al login en las páginas normales.
 */
final class AuthRequired implements Middleware
{
    public function handle(Request $peticion, callable $siguiente): Response
    {
        Session::iniciar();

        if (Session::obtener('user_id') === null) {
            if ($peticion->isJson() || str_starts_with($peticion->path(), '/api/')) {
                return Response::json([
                    'ok'    => false,
                    'error' => ['code' => 'NO_AUTENTICADO', 'message' => 'Necesitas iniciar sesión.'],
                ], 401);
            }

            return Response::redirect('/login?next=' . urlencode($peticion->path()));
        }

        return $siguiente($peticion);
    }
}
