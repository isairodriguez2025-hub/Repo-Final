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
use App\Modules\Kyc\KycRepository;
use App\Modules\Kyc\KycService;
use App\Modules\Perfil\PerfilController;
use App\Modules\Perfil\PerfilRepository;
use App\Modules\Catalogo\CatalogoController;
use App\Modules\Catalogo\ProductoRepository;
use App\Modules\Catalogo\ProductoService;
use App\Core\Cache;
use App\Modules\Busqueda\BusquedaController;
use App\Modules\Busqueda\BusquedaRepository;
use App\Modules\Busqueda\BusquedaService;
use App\Modules\Sistema\StaticController;
use App\Modules\Carrito\CarritoController;
use App\Modules\Carrito\CarritoRepository;
use App\Modules\Carrito\CarritoService;
use App\Modules\Pedidos\CheckoutService;
use App\Modules\Pedidos\PedidoService;
use App\Modules\Pedidos\PedidosController;
use App\Modules\Pagos\PagoController;
use App\Modules\Pagos\PagoService;
use App\Modules\Pagos\SimuladoGateway;
use App\Modules\Pagos\WebhookController;
use App\Modules\Jobs\TickController;
use App\Modules\Jobs\Worker;
use App\Modules\Notificaciones\NotificacionController;
use App\Modules\Notificaciones\NotificacionService;
use App\Modules\Logistica\AsignacionService;
use App\Modules\Logistica\PruebaEntregaService;
use App\Modules\Logistica\RepartidorController;
use App\Modules\Logistica\TarifaService;
use App\Modules\Logistica\TrackingController;
use App\Modules\Logistica\TrackingService;
use App\Modules\Favoritos\FavoritoService;
use App\Modules\Favoritos\FavoritosController;
use App\Modules\Programados\ProgramadoService;
use App\Modules\Programados\ProgramadosController;
use App\Modules\Confianza\ChatService;
use App\Modules\Confianza\ConfianzaController;
use App\Modules\Confianza\ReclamoService;
use App\Modules\Confianza\ResenaService;
use App\Modules\Finanzas\FinanzasService;

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

// La raíz no es una pantalla: manda a donde corresponde según la sesión.
$router->get('/', static function (\App\Core\Request $peticion): \App\Core\Response {
    return \App\Core\Response::redirect(
        \App\Core\Session::obtener('user_id', null) !== null ? '/perfil' : '/login'
    );
}, [$seguridad]);

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
$perfiles = new PerfilRepository($pdo);
$auth = new AuthController(
    new AuthService(new UsuarioRepository($pdo), $auditoria),
    __DIR__ . '/../Views',
    $perfiles,
);

$kyc = new KycService(
    new KycRepository($pdo),
    $auditoria,
    $pdo,
    __DIR__ . '/../../storage/kyc',
);

$perfil = new PerfilController($perfiles, $kyc, __DIR__ . '/../Views');

$productos = new ProductoRepository($pdo);
$catalogo = new CatalogoController(
    new ProductoService($productos, $auditoria, $pdo),
    $productos,
    __DIR__ . '/../Views',
);

$router->get('/login', [$auth, 'loginForm'], [$seguridad]);
$router->post('/login', [$auth, 'login'], [
    $seguridad,
    new CsrfProtect(),
    new RateLimit(5, 900, $directorioRate, 'email'),  // por cuenta
    new RateLimit(40, 900, $directorioRate),          // respaldo por IP
]);
$router->get('/registro', [$auth, 'registroForm'], [$seguridad]);
$router->post('/registro', [$auth, 'registro'], [$seguridad, new CsrfProtect(), new RateLimit(10, 3600, $directorioRate)]);
$router->post('/logout', [$auth, 'logout'], [$seguridad, new CsrfProtect()]);
$router->get('/verificar', [$auth, 'verificar'], [$seguridad]);
$router->get('/recuperar', [$auth, 'recuperarForm'], [$seguridad]);
$router->post('/recuperar', [$auth, 'recuperar'], [$seguridad, new CsrfProtect(), new RateLimit(3, 3600, $directorioRate)]);
$router->get('/restablecer', [$auth, 'restablecerForm'], [$seguridad]);
$router->post('/restablecer', [$auth, 'restablecer'], [$seguridad, new CsrfProtect()]);

