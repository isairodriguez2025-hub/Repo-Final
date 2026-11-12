<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Modules\Auth\UsuarioRepository;
use App\Modules\Pedidos\PedidoService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class PedidosTest extends TestCase
{
    private PDO $pdo;
    private PedidoService $servicio;
    private int $pedidoId;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::nuevaConexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $this->servicio = new PedidoService($this->pdo);

        // Datos mínimos para poder crear un pedido completo.
        $sufijo = bin2hex(random_bytes(3));

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["may-{$sufijo}@t.mx"]);
        $uMayo = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'MayoTest', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$uMayo]);
        $mayoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["com-{$sufijo}@t.mx"]);
        $uCom = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioTest')")
            ->execute([$uCom]);
        $comId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 1', 'CDMX', 19.4, -99.1, 1)")
            ->execute([$uCom]);
        $dirId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO productos (uuid, mayorista_id, categoria_id, nombre, precio_base, iva_tasa, unidad_venta, contenido, peso_kg, volumen_m3, cantidad_minima, multiplo_venta, stock_disponible, activo) VALUES (UUID(), ?, 1, 'ProductoTest', 100, 16, 'caja', '1', 1, 0.001, 1, 1, 10, 1)")
            ->execute([$mayoId]);
        $prodId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 116, 'pendiente_pago')")
            ->execute([$comId, "test-{$sufijo}"]);
        $checkoutId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id, subtotal, iva, total, estado) VALUES (UUID(), ?, ?, ?, ?, 100, 16, 116, 'borrador')")
            ->execute([$checkoutId, $comId, $mayoId, $dirId]);
        $this->pedidoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?, NULL, 'borrador', 'Prueba')")
            ->execute([$this->pedidoId]);

        // Reserva activa de 2 piezas para probar la liberación.
        $this->pdo->prepare("UPDATE productos SET stock_disponible = 8, stock_reservado = 2 WHERE id = ?")->execute([$prodId]);
        $this->pdo->prepare("INSERT INTO inventario_reservas (producto_id, pedido_id, cantidad, estado, expira_en) VALUES (?, ?, 2, 'activa', DATE_ADD(NOW(), INTERVAL 15 MINUTE))")
            ->execute([$prodId, $this->pedidoId]);

        $this->pdo->prepare('UPDATE pedidos SET mayorista_id = ? WHERE id = ?')->execute([$mayoId, $this->pedidoId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->pedidoId)) {
            return;
        }

        $p = (int) $this->pedidoId;
        $this->pdo->exec("DELETE FROM pedido_eventos WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM inventario_reservas WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM detalle_pedido WHERE pedido_id = {$p}");

        $fila = $this->pdo->query("SELECT comercio_id, mayorista_id, checkout_id FROM pedidos WHERE id = {$p}")->fetch();

        if ($fila) {
            $com = (int) $fila['comercio_id'];
            $may = (int) $fila['mayorista_id'];
            $chk = (int) $fila['checkout_id'];

            $this->pdo->exec("DELETE FROM pedidos WHERE id = {$p}");
            $this->pdo->exec("DELETE FROM checkouts WHERE id = {$chk}");
            $this->pdo->exec("DELETE FROM productos WHERE mayorista_id = {$may}");

            $this->pdo->exec("DELETE FROM direcciones WHERE usuario_id = (SELECT usuario_id FROM comercios_perfil WHERE id = {$com})");
            $this->pdo->exec("DELETE FROM comercios_perfil WHERE id = {$com}");
            $this->pdo->exec("DELETE FROM mayoristas_perfil WHERE id = {$may}");
        }

        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE '%@t.mx'");
    }

    public function test_transicion_valida_de_borrador_a_pendiente_pago(): void
    {
        $r = $this->servicio->transicionar($this->pedidoId, 'pendiente_pago', 1, ['comercio']);

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('pendiente_pago', $r['estado']);
    }

    public function test_comercio_no_puede_cancelar_un_pedido_en_preparando(): void
    {
        $this->servicio->transicionar($this->pedidoId, 'pendiente_pago', 1, ['comercio']);

        $r = $this->servicio->transicionar($this->pedidoId, 'preparando', 1, ['comercio']);

        $this->assertFalse($r['ok'], 'No debe permitir saltar de pendiente_pago a preparando');
    }

    public function test_mayorista_no_puede_marcar_pendiente_pago(): void
    {
        $r = $this->servicio->transicionar($this->pedidoId, 'pendiente_pago', 1, ['mayorista']);

        $this->assertFalse($r['ok']);
    }

    public function test_transicion_a_estado_inexistente_se_rechaza(): void
    {
        $r = $this->servicio->transicionar($this->pedidoId, 'volando', 1, ['comercio']);

        $this->assertFalse($r['ok']);
    }

    public function test_cada_transicion_queda_en_el_historial(): void
    {
        $this->servicio->transicionar($this->pedidoId, 'pendiente_pago', 7, ['comercio'], 'Confirmado');
        $eventos = $this->servicio->eventos($this->pedidoId);

        $ultimo = end($eventos);

        $this->assertSame('borrador', $ultimo['estado_anterior']);
        $this->assertSame('pendiente_pago', $ultimo['estado_nuevo']);
        $this->assertSame(7, (int) $ultimo['actor_usuario_id']);
        $this->assertSame('Confirmado', $ultimo['nota']);
    }

    public function test_cancelar_libera_el_stock_reservado(): void
    {
        $antes = $this->pdo->query("SELECT stock_disponible FROM productos WHERE nombre = 'ProductoTest'")->fetchColumn();

        $this->servicio->transicionar($this->pedidoId, 'cancelado', 1, ['comercio'], 'Cancelado por el comercio');

        $despues = $this->pdo->query("SELECT stock_disponible FROM productos WHERE nombre = 'ProductoTest'")->fetchColumn();
        $reserva = $this->pdo->query("SELECT estado FROM inventario_reservas WHERE pedido_id = {$this->pedidoId}")->fetchColumn();

        $this->assertSame((int) $antes + 2, (int) $despues);
        $this->assertSame('liberada', $reserva);
    }

    public function test_aceptar_consume_la_reserva(): void
    {
        $this->servicio->transicionar($this->pedidoId, 'pendiente_pago', 1, ['comercio']);
        $this->servicio->transicionar($this->pedidoId, 'pago_autorizado', null, ['sistema']);
        $this->servicio->transicionar($this->pedidoId, 'pendiente_aceptacion', null, ['sistema']);
        $this->servicio->transicionar($this->pedidoId, 'aceptado', 1, ['mayorista']);

        $reserva = $this->pdo->query("SELECT estado FROM inventario_reservas WHERE pedido_id = {$this->pedidoId}")->fetchColumn();

        $this->assertSame('consumida', $reserva);
    }

    public function test_flujo_completo_hasta_entregado(): void
    {
        $pasos = [
            ['pendiente_pago', ['comercio']],
            ['pago_autorizado', ['sistema']],
            ['pendiente_aceptacion', ['sistema']],
            ['aceptado', ['mayorista']],
            ['preparando', ['mayorista']],
            ['listo_para_recoger', ['mayorista']],
            ['buscando_repartidor', ['sistema']],
            ['repartidor_asignado', ['sistema']],
            ['en_recoleccion', ['repartidor']],
            ['en_camino', ['repartidor']],
            ['en_puerta', ['repartidor']],
            ['entregado', ['repartidor']],
        ];

        foreach ($pasos as [$estado, $roles]) {
            $r = $this->servicio->transicionar($this->pedidoId, $estado, 1, $roles);
            $this->assertTrue($r['ok'], "Falló al pasar a {$estado}: " . $r['mensaje']);
        }

        $this->assertSame('entregado', $this->pdo->query("SELECT estado FROM pedidos WHERE id = {$this->pedidoId}")->fetchColumn());
        $this->assertCount(13, $this->servicio->eventos($this->pedidoId));
    }
}
