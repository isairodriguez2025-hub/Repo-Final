#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Crea un pedido nuevo esperando repartidor y lo oferta.
 * No toca las cuentas: solo agrega el pedido.
 *
 * Uso: php bin/demo-nueva-entrega.php
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->safeLoad();

use App\Config\Database;
use App\Modules\Logistica\AsignacionService;
use App\Modules\Logistica\PruebaEntregaService;
use App\Modules\Logistica\TarifaService;
use App\Modules\Pedidos\PedidoService;

$pdo = Database::nuevaConexion();

$comercio = $pdo->query("SELECT id, usuario_id FROM comercios_perfil WHERE nombre_negocio = 'Abarrotes La Esquina'")->fetch();
$mayorista = $pdo->query("SELECT id, usuario_id FROM mayoristas_perfil WHERE nombre_comercial = 'Centro Abarrotero'")->fetch();

if ($comercio === false || $mayorista === false) {
    exit("Faltan las cuentas de demostración.\n");
}

$direccion = $pdo->query("SELECT id FROM direcciones WHERE usuario_id = " . (int) $comercio['usuario_id'] . " LIMIT 1")->fetchColumn();
$producto = $pdo->query("SELECT id, nombre, precio_base, stock_disponible FROM productos WHERE mayorista_id = " . (int) $mayorista['id'] . " AND activo = 1 AND stock_disponible >= 10 ORDER BY id LIMIT 1")->fetch();

if ($producto === false) {
    exit("No hay stock suficiente en el mayorista.\n");
}

$cantidad = min(6, (int) $producto['stock_disponible']);
$subtotal = round((float) $producto['precio_base'] * $cantidad, 2);
$iva = round($subtotal * 0.16, 2);
$envio = 56.00;
$total = round($subtotal + $iva + $envio, 2);

$pdo->beginTransaction();

try {
    $pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, ?, 'pagado')")
        ->execute([(int) $comercio['id'], 'demo-' . bin2hex(random_bytes(8)), $total]);
    $checkoutId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id,
                              subtotal, iva, envio, total, estado, acepta_antes_de)
         VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, 'aceptado', DATE_ADD(NOW(), INTERVAL 30 MINUTE))"
    )->execute([$checkoutId, (int) $comercio['id'], (int) $mayorista['id'], (int) $direccion, $subtotal, $iva, $envio, $total]);

    $pedidoId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, iva_tasa, subtotal)
         VALUES (?, ?, ?, ?, ?, 16.00, ?)"
    )->execute([$pedidoId, (int) $producto['id'], $producto['nombre'], $cantidad, (float) $producto['precio_base'], $subtotal]);

    $pdo->prepare("INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?, NULL, 'aceptado', 'Pedido de demostración')")
        ->execute([$pedidoId]);

    // Reserva real del stock, como en un pedido de verdad.
    $pdo->prepare('UPDATE productos SET stock_disponible = stock_disponible - ?, stock_reservado = stock_reservado + ? WHERE id = ?')
        ->execute([$cantidad, $cantidad, (int) $producto['id']]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    exit('Error: ' . $e->getMessage() . "\n");
}

$pedidos = new PedidoService($pdo);
$pruebas = new PruebaEntregaService($pdo);
$asignacion = new AsignacionService($pdo, new TarifaService($pdo), $pedidos);

$actor = (int) $mayorista['usuario_id'];

$pedidos->transicionar($pedidoId, 'preparando', $actor, ['mayorista'], 'Surtiendo el pedido');
$pedidos->transicionar($pedidoId, 'listo_para_recoger', $actor, ['mayorista'], 'Listo en bodega');

// El PIN se genera cuando ya existe el envío (o lo crea en el acto).
$pin = $pruebas->generarRecoleccion($pedidoId);

$pedidos->transicionar($pedidoId, 'buscando_repartidor', $actor, ['mayorista'], 'Buscando repartidor');
$ofertas = $asignacion->lanzarRonda($pedidoId, 1);

// Para la demo, la oferta dura 40 minutos en vez de 60 segundos.
$pdo->prepare("UPDATE ofertas_envio SET expira_en = DATE_ADD(NOW(), INTERVAL 40 MINUTE)
               WHERE envio_id = (SELECT id FROM envios WHERE pedido_id = ?) AND estado = 'pendiente'")
    ->execute([$pedidoId]);

$uuid = $pdo->query("SELECT uuid FROM pedidos WHERE id = {$pedidoId}")->fetchColumn();

echo "Pedido creado: {$uuid}\n";
echo "Producto:      {$producto['nombre']} × {$cantidad}\n";
echo "Total:         \$" . number_format($total, 2) . "\n";
echo "Ofertas:       {$ofertas}\n";
echo "PIN recolección: {$pin}\n";
