<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Database;
use Throwable;

/**
 * GET /health — comprueba que la aplicación está viva y que puede hablar con
 * la base. Lo usan el monitoreo y el despliegue para saber si levantar tráfico.
 */
final class HealthController
{
    public function __invoke(Request $peticion, array $args = []): Response
    {
        $db = 'ok';
        $cola = 0;

        try {
            $pdo = Database::conexion();
            $pdo->query('SELECT 1');

            // La tabla jobs llega en BLK-09; si no existe, la cola es 0.
            try {
                $cola = (int) $pdo->query("SELECT COUNT(*) FROM jobs WHERE estado = 'pendiente'")->fetchColumn();
            } catch (Throwable) {
                $cola = 0;
            }
        } catch (Throwable) {
            $db = 'error';
        }

        $libre = @disk_free_space(__DIR__ . '/../../storage');
        $disco = ($libre !== false && $libre > 50 * 1024 * 1024) ? 'ok' : 'bajo';

        $todoBien = $db === 'ok' && $disco === 'ok';

        return Response::json([
            'ok'    => $todoBien,
            'db'    => $db,
            'disk'  => $disco,
            'queue' => $cola,
            'env'   => $_ENV['APP_ENV'] ?? 'local',
        ], $todoBien ? 200 : 503);
    }
}
