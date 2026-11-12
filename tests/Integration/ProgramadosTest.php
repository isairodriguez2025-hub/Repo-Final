<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Modules\Carrito\CarritoRepository;
use App\Modules\Carrito\CarritoService;
use App\Modules\Catalogo\PrecioService;
use App\Modules\Favoritos\FavoritoService;
use App\Modules\Notificaciones\NotificacionService;
use App\Modules\Programados\ProgramadoService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProgramadosTest extends TestCase
{
    private PDO $pdo;
    private int $comercioId;
    private int $mayoristaId;
    private int $productoId;
    private string $productoUuid;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::nuevaConexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'prog-%@t.mx'");
        $sufijo = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["prog-m-{$sufijo}@t.mx"]);
        $uMayo = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'MayoProg', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$uMayo]);
        $this->mayoristaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["prog-c-{$sufijo}@t.mx"]);
        $uCom = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioProg')")->execute([$uCom]);
        $this->comercioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 1', 'CDMX', 19.4, -99.1, 1)")
            ->execute([$uCom]);

        $this->pdo->prepare("INSERT INTO productos (uuid, mayorista_id, categoria_id, nombre, precio_base, iva_tasa, unidad_venta, contenido, peso_kg, volumen_m3, cantidad_minima, multiplo_venta, stock_disponible, activo) VALUES (UUID(), ?, 1, 'ProductoProg', 100, 16, 'caja', '1', 1, 0.001, 1, 1, 50, 1)")
            ->execute([$this->mayoristaId]);
        $this->productoId = (int) $this->pdo->lastInsertId();
        $this->productoUuid = (string) $this->pdo->query("SELECT uuid FROM productos WHERE id = {$this->productoId}")->fetchColumn();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->comercioId)) {
            return;
        }

        $pdo = $this->pdo;
        $pdo->exec("DELETE FROM pedidos_programados_items WHERE programado_id IN (SELECT id FROM pedidos_programados WHERE comercio_id = {$this->comercioId})");
        $pdo->exec("DELETE FROM pedidos_programados WHERE comercio_id = {$this->comercioId}");
        $pdo->exec("DELETE FROM favoritos WHERE comercio_id = {$this->comercioId}");

        $pedidos = $pdo->query("SELECT id, checkout_id FROM pedidos WHERE comercio_id = {$this->comercioId}")->fetchAll() ?: [];

        foreach ($pedidos as $p) {
            $pdo->exec("DELETE FROM detalle_pedido WHERE pedido_id = " . (int) $p['id']);
            $pdo->exec("DELETE FROM pedido_eventos WHERE pedido_id = " . (int) $p['id']);
            $pdo->exec("DELETE FROM pedidos WHERE id = " . (int) $p['id']);
            $pdo->exec("DELETE FROM checkouts WHERE id = " . (int) $p['checkout_id']);
        }

        $pdo->exec("DELETE FROM productos WHERE mayorista_id = {$this->mayoristaId}");
        $pdo->exec("DELETE FROM direcciones WHERE usuario_id IN (SELECT usuario_id FROM comercios_perfil WHERE id = {$this->comercioId})");
        $pdo->exec("DELETE FROM comercios_perfil WHERE id = {$this->comercioId}");
        $pdo->exec("DELETE FROM mayoristas_perfil WHERE id = {$this->mayoristaId}");
        $pdo->exec("DELETE FROM usuarios WHERE email LIKE 'prog-%@t.mx'");
    }

    private function servicio(): ProgramadoService
    {
        return new ProgramadoService(
            $this->pdo,
            new PrecioService(),
            new NotificacionService($this->pdo, sys_get_temp_dir() . '/didi2-prog-correos'),
        );
    }

    private function crearPlantilla(string $frecuencia = 'semanal'): int
    {
        $r = $this->servicio()->crear($this->comercioId, $this->mayoristaId, [
            'frecuencia' => $frecuencia,
            'hora'       => '09:00',
            'items'      => [['producto_uuid' => $this->productoUuid, 'cantidad' => 3]],
        ]);

        return (int) $r['id'];
    }

    public function test_crea_la_plantilla_con_proximo_envio(): void
    {
        $id = $this->crearPlantilla();

        $fila = $this->pdo->query("SELECT * FROM pedidos_programados WHERE id = {$id}")->fetch();

        $this->assertSame('activa', $fila['estado']);
        $this->assertNotNull($fila['proximo_envio']);
    }

    public function test_pausada_no_genera_borradores(): void
    {
        $id = $this->crearPlantilla();
        $this->pdo->exec("UPDATE pedidos_programados SET proximo_envio = DATE_ADD(NOW(), INTERVAL 2 HOUR), estado = 'pausada' WHERE id = {$id}");

        $r = $this->servicio()->generarBorradores();

        $this->assertSame(0, $r['borradores']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM pedidos WHERE es_programado = 1 AND comercio_id = {$this->comercioId}")->fetchColumn());
    }

    public function test_genera_borrador_sin_reservar_stock(): void
    {
        $id = $this->crearPlantilla();
        $this->pdo->exec("UPDATE pedidos_programados SET proximo_envio = DATE_ADD(NOW(), INTERVAL 2 HOUR) WHERE id = {$id}");

        $antes = $this->pdo->query("SELECT stock_disponible FROM productos WHERE id = {$this->productoId}")->fetchColumn();

        $r = $this->servicio()->generarBorradores();

        $despues = $this->pdo->query("SELECT stock_disponible FROM productos WHERE id = {$this->productoId}")->fetchColumn();
        $pedido = $this->pdo->query("SELECT estado FROM pedidos WHERE es_programado = 1 AND comercio_id = {$this->comercioId}")->fetchColumn();

        $this->assertSame(1, $r['borradores']);
        $this->assertSame('borrador', $pedido);
        $this->assertSame((int) $antes, (int) $despues, 'El borrador no debe reservar stock');
    }

    public function test_recompra_omite_agotados(): void
    {
        // Pedido histórico con el producto
        $this->pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 116, 'pagado')")
            ->execute([$this->comercioId, 'prog-chk-' . bin2hex(random_bytes(4))]);
        $checkoutId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id, subtotal, iva, total, estado) VALUES (UUID(), ?, ?, ?, (SELECT id FROM direcciones WHERE usuario_id = (SELECT usuario_id FROM comercios_perfil WHERE id = ?) LIMIT 1), 100, 16, 116, 'entregado')")
            ->execute([$checkoutId, $this->comercioId, $this->mayoristaId, $this->comercioId]);
        $pedidoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, iva_tasa, subtotal) VALUES (?, ?, 'ProductoProg', 2, 100, 16, 200)")
            ->execute([$pedidoId, $this->productoId]);

        // Se agota
        $this->pdo->exec("UPDATE productos SET stock_disponible = 0 WHERE id = {$this->productoId}");

        $favoritos = new FavoritoService($this->pdo, new CarritoService(new CarritoRepository($this->pdo), new PrecioService()));
        $r = $favoritos->recomprar($this->comercioId, $pedidoId);

        $this->assertSame(0, $r['agregados']);
        $this->assertCount(1, $r['omitidos']);
        $this->assertSame('agotado', $r['omitidos'][0]['motivo']);
    }
}
