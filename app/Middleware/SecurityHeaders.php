<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Genera el nonce de la CSP en cada petición y lo deja en la respuesta.
 * Las cabeceras fijas (X-Frame-Options, etc.) las pone Response::send().
 */
final class SecurityHeaders implements Middleware
{
    public function handle(Request $peticion, callable $siguiente): Response
    {
        $respuesta = $siguiente($peticion);

        // Si el controlador ya generó su nonce (y lo puso en el HTML), se respeta:
        // si aquí se pisara con otro, la cabecera y el <style> no coincidirían y
        // el navegador bloquearía toda la hoja de estilos.
        if ($respuesta->nonce() !== null) {
            return $respuesta;
        }

        return $respuesta->withNonce(base64_encode(random_bytes(16)));
    }
}
