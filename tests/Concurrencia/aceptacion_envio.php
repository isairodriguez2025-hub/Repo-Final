<?php

declare(strict_types=1);

/**
 * RF-R04: dos repartidores aceptan el mismo envío a la vez.
 * Debe ganar exactamente uno.
 *
 * Uso: php tests/Concurrencia/aceptacion_envio.php
 */

require __DIR__ . '/../../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

use App\Config\Database;
use App\Modules\Logistica\AsignacionService;
use App\Modules\Logistica\TarifaService;
use App\Modules\Pedidos\PedidoService;

const REPARTIDORES = 6;

$pdo = Database::nuevaConexion();
$sufijo = bin2hex(random_bytes(3));

// --- Limpieza de corridas anteriores --------------------------------------
$pdo->exec("DELETE FROM usuarios WHERE email LIKE 'conc-rep-%@didi2.mx'");

// --- Mayorista y comercio --------------------------------------------------
$pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
    ->execute(["conc-rep-may-{$sufijo}@didi2.mx"]);
$uMayo = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo)
               VALUES (UUID(), ?, 'MayoConc', 'abarrotes', 19.4326, -99.1332, 'aprobado', 1)")
    ->execute([$uMayo]);
$mayoId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
    ->execute(["conc-rep-com-{$sufijo}@didi2.mx"]);
$uCom = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioConc')")->execute([$uCom]);
$comId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 2', 'CDMX', 19.40, -99.15, 1)")
    ->execute([$uCom]);
$dirId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO productos (uuid, mayorista_id, categoria_id, nombre, precio_base, iva_tasa, unidad_venta, contenido,
                                      peso_kg, volumen_m3, cantidad_minima, multiplo_venta, stock_disponible, activo)
               VALUES (UUID(), ?, 1, 'Carga conc', 100, 16, 'caja', '1', 5, 0.01, 1, 1, 50, 1)")
    ->execute([$mayoId]);
$prodId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 116, 'pendiente_pago')")
    ->execute([$comId, "conc-rep-{$sufijo}"]);
$checkoutId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id, subtotal, iva, total, estado)
               VALUES (UUID(), ?, ?, ?, ?, 100, 16, 116, 'buscando_repartidor')")
    ->execute([$checkoutId, $comId, $mayoId, $dirId]);
$pedidoId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, iva_tasa, subtotal)
               VALUES (?, ?, 'Carga conc', 2, 100, 16, 200)")
    ->execute([$pedidoId, $prodId]);

// --- Repartidores alrededor del mayorista ---------------------------------
$repartidores = [];

for ($i = 0; $i < REPARTIDORES; $i++) {
    $pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
        ->execute(["conc-rep-{$i}-{$sufijo}@didi2.mx"]);
    $uid = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO repartidores_perfil (usuario_id, calificacion_promedio, disponible, ultima_posicion_lat, ultima_posicion_lng, ultima_posicion_at, estado_kyc)
                   VALUES (?, ?, 1, ?, ?, NOW(), 'aprobado')")
        ->execute([$uid, 4.5, 19.4326 + ($i * 0.002), -99.1332 + ($i * 0.002)]);
    $rid = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO vehiculos (repartidor_id, tipo, placa, capacidad_peso_kg, capacidad_volumen_m3, tiene_refrigeracion, activo)
                   VALUES (?, 'moto', ?, 50, 1.0, 1, 1)")
        ->execute([$rid, 'PLC' . $i . substr($sufijo, 0, 2)]);

    $repartidores[] = ['id' => $rid, 'usuario' => $uid];
}

// --- Ofertas (como las generaría la ronda 1) ------------------------------
$envio = null;

$asignacion = new AsignacionService($pdo, new TarifaService($pdo), new PedidoService($pdo));
$envio = $asignacion->prepararEnvio($pedidoId);
$envioId = (int) $envio['id'];

$ofertas = [];
$tarifa = new TarifaService($pdo);

foreach ($repartidores as $r) {
    $pdo->prepare("INSERT INTO ofertas_envio (envio_id, repartidor_id, tarifa, distancia_km, ronda, estado, expira_en)
                   VALUES (?, ?, 120.00, 1.20, 1, 'pendiente', DATE_ADD(NOW(), INTERVAL 60 SECOND))")
        ->execute([$envioId, $r['id']]);

    $ofertas[] = (int) $pdo->lastInsertId();
}

echo "Preparado: 1 envío, " . REPARTIDORES . " ofertas abiertas.\n";
echo "Todos aceptan al mismo tiempo...\n";

// --- Carrera ---------------------------------------------------------------
$pids = [];

for ($i = 0; $i < REPARTIDORES; $i++) {
    $pid = pcntl_fork();

    if ($pid === 0) {
        $pdoHijo = Database::nuevaConexion();
        $servicio = new AsignacionService($pdoHijo, new TarifaService($pdoHijo), new PedidoService($pdoHijo));

        $r = $servicio->aceptar($ofertas[$i], $repartidores[$i]['id']);

        file_put_contents(sys_get_temp_dir() . "/acept-{$i}.json", json_encode($r));

        exit(0);
    }

    $pids[] = $pid;
}

foreach ($pids as $pid) {
    pcntl_waitpid($pid, $status);
}

$pdo = Database::nuevaConexion();

$ganadores = 0;
$perdedores = 0;

for ($i = 0; $i < REPARTIDORES; $i++) {
    $datos = json_decode((string) @file_get_contents(sys_get_temp_dir() . "/acept-{$i}.json"), true) ?: ['ok' => false];
    @unlink(sys_get_temp_dir() . "/acept-{$i}.json");

    $datos['ok'] ? $ganadores++ : $perdedores++;
}

$asignado = $pdo->query("SELECT repartidor_id FROM envios WHERE id = {$envioId}")->fetchColumn();
$aceptadas = (int) $pdo->query("SELECT COUNT(*) FROM ofertas_envio WHERE envio_id = {$envioId} AND estado = 'aceptada'")->fetchColumn();

echo "\n--- Resultado ---\n";
echo "Aceptaciones exitosas: {$ganadores}\n";
echo "Rechazadas:            {$perdedores}\n";
echo "Envío asignado a:      " . ($asignado ?? 'nadie') . "\n";
echo "Ofertas aceptadas:     {$aceptadas}\n";

$correcto = $ganadores === 1 && $aceptadas === 1 && $asignado !== null;

echo "\n" . ($correcto ? '✓ CORRECTO: exactamente un repartidor ganó el envío.' : '✗ FALLO: hubo doble asignación o ninguna.') . "\n";

exit($correcto ? 0 : 1);