// --- Perfil, direcciones y KYC ---------------------------------------------
$router->get('/perfil', [$perfil, 'perfil'], [$seguridad, new AuthRequired()]);
$router->post('/perfil/direccion', [$perfil, 'guardarDireccion'], [$seguridad, new AuthRequired(), new CsrfProtect()]);
$router->get('/kyc', [$perfil, 'kycForm'], [$seguridad, new AuthRequired()]);
$router->post('/kyc', [$perfil, 'subirKyc'], [$seguridad, new AuthRequired(), new CsrfProtect()]);

$router->get('/admin/kyc', [$perfil, 'colaKyc'], [$seguridad, new AuthRequired(), new RoleRequired(['admin_total', 'admin_kyc'])]);
$router->post('/admin/kyc/{id:\d+}/aprobar', [$perfil, 'aprobarKyc'], [$seguridad, new AuthRequired(), new RoleRequired(['admin_total', 'admin_kyc']), new CsrfProtect()]);
$router->post('/admin/kyc/{id:\d+}/rechazar', [$perfil, 'rechazarKyc'], [$seguridad, new AuthRequired(), new RoleRequired(['admin_total', 'admin_kyc']), new CsrfProtect()]);

// --- Catálogo del mayorista ------------------------------------------------
$soloMayorista = [$seguridad, new AuthRequired(), new RoleRequired(['mayorista'])];

$router->get('/mayorista/productos', [$catalogo, 'index'], $soloMayorista);
$router->get('/mayorista/productos/nuevo', [$catalogo, 'nuevoForm'], $soloMayorista);
$router->post('/mayorista/productos', [$catalogo, 'crear'], [...$soloMayorista, new CsrfProtect()]);
$router->get('/mayorista/productos/{uuid:[0-9a-fA-F-]{36}}/editar', [$catalogo, 'editarForm'], $soloMayorista);
$router->post('/mayorista/productos/{uuid:[0-9a-fA-F-]{36}}', [$catalogo, 'actualizar'], [...$soloMayorista, new CsrfProtect()]);
$router->post('/mayorista/productos/{uuid:[0-9a-fA-F-]{36}}/stock', [$catalogo, 'ajustarStock'], [...$soloMayorista, new CsrfProtect()]);
$router->post('/mayorista/productos/csv', [$catalogo, 'importarCsv'], [...$soloMayorista, new CsrfProtect()]);
$router->get('/mayorista/productos/{uuid:[0-9a-fA-F-]{36}}/escalas-preview', [$catalogo, 'escalasPreview'], $soloMayorista);

// --- Carrito y pedidos -----------------------------------------------------
$notificaciones = new NotificacionService($pdo, __DIR__ . '/../../storage/correos');
$pedidoService = new PedidoService($pdo, $notificaciones);
$carritoService = new CarritoService(new CarritoRepository($pdo), new \App\Modules\Catalogo\PrecioService());

$carrito = new CarritoController(
    $carritoService,
    new CheckoutService($pdo, $carritoService, $pedidoService, $auditoria),
    $perfiles,
    $pdo,
    __DIR__ . '/../Views',
);

$favoritoService = new FavoritoService($pdo, $carritoService);
$favoritosCtl = new FavoritosController($favoritoService, $pdo, __DIR__ . '/../Views');

$programadoService = new ProgramadoService($pdo, new \App\Modules\Catalogo\PrecioService(), $notificaciones);
$programadosCtl = new ProgramadosController($programadoService, $pdo, __DIR__ . '/../Views');

$confianza = new ConfianzaController(
    new ResenaService($pdo),
    new ChatService($pdo),
    new ReclamoService($pdo, $pedidoService),
    new FinanzasService($pdo),
    $pdo,
    __DIR__ . '/../Views',
);

$worker = new Worker($pdo, $pedidoService, $notificaciones, $programadoService);
$tick = new TickController($worker, (string) ($_ENV['WORKER_TOKEN'] ?? ''));

$configPagos = require __DIR__ . '/pagos.php';
$pasarela = new SimuladoGateway($configPagos['webhook_secret'], 300);
$pagoService = new PagoService($pdo, $pedidoService, $pasarela, $auditoria, $configPagos);

$tarifas = new TarifaService($pdo);
$asignacion = new AsignacionService($pdo, $tarifas, $pedidoService);
$pruebas = new PruebaEntregaService($pdo);

