<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Un middleware recibe la petición, decide si deja pasar y, si deja, llama a
 * $siguiente. Puede devolver una respuesta propia (401, 403, 429) sin llegar
 * al controlador.
 */
interface Middleware
{
    public function handle(Request $peticion, callable $siguiente): Response;
}
