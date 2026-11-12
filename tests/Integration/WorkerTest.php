<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Modules\Jobs\Worker;
use App\Modules\Notificaciones\NotificacionService;
use App\Modules\Pedidos\PedidoService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class WorkerTest extends TestCase
{
    private PDO $pdo;
    private Worker $worker;
    private NotificacionService $notificaciones;
    private string $dirCorreos;
    private int $pedidoId;
    private int $productoId;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::nuevaConexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $this->dirCorreos = sys_get_temp_dir() . '/didi2-correos-' . bin2hex(random_bytes(4));
        $pedidos = new PedidoService($this->pdo);
        $this->notificaciones = new NotificacionService($this->pdo, $this->dirCorreos);
        $this->worker = new Worker($this->pdo, $pedidos, $this->notificaciones);

        $sufijo = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["wm-{$sufijo}@t.mx"]);
        $uMayo = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'MayoWorker', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$uMayo]);
        $mayoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["wc-{$sufijo}@t.mx"]);
        $uCom = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioWorker')")->execute([$uCom]);
        $comId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 1', 'CDMX', 19.4, -99.1, 1)")
            ->execute([$uCom]);
        $dirId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO productos (uuid, mayorista_id, categoria_id, nombre, precio_base, iva_tasa, unidad_venta, contenido, peso_kg, volumen_m3, cantidad_minima, multiplo_venta, stock_disponible, stock_reservado, activo) VALUES (UUID(), ?, 1, 'ProductoWorker', 100, 16, 'caja', '1', 1, 0.001, 1, 1, 8, 2, 1)")
            ->execute([$mayoId]);
        $this->productoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 116, 'pendiente_pago')")
            ->execute([$comId, "wchk-{$sufijo}"]);
        $checkoutId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id, subtotal, iva, total, estado) VALUES (UUID(), ?, ?, ?, ?, 100, 16, 116, 'pendiente_pago')")
            ->execute([$checkoutId, $comId, $mayoId, $dirId]);
        $this->pedidoId = (int) $this->pdo->lastInsertId();

        // Reserva ya vencida: debe liberarse en la siguiente pasada.
        $this->pdo->prepare("INSERT INTO inventario_reservas (producto_id, pedido_id, cantidad, estado, expira_en) VALUES (?, ?, 2, 'activa', DATE_SUB(NOW(), INTERVAL 1 MINUTE))")
            ->execute([$this->productoId, $this->pedidoId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->pedidoId)) {
            return;
        }

        $p = (int) $this->pedidoId;
        $fila = $this->pdo->query("SELECT comercio_id, mayorista_id, checkout_id FROM pedidos WHERE id = {$p}")->fetch();

        $this->pdo->exec("DELETE FROM notificaciones WHERE tipo LIKE '%worker%' OR asunto LIKE '%prueba%'");
        $this->pdo->exec("DELETE FROM pedido_eventos WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM inventario_reservas WHERE pedido_id = {$p}");
        $this->pdo->exec('DELETE FROM ejecuciones_worker');

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

        foreach (glob($this->dirCorreos . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dirCorreos);
    }

    public function test_el_worker_libera_reservas_vencidas(): void
    {
        $antes = (int) $this->pdo->query("SELECT stock_disponible FROM productos WHERE id = {$this->productoId}")->fetchColumn();

        $this->worker->ejecutar();

        $despues = (int) $this->pdo->query("SELECT stock_disponible FROM productos WHERE id = {$this->productoId}")->fetchColumn();
        $reservado = (int) $this->pdo->query("SELECT stock_reservado FROM productos WHERE id = {$this->productoId}")->fetchColumn();
        $estadoReserva = $this->pdo->query("SELECT estado FROM inventario_reservas WHERE pedido_id = {$this->pedidoId}")->fetchColumn();

        $this->assertSame($antes + 2, $despues);
        $this->assertSame(0, $reservado);
        $this->assertSame('liberada', $estadoReserva);
    }

    public function test_el_worker_expira_el_pedido_sin_respuesta(): void
    {
        $this->pdo->prepare("UPDATE pedidos SET estado = 'pendiente_aceptacion', acepta_antes_de = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = ?")
            ->execute([$this->pedidoId]);

        $this->worker->ejecutar();

        $this->assertSame('expirado', $this->pdo->query("SELECT estado FROM pedidos WHERE id = {$this->pedidoId}")->fetchColumn());
    }

    public function test_deja_registro_de_cada_tarea(): void
    {
        $this->worker->ejecutar();

        $tareas = $this->pdo->query('SELECT tarea FROM ejecuciones_worker ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertCount(6, $tareas);
        $this->assertContains('reservas_vencidas', $tareas);
        $this->assertContains('notificaciones', $tareas);
        $this->assertContains('programados', $tareas);
    }

    public function test_encola_y_entrega_una_notificacion(): void
    {
        $usuarioId = (int) $this->pdo->query("SELECT usuario_id FROM comercios_perfil WHERE nombre_negocio = 'ComercioWorker'")->fetchColumn();

        $this->notificaciones->encolar($usuarioId, 'pedido_nuevo', 'Pedido de prueba', 'Cuerpo de prueba');
        $r = $this->notificaciones->enviarPendientes();

        $this->assertSame(2, $r['enviadas']); // bandeja + correo
        $this->assertSame(0, $r['fallidas']);

        $correos = glob($this->dirCorreos . '/*.html') ?: [];
        $this->assertCount(1, $correos, 'Debe haberse escrito un correo');
    }

    public function test_respeta_las_preferencias_del_usuario(): void
    {
        $usuarioId = (int) $this->pdo->query("SELECT usuario_id FROM comercios_perfil WHERE nombre_negocio = 'ComercioWorker'")->fetchColumn();

        $this->pdo->prepare('INSERT INTO preferencias_notificacion (usuario_id, tipo, correo, push) VALUES (?,?,0,0)')
            ->execute([$usuarioId, 'pedido_nuevo']);

        $encoladas = $this->notificaciones->encolar($usuarioId, 'pedido_nuevo', 'Sin correo', 'Solo bandeja');

        $this->assertSame(1, $encoladas, 'Con correo desactivado solo debe quedar la bandeja');
    }

    public function test_la_bandeja_cuenta_las_no_leidas(): void
    {
        $usuarioId = (int) $this->pdo->query("SELECT usuario_id FROM comercios_perfil WHERE nombre_negocio = 'ComercioWorker'")->fetchColumn();

        $this->notificaciones->encolar($usuarioId, 'pedido_nuevo', 'Uno', 'Cuerpo', null, ['bandeja']);
        $this->notificaciones->encolar($usuarioId, 'pedido_nuevo', 'Dos', 'Cuerpo', null, ['bandeja']);

        $this->assertSame(2, $this->notificaciones->sinLeer($usuarioId));

        $this->notificaciones->marcarLeidas($usuarioId);

        $this->assertSame(0, $this->notificaciones->sinLeer($usuarioId));
    }
}
