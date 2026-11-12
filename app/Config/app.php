<?php

declare(strict_types=1);

use App\Core\Container;
use App\Core\ErrorHandler;
use App\Core\HealthController;
use App\Core\Logger;
use App\Core\Router;

/**
 * Arranque de la aplicación: registra servicios y rutas.
 * Devuelve el contenedor para que index.php lo use.
 */

$correlationId = bin2hex(random_bytes(8));

$logger = new Logger(__DIR__ . '/../../storage/logs', $correlationId);

$errorHandler = new ErrorHandler($logger);
$errorHandler->registrar();

$router = new Router();

// --- Rutas públicas --------------------------------------------------------
$router->get('/health', new HealthController());

$contenedor = new Container();
$contenedor->set(Logger::class, $logger);
$contenedor->set(ErrorHandler::class, $errorHandler);
$contenedor->set(Router::class, $router);

return $contenedor;
