<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Valida el token CSRF en toda petición que cambia datos.
 * Acepta el token por cabecera (AJAX) o por campo de formulario.
 */
final class CsrfProtect implements Middleware
{
    private const METODOS_SEGUROS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $peticion, callable $siguiente): Response
    {
        if (in_array($peticion->method(), self::METODOS_SEGUROS, true)) {
            return $siguiente($peticion);
        }

        Session::iniciar();

        $enviado = $peticion->header('X-CSRF-Token') ?? $peticion->input('_csrf');

        if (!Csrf::valido(is_string($enviado) ? $enviado : null)) {
            return Response::json([
                'ok'    => false,
                'error' => ['code' => 'CSRF_INVALIDO', 'message' => 'La sesión expiró. Recarga la página e inténtalo de nuevo.'],
            ], 403);
        }

        return $siguiente($peticion);
    }
}
