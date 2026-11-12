<?php

declare(strict_types=1);

/**
 * Front controller: TODA petición entra por aquí.
 * No hay archivos PHP sueltos en public/ a propósito.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Request;
use App\Core\Router;
use Dotenv\Dotenv;

// Variables de entorno. safeLoad no falla si .env no existe (producción).
Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

$contenedor = require __DIR__ . '/../app/Config/app.php';

/** @var Router $router */
$router = $contenedor->get(Router::class);

$peticion  = Request::fromGlobals();
$respuesta = $router->dispatch($peticion);

$respuesta->send();
