<?php

declare(strict_types=1);

/**
 * Prueba de concurrencia del plan (BLK-07):
 * 50 compradores simultáneos por la ÚLTIMA pieza. Debe ganar exactamente uno.
 *
 * Uso: php tests/Concurrencia/ultima_pieza.php
 */

require __DIR__ . '/../../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

use App\Config\Database;
use App\Core\AuditLogger;
use App\Modules\Carrito\CarritoRepository;
use App\Modules\Carrito\CarritoService;
use App\Modules\Catalogo\PrecioService;
use App\Modules\Pedidos\CheckoutService;
use App\Modules\Pedidos\PedidoService;

const COMPRADORES = 50;

$pdo = Database::conexion();

// --- Preparación: un mayorista, un producto con UNA pieza, 50 comercios -----
// Limpieza en orden inverso a las llaves foráneas.
$ids = $pdo->query("SELECT id FROM usuarios WHERE email LIKE 'conc-%@didi2.mx'")->fetchAll(PDO::FETCH_COLUMN) ?: [];

if ($ids !== []) {
    $lista = implode(',', array_map('intval', $ids));

    $pdo->exec("DELETE ci FROM carrito_items ci JOIN carritos c ON c.id = ci.carrito_id
                JOIN comercios_perfil cp ON cp.id = c.comercio_id WHERE cp.usuario_id IN ({$lista})");
    $pdo->exec("DELETE c FROM carritos c JOIN comercios_perfil cp ON cp.id = c.comercio_id WHERE cp.usuario_id IN ({$lista})");
    $pdo->exec("DELETE d FROM detalle_pedido d JOIN pedidos p ON p.id = d.pedido_id
                WHERE p.comercio_id IN (SELECT id FROM comercios_perfil WHERE usuario_id IN ({$lista}))");
    $pdo->exec("DELETE e FROM pedido_eventos e JOIN pedidos p ON p.id = e.pedido_id
                WHERE p.comercio_id IN (SELECT id FROM comercios_perfil WHERE usuario_id IN ({$lista}))");
    $pdo->exec("DELETE r FROM inventario_reservas r JOIN pedidos p ON p.id = r.pedido_id
                WHERE p.comercio_id IN (SELECT id FROM comercios_perfil WHERE usuario_id IN ({$lista}))");
    $pdo->exec("DELETE p FROM pedidos p
                WHERE p.comercio_id IN (SELECT id FROM comercios_perfil WHERE usuario_id IN ({$lista}))");
    $pdo->exec("DELETE k FROM checkouts k
                WHERE k.comercio_id IN (SELECT id FROM comercios_perfil WHERE usuario_id IN ({$lista}))");
    $pdo->exec("DELETE pr FROM productos pr JOIN mayoristas_perfil m ON m.id = pr.mayorista_id
                WHERE m.usuario_id IN ({$lista})");
    $pdo->exec("DELETE m FROM mayoristas_perfil m WHERE m.usuario_id IN ({$lista})");
    $pdo->exec("DELETE d FROM direcciones d WHERE d.usuario_id IN ({$lista})");
    $pdo->exec("DELETE cp FROM comercios_perfil cp WHERE cp.usuario_id IN ({$lista})");
    $pdo->exec("DELETE ur FROM usuario_roles ur WHERE ur.usuario_id IN ({$lista})");
    $pdo->exec("DELETE u FROM usuarios u WHERE u.id IN ({$lista})");
}

$pdo->prepare(
    "INSERT INTO usuarios (uuid, email, password_hash, telefono, estado)
     VALUES (UUID(), 'conc-mayorista@didi2.mx', 'x', '5500000000', 'activo')"
)->execute();
$mayoUsuario = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud,
                                    estado_kyc, activo, monto_minimo)
     VALUES (UUID(), ?, 'Mayorista Concurrencia', 'abarrotes', 19.43, -99.13, 'aprobado', 1, 0)"
)->execute([$mayoUsuario]);
$mayoristaId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO productos (uuid, mayorista_id, categoria_id, nombre, precio_base, iva_tasa,
                            unidad_venta, contenido, peso_kg, volumen_m3, cantidad_minima,
                            multiplo_venta, stock_disponible, activo)
     VALUES (UUID(), ?, 1, 'Última pieza', 100.00, 16.00, 'caja', '1 pieza', 1, 0.001, 1, 1, 1, 1)"
)->execute([$mayoristaId]);
$productoId = (int) $pdo->lastInsertId();
$productoUuid = (string) $pdo->query("SELECT uuid FROM productos WHERE id = {$productoId}")->fetchColumn();

$comercios = [];

for ($i = 0; $i < COMPRADORES; $i++) {
    $email = "conc-{$i}@didi2.mx";
    $pdo->prepare(
        "INSERT INTO usuarios (uuid, email, password_hash, telefono, estado)
         VALUES (UUID(), ?, 'x', '5500000000', 'activo')"
    )->execute([$email]);
    $uid = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, ?)")
        ->execute([$uid, "Comercio {$i}"]);
    $cid = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal)
         VALUES (?, 'Calle 1', 'CDMX', 19.43, -99.13, 1)"
    )->execute([$uid]);
    $dirId = (int) $pdo->lastInsertId();

    $comercios[] = ['id' => $cid, 'usuario' => $uid, 'direccion' => $dirId];
}

// Cada comercio mete la pieza a su carrito antes de la carrera.
$carritoRepo = new CarritoRepository($pdo);
$precios = new PrecioService();

foreach ($comercios as $c) {
    $carrito = $carritoRepo->carritoDe($c['id']);
    $carritoRepo->agregar((int) $carrito['id'], $productoId, 1);
}

echo "Preparado: 1 producto con stock 1, " . COMPRADORES . " compradores.\n";
echo "Disparando compras simultáneas...\n";

// --- Carrera ---------------------------------------------------------------
$pids = [];
$inicio = microtime(true);

for ($i = 0; $i < COMPRADORES; $i++) {
    $pid = pcntl_fork();

    if ($pid === -1) {
        exit("No se pudo crear el proceso {$i}\n");
    }

    if ($pid === 0) {
        // Hijo: conexión propia, porque PDO no se comparte entre procesos.
        $pdoHijo = Database::nuevaConexion();
        $servicio = new CheckoutService(
            $pdoHijo,
            new CarritoService(new CarritoRepository($pdoHijo), new PrecioService()),
            new PedidoService($pdoHijo),
            new AuditLogger($pdoHijo),
        );

        $r = $servicio->crearCheckout(
            $comercios[$i]['id'],
            'conc-' . $i . '-' . bin2hex(random_bytes(4)),
            $comercios[$i]['direccion'],
            $comercios[$i]['usuario'],
        );

        file_put_contents(
            sys_get_temp_dir() . "/conc-{$i}.json",
            json_encode(['ok' => $r['ok'], 'codigo' => $r['codigo'] ?? null, 'mensaje' => $r['mensaje']]),
        );

        exit(0);
    }

    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$duracion = round((microtime(true) - $inicio) * 1000);

// El padre también necesita conexión nueva: los hijos cerraron la heredada.
$pdo = Database::nuevaConexion();

// --- Resultados ------------------------------------------------------------
$exitos = 0;
$fallos = [];
$otros = [];

for ($i = 0; $i < COMPRADORES; $i++) {
    $ruta = sys_get_temp_dir() . "/conc-{$i}.json";
    $datos = json_decode((string) @file_get_contents($ruta), true) ?: ['ok' => false, 'codigo' => 'SIN_RESPUESTA'];
    @unlink($ruta);

    if ($datos['ok'] === true) {
        $exitos++;
    } elseif (($datos['codigo'] ?? '') === 'STOCK_INSUFICIENTE') {
        $fallos[] = $datos;
    } else {
        $otros[] = $datos;
    }
}

$stock = (int) $pdo->query("SELECT stock_disponible FROM productos WHERE id = {$productoId}")->fetchColumn();
$reservado = (int) $pdo->query("SELECT stock_reservado FROM productos WHERE id = {$productoId}")->fetchColumn();
$pedidos = (int) $pdo->query("SELECT COUNT(*) FROM pedidos WHERE mayorista_id = {$mayoristaId}")->fetchColumn();

echo "\n--- Resultado ---\n";
echo "Compras exitosas:      {$exitos}\n";
echo "Rechazadas por stock:  " . count($fallos) . "\n";
echo "Otros errores:         " . count($otros) . "\n";
echo "Stock disponible:      {$stock}\n";
echo "Stock reservado:       {$reservado}\n";
echo "Pedidos creados:       {$pedidos}\n";
echo "Duración:              {$duracion} ms\n";

if ($otros !== []) {
    echo "\nOtros errores (primeros 3):\n";
    foreach (array_slice($otros, 0, 3) as $o) {
        echo '  - ' . ($o['codigo'] ?? '?') . ': ' . ($o['mensaje'] ?? '') . "\n";
    }
}

$correcto = $exitos === 1 && $stock === 0 && $pedidos === 1;

echo "\n" . ($correcto ? '✓ CORRECTO: exactamente 1 compra ganó la última pieza.' : '✗ FALLO: el resultado no es el esperado.') . "\n";

exit($correcto ? 0 : 1);