$chatService = new ChatService($pdo);
$pedidos = new PedidosController($pedidoService, $pdo, __DIR__ . '/../Views', $pagoService, $asignacion, $pruebas, $chatService);

$repartidorCtl = new RepartidorController($asignacion, $pruebas, $pedidoService, $pdo, __DIR__ . '/../Views');

$tracking = new TrackingService($pdo, new \App\Core\Cache(__DIR__ . '/../../storage/cache', 120));
$trackingCtl = new TrackingController($tracking, $pdo, __DIR__ . '/../Views');

$webhooks = new WebhookController($pagoService);
$pagosCtl = new PagoController($pagoService, $pasarela, $pdo, __DIR__ . '/../Views');

$soloComercio = [$seguridad, new AuthRequired(), new RoleRequired(['comercio'])];

$router->get('/carrito', [$carrito, 'ver'], $soloComercio);
$router->post('/carrito/agregar', [$carrito, 'agregar'], [...$soloComercio, new CsrfProtect()]);
$router->post('/carrito/quitar', [$carrito, 'quitar'], [...$soloComercio, new CsrfProtect()]);
$router->post('/carrito/vaciar', [$carrito, 'vaciar'], [...$soloComercio, new CsrfProtect()]);
$router->get('/checkout', [$carrito, 'checkoutForm'], $soloComercio);
$router->post('/checkout', [$carrito, 'checkout'], [...$soloComercio, new CsrfProtect()]);

$router->get('/pedidos', [$pedidos, 'misPedidos'], $soloComercio);
$router->get('/pedidos/{uuid:[0-9a-fA-F-]{36}}', [$pedidos, 'detalle'], [$seguridad, new AuthRequired()]);
$router->get('/pedidos/{uuid:[0-9a-fA-F-]{36}}/surtido', [$pedidos, 'surtido'], [$seguridad, new AuthRequired()]);
$router->post('/pedidos/{uuid:[0-9a-fA-F-]{36}}/pagar', [$pedidos, 'pagar'], [...$soloComercio, new CsrfProtect()]);
$router->post('/pedidos/{uuid:[0-9a-fA-F-]{36}}/{accion:aceptar|rechazar|preparando|listo|buscar-reparto|cancelar|en-camino|en-puerta}',
    [$pedidos, 'accion'], [$seguridad, new AuthRequired(), new CsrfProtect()]);

$router->get('/mayorista/pedidos', [$pedidos, 'bandeja'], [$seguridad, new AuthRequired(), new RoleRequired(['mayorista'])]);

// --- Pagos y webhooks ------------------------------------------------------
// El webhook no lleva sesión ni CSRF: se autentica con la firma HMAC.
$router->post('/pagos/webhook', [$webhooks, 'recibir'], [$seguridad]);
$router->get('/internal/tick', [$tick, 'tick'], [$seguridad]);

$avisosCtl = new NotificacionController($notificaciones, __DIR__ . '/../Views');
$router->get('/notificaciones', [$avisosCtl, 'index'], [$seguridad, new AuthRequired()]);

// --- Repartidor ------------------------------------------------------------
$soloRepartidor = [$seguridad, new AuthRequired(), new RoleRequired(['repartidor'])];

$router->get('/repartidor', [$repartidorCtl, 'panel'], $soloRepartidor);
$router->post('/repartidor/disponibilidad', [$repartidorCtl, 'disponibilidad'], [...$soloRepartidor, new CsrfProtect()]);
$router->post('/repartidor/ofertas/{id:\d+}/aceptar', [$repartidorCtl, 'aceptar'], [...$soloRepartidor, new CsrfProtect()]);
$router->post('/repartidor/pedidos/{uuid:[0-9a-fA-F-]{36}}/recolectar', [$repartidorCtl, 'recolectar'], [...$soloRepartidor, new CsrfProtect()]);
$router->post('/repartidor/pedidos/{uuid:[0-9a-fA-F-]{36}}/entregar', [$repartidorCtl, 'entregar'], [...$soloRepartidor, new CsrfProtect()]);
$router->post('/tracking/posicion', [$trackingCtl, 'posicion'], [...$soloRepartidor, new CsrfProtect()]);

