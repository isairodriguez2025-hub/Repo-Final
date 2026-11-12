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
use App\Config\Database;
use App\Core\AuditLogger;
use App\Modules\Auth\AuthController;
use App\Modules\Auth\AuthService;
use App\Modules\Auth\UsuarioRepository;

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

// --- Autenticación ---------------------------------------------------------
$pdo = Database::conexion();
$auditoria = new AuditLogger($pdo);
$auth = new AuthController(
    new AuthService(new UsuarioRepository($pdo), $auditoria),
    __DIR__ . '/../Views',
);

$router->get('/login', [$auth, 'loginForm'], [$seguridad]);
$router->post('/login', [$auth, 'login'], [$seguridad, new CsrfProtect(), new RateLimit(5, 900, $directorioRate)]);
$router->get('/registro', [$auth, 'registroForm'], [$seguridad]);
$router->post('/registro', [$auth, 'registro'], [$seguridad, new CsrfProtect(), new RateLimit(10, 3600, $directorioRate)]);
$router->post('/logout', [$auth, 'logout'], [$seguridad, new CsrfProtect()]);
$router->get('/verificar', [$auth, 'verificar'], [$seguridad]);
$router->get('/recuperar', [$auth, 'recuperarForm'], [$seguridad]);
$router->post('/recuperar', [$auth, 'recuperar'], [$seguridad, new CsrfProtect(), new RateLimit(3, 3600, $directorioRate)]);
$router->get('/restablecer', [$auth, 'restablecerForm'], [$seguridad]);
$router->post('/restablecer', [$auth, 'restablecer'], [$seguridad, new CsrfProtect()]);

// Panel de ejemplo protegido por rol.
$router->get('/comercio', static fn (): Response => Response::html(
    '<h1>Panel del comercio</h1><p>Solo entra quien tiene el rol comercio.</p>'
), [$seguridad, new AuthRequired(), new RoleRequired(['comercio'])]);

$contenedor = new Container();
$contenedor->set(Logger::class, $logger);
$contenedor->set(ErrorHandler::class, $errorHandler);
$contenedor->set(Router::class, $router);
$contenedor->set(AuthService::class, $auth);

return $contenedor;
