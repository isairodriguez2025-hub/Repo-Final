<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Exige que el usuario tenga alguno de los roles permitidos.
 *
 * Devuelve 403 genérico: no revela si el recurso existe ni qué rol hacía falta
 * (SEG-09). El detalle queda en el log.
 */
final class RoleRequired implements Middleware
{
    /** @param string[] $rolesPermitidos */
    public function __construct(private readonly array $rolesPermitidos)
    {
    }

    public function handle(Request $peticion, callable $siguiente): Response
    {
        Session::iniciar();

        $roles = Session::obtener('roles', []);
        $roles = is_array($roles) ? $roles : [$roles];

        if (array_intersect($roles, $this->rolesPermitidos) === []) {
            return Response::json([
                'ok'    => false,
                'error' => ['code' => 'SIN_PERMISO', 'message' => 'No tienes permiso para esta acción.'],
            ], 403);
        }

        return $siguiente($peticion);
    }
}