// --- Seguimiento -----------------------------------------------------------
$router->get('/pedidos/{uuid:[0-9a-fA-F-]{36}}/tracking', [$trackingCtl, 'json'], [$seguridad, new AuthRequired()]);
$router->get('/pedidos/{uuid:[0-9a-fA-F-]{36}}/seguimiento', [$trackingCtl, 'mapa'], [$seguridad, new AuthRequired()]);

// --- Confianza: reseñas, chat, reclamos y finanzas ------------------------
$router->post('/pedidos/{uuid:[0-9a-fA-F-]{36}}/resena', [$confianza, 'resenar'], [$seguridad, new AuthRequired(), new CsrfProtect()]);
$router->post('/pedidos/{uuid:[0-9a-fA-F-]{36}}/mensaje', [$confianza, 'mensaje'], [$seguridad, new AuthRequired(), new CsrfProtect()]);
$router->post('/pedidos/{uuid:[0-9a-fA-F-]{36}}/reclamo', [$confianza, 'reclamar'], [$seguridad, new AuthRequired(), new CsrfProtect()]);
$router->get('/finanzas', [$confianza, 'finanzas'], [$seguridad, new AuthRequired()]);
$router->get('/finanzas/export', [$confianza, 'exportar'], [$seguridad, new AuthRequired()]);

// --- Favoritos y programados ----------------------------------------------
$router->get('/favoritos', [$favoritosCtl, 'index'], $soloComercio);
$router->post('/favoritos/agregar', [$favoritosCtl, 'agregar'], [...$soloComercio, new CsrfProtect()]);
$router->post('/favoritos/quitar', [$favoritosCtl, 'quitar'], [...$soloComercio, new CsrfProtect()]);
$router->post('/favoritos/recompra/{uuid:[0-9a-fA-F-]{36}}', [$favoritosCtl, 'recompra'], [...$soloComercio, new CsrfProtect()]);

$router->get('/programados', [$programadosCtl, 'index'], $soloComercio);
$router->post('/programados', [$programadosCtl, 'crear'], [...$soloComercio, new CsrfProtect()]);
$router->post('/programados/{id:\d+}/{estado:activa|pausada|cancelada}', [$programadosCtl, 'estado'], [...$soloComercio, new CsrfProtect()]);
$router->get('/pago/simulado/{referencia}', [$pagosCtl, 'mostrar'], [$seguridad, new AuthRequired()]);
$router->post('/pago/simulado/{referencia}', [$pagosCtl, 'confirmar'], [$seguridad, new AuthRequired(), new CsrfProtect()]);

// --- Búsqueda geográfica ---------------------------------------------------
$busqueda = new BusquedaController(
    new BusquedaService(
        new BusquedaRepository($pdo),
        new Cache(__DIR__ . '/../../storage/cache', BusquedaService::TTL_BUSQUEDA),
        $pdo,
    ),
    __DIR__ . '/../Views',
);

$estaticos = new StaticController(__DIR__ . '/../../public/assets');
$router->get('/assets/{ruta:.*}', [$estaticos, 'servir'], [$seguridad]);

$router->get('/busqueda/productos', [$busqueda, 'productos'], [$seguridad]);
$router->get('/buscar', [$busqueda, 'pagina'], [$seguridad, new AuthRequired()]);
$router->get('/mayoristas/{uuid:[0-9a-fA-F-]{36}}', [$busqueda, 'mayorista'], [$seguridad]);

// Panel de ejemplo protegido por rol.
// --- Paneles de entrada ----------------------------------------------------
// El login manda a /comercio, /mayorista, /admin o /repartidor según el rol.
// Cada uno redirige a la pantalla real de ese rol.
$router->get('/comercio', static fn (): Response => Response::redirect('/buscar'),
    [$seguridad, new AuthRequired(), new RoleRequired(['comercio'])]);

$router->get('/mayorista', static fn (): Response => Response::redirect('/mayorista/productos'),
    [$seguridad, new AuthRequired(), new RoleRequired(['mayorista'])]);

$router->get('/admin', static fn (): Response => Response::redirect('/admin/kyc'),
    [$seguridad, new AuthRequired(), new RoleRequired(['admin_total', 'admin_kyc'])]);

$contenedor = new Container();
$contenedor->set(Logger::class, $logger);
$contenedor->set(ErrorHandler::class, $errorHandler);
$contenedor->set(Router::class, $router);
$contenedor->set(AuthService::class, $auth);

return $contenedor;
