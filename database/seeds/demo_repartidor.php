<?php

declare(strict_types=1);

/**
 * Demo completa de logística: cuenta de repartidor lista para trabajar y un
 * pedido esperando quien lo lleve.
 */

require __DIR__ . '/../../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

use App\Config\Database;
use App\Modules\Logistica\AsignacionService;
use App\Modules\Logistica\PruebaEntregaService;
use App\Modules\Logistica\TarifaService;
use App\Modules\Pedidos\PedidoService;

$pdo = Database::nuevaConexion();
$email = 'repartidor@didi2.mx';

// --- Repartidor ------------------------------------------------------------
$pdo->prepare('DELETE FROM usuarios WHERE email = ?')->execute([$email]);
$pdo->prepare(
    "INSERT INTO usuarios (uuid, email, password_hash, telefono, estado, email_verificado_at)
     VALUES (UUID(), ?, ?, '5587654321', 'activo', NOW())"
)->execute([$email, App\Core\Password::hash('repartidor123')]);
$usuarioId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO usuario_roles (usuario_id, rol_id) SELECT ?, id FROM roles WHERE nombre = 'repartidor'")
    ->execute([$usuarioId]);

// Posición a 900 m del Centro Abarrotero, en línea y con KYC aprobado.
$pdo->prepare(
    "INSERT INTO repartidores_perfil
     (usuario_id, calificacion_promedio, disponible, ultima_posicion_lat, ultima_posicion_lng, ultima_posicion_at, estado_kyc)
     VALUES (?, 4.80, 1, 19.4260, -99.1400, NOW(), 'aprobado')"
)->execute([$usuarioId]);
$repartidorId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO vehiculos (repartidor_id, tipo, placa, capacidad_peso_kg, capacidad_volumen_m3, tiene_refrigeracion, activo)
     VALUES (?, 'camioneta', 'ABC123', 400, 6.0, 1, 1)"
)->execute([$repartidorId]);

echo "Repartidor creado: {$email} / repartidor123\n";

// --- Pedido listo para recoger, esperando repartidor -----------------------
$comercio = $pdo->query("SELECT id, usuario_id FROM comercios_perfil WHERE nombre_negocio = 'Abarrotes La Esquina'")->fetch();
$mayorista = $pdo->query("SELECT id, usuario_id FROM mayoristas_perfil WHERE nombre_comercial = 'Centro Abarrotero'")->fetch();

if ($comercio === false || $mayorista === false) {
    exit("Faltan el comercio o el mayorista de demostración.\n");
}

$direccion = $pdo->query("SELECT id FROM direcciones WHERE usuario_id = " . (int) $comercio['usuario_id'] . " LIMIT 1")->fetchColumn();
$producto = $pdo->query("SELECT id, nombre, precio_base FROM productos WHERE mayorista_id = " . (int) $mayorista['id'] . " AND stock_disponible > 20 LIMIT 1")->fetch();

if ($producto === false) {
    exit("El mayorista no tiene stock suficiente.\n");
}

$cantidad = 6;
$subtotal = round((float) $producto['precio_base'] * $cantidad, 2);
$iva = round($subtotal * 0.16, 2);
$total = round($subtotal + $iva, 2);

$pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, ?, 'pagado')")
    ->execute([(int) $comercio['id'], 'demo-rep-' . bin2hex(random_bytes(6)), $total]);
$checkoutId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id,
                          subtotal, iva, envio, total, estado, acepta_antes_de)
     VALUES (UUID(), ?, ?, ?, ?, ?, ?, 56.00, ?, 'aceptado', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
)->execute([$checkoutId, (int) $comercio['id'], (int) $mayorista['id'], (int) $direccion, $subtotal, $iva, round($total + 56, 2)]);

$pedidoId = (int) $pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, iva_tasa, subtotal)
     VALUES (?, ?, ?, ?, ?, 16.00, ?)"
)->execute([$pedidoId, (int) $producto['id'], $producto['nombre'], $cantidad, (float) $producto['precio_base'], $subtotal]);

$pdo->prepare("INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?, NULL, 'aceptado', 'Pedido de demostración')")
    ->execute([$pedidoId]);

// --- Recorrido hasta "buscando repartidor" ---------------------------------
$pedidos = new PedidoService($pdo);
$pruebas = new PruebaEntregaService($pdo);
$asignacion = new AsignacionService($pdo, new TarifaService($pdo), $pedidos);

$actor = (int) $mayorista['usuario_id'];

$pedidos->transicionar($pedidoId, 'preparando', $actor, ['mayorista'], 'Surtiendo el pedido');
$pedidos->transicionar($pedidoId, 'listo_para_recoger', $actor, ['mayorista'], 'Listo en bodega');

$pinRecoleccion = $pruebas->generarRecoleccion($pedidoId);

$pedidos->transicionar($pedidoId, 'buscando_repartidor', $actor, ['mayorista'], 'Buscando repartidor');
$ofertas = $asignacion->lanzarRonda($pedidoId, 1);

$uuid = $pdo->query("SELECT uuid FROM pedidos WHERE id = {$pedidoId}")->fetchColumn();

echo "\n--- Lo que necesitas para entrar ---\n";
echo "Repartidor:  {$email} / repartidor123\n";
echo "Comercio:    comercio@didi2.mx / comercio123\n";
echo "Mayorista:   demo-centro-abarrotero@didi2.mx / demo1234\n\n";
echo "Pedido de demostración: {$uuid}\n";
echo "Ofertas generadas:      {$ofertas}\n";
echo "PIN de recolección:     {$pinRecoleccion}\n";
