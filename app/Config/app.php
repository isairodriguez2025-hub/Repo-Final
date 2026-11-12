<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\ErrorHandler;
use App\Core\HealthController;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Middleware\AuthRequired;
use App\Middleware\CsrfProtect;
use App\Middleware\RateLimit;
use App\Middleware\RoleRequired;
use App\Middleware\SecurityHeaders;

/**
 * Arranque de la aplicación: registra servicios y rutas.
 * Devuelve el contenedor para que index.php lo use.
 */

$correlationId = bin2hex(random_bytes(8));

$logger = new Logger(__DIR__ . '/../../storage/logs', $correlationId);

$errorHandler = new ErrorHandler($logger);
$errorHandler->registrar();

$router = new Router();

$seguridad = new SecurityHeaders();
$directorioRate = __DIR__ . '/../../storage/rate';

// --- Rutas públicas --------------------------------------------------------
$router->get('/health', new HealthController(), [$seguridad]);

// Página de ejemplo: demuestra el escape y el nonce de la CSP.
$router->get('/demo', static function (Request $peticion, array $args) use ($seguridad): Response {
    $nonce = base64_encode(random_bytes(16));
    $vista = new View(__DIR__ . '/../Views', $nonce);

    return Response::html($vista->render('ejemplo', [
        'titulo'  => 'Núcleo',
        'mensaje' => 'Núcleo funcionando',
        'nonce'   => $nonce,
    ]))->withNonce($nonce);
}, [$seguridad]);

// --- Rutas de prueba de los middleware -------------------------------------
$router->get('/api/privado', static fn (): Response => Response::json(['ok' => true, 'data' => 'contenido privado']),
    [$seguridad, new AuthRequired()]);

$router->get('/api/solo-admin', static fn (): Response => Response::json(['ok' => true, 'data' => 'solo admin']),
    [$seguridad, new AuthRequired(), new RoleRequired(['admin_total'])]);

$router->post('/api/accion', static fn (): Response => Response::json(['ok' => true, 'data' => 'acción ejecutada']),
    [$seguridad, new CsrfProtect()]);

$router->get('/api/limitado', static fn (): Response => Response::json(['ok' => true, 'data' => 'dentro del límite']),
    [$seguridad, new RateLimit(3, 60, $directorioRate)]);

$contenedor = new Container();
$contenedor->set(Logger::class, $logger);
$contenedor->set(ErrorHandler::class, $errorHandler);
$contenedor->set(Router::class, $router);

return $contenedor;
