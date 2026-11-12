<?php

declare(strict_types=1);

namespace App\Modules\Notificaciones;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class NotificacionController
{
    public function __construct(
        private readonly NotificacionService $servicio,
        private readonly string $directorioVistas,
    ) {
    }

    public function index(Request $peticion): Response
    {
        $usuarioId = (int) Session::obtener('user_id', 0);

        $nonce = base64_encode(random_bytes(16));
        $vista = new View($this->directorioVistas, $nonce);

        $avisos = $this->servicio->bandeja($usuarioId);
        $this->servicio->marcarLeidas($usuarioId);

        return Response::html($vista->render('notificaciones/index', [
            'titulo' => 'Avisos',
            'avisos' => $avisos,
            'nonce'  => $nonce,
            'csrf'   => Csrf::token(),
        ]), 200)->withNonce($nonce);
    }
}
