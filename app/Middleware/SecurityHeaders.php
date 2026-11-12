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
        $nonce = base64_encode(random_bytes(16));

        return $siguiente($peticion)->withNonce($nonce);
    }
}
