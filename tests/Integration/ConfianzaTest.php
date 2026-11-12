<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Modules\Confianza\ChatService;
use App\Modules\Confianza\ReclamoService;
use App\Modules\Confianza\ResenaService;
use App\Modules\Finanzas\FinanzasService;
use App\Modules\Pedidos\PedidoService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConfianzaTest extends TestCase
{
    private PDO $pdo;
    private int $pedidoId;
    private int $comercioUsuario;
    private int $mayoristaUsuario;
    private int $comercioId;
    private int $mayoristaId;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::nuevaConexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'conf-%@t.mx'");
        $sufijo = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["conf-m-{$sufijo}@t.mx"]);
        $this->mayoristaUsuario = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'MayoConf', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$this->mayoristaUsuario]);
        $this->mayoristaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["conf-c-{$sufijo}@t.mx"]);
        $this->comercioUsuario = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioConf')")->execute([$this->comercioUsuario]);
        $this->comercioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 1', 'CDMX', 19.4, -99.1, 1)")
            ->execute([$this->comercioUsuario]);
        $dirId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 116, 'pagado')")
            ->execute([$this->comercioId, "conf-{$sufijo}"]);
        $checkoutId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id, subtotal, iva, total, estado) VALUES (UUID(), ?, ?, ?, ?, 100, 16, 116, 'completado')")
            ->execute([$checkoutId, $this->comercioId, $this->mayoristaId, $dirId]);
        $this->pedidoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->pedidoId)) {
            return;
        }

        $p = (int) $this->pedidoId;
        $this->pdo->exec("DELETE FROM resenas WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM reclamo_evidencias WHERE reclamo_id IN (SELECT id FROM reclamos WHERE pedido_id = {$p})");
        $this->pdo->exec("DELETE FROM reclamos WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM mensajes WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM pedido_eventos WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM pedidos WHERE id = {$p}");
        $this->pdo->exec("DELETE FROM checkouts WHERE comercio_id = {$this->comercioId}");
        $this->pdo->exec("DELETE FROM direcciones WHERE usuario_id = {$this->comercioUsuario}");
        $this->pdo->exec("DELETE FROM comercios_perfil WHERE id = {$this->comercioId}");
        $this->pdo->exec("DELETE FROM mayoristas_perfil WHERE id = {$this->mayoristaId}");
        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'conf-%@t.mx'");
    }

    // --- Reseñas (RN-16) ---------------------------------------------------

    public function test_resena_valida_en_pedido_completado(): void
    {
        $r = (new ResenaService($this->pdo))->crear($this->pedidoId, $this->comercioUsuario, 'comercio', 5, 'Excelente servicio');

        $this->assertTrue($r['ok'], $r['mensaje']);
    }

    public function test_resena_duplicada_se_rechaza(): void
    {
        $servicio = new ResenaService($this->pdo);
        $servicio->crear($this->pedidoId, $this->comercioUsuario, 'comercio', 5, 'Bien');
        $r = $servicio->crear($this->pedidoId, $this->comercioUsuario, 'comercio', 4, 'Otra vez');

        $this->assertFalse($r['ok']);
        $this->assertSame('DUPLICADA', $r['codigo']);
    }

    public function test_resena_en_pedido_no_completado_se_rechaza(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'en_camino' WHERE id = {$this->pedidoId}");

        $r = (new ResenaService($this->pdo))->crear($this->pedidoId, $this->comercioUsuario, 'comercio', 5, 'Muy bien');

        $this->assertFalse($r['ok']);
        $this->assertSame('PEDIDO_NO_COMPLETADO', $r['codigo']);
    }

    public function test_palabra_bloqueada_oculta_la_resena(): void
    {
        $r = (new ResenaService($this->pdo))->crear($this->pedidoId, $this->comercioUsuario, 'comercio', 1, 'Esto es una estafa');

        $this->assertTrue($r['ok']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT oculta FROM resenas WHERE pedido_id = {$this->pedidoId}")->fetchColumn());
    }

    // --- Chat (RN-14) ------------------------------------------------------

    public function test_chat_cerrado_en_borrador(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'borrador' WHERE id = {$this->pedidoId}");

        $r = (new ChatService($this->pdo))->enviar($this->pedidoId, $this->comercioUsuario, 'Hola');

        $this->assertFalse($r['ok']);
        $this->assertSame('CHAT_CERRADO', $r['codigo']);
    }

    public function test_mensaje_con_telefono_marca_alerta(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'aceptado' WHERE id = {$this->pedidoId}");

        $r = (new ChatService($this->pdo))->enviar($this->pedidoId, $this->comercioUsuario, 'Márcame al 5512345678');

        $this->assertTrue($r['ok']);
        $this->assertTrue($r['alerta']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT tiene_alerta_desintermediacion FROM mensajes WHERE pedido_id = {$this->pedidoId}")->fetchColumn());
    }

    public function test_mensaje_normal_no_marca_alerta(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'aceptado' WHERE id = {$this->pedidoId}");

        $r = (new ChatService($this->pdo))->enviar($this->pedidoId, $this->comercioUsuario, '¿A qué hora llega el pedido?');

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['alerta']);
    }

    public function test_detecta_correo_electronico(): void
    {
        $this->assertTrue((new ChatService($this->pdo))->detectarDesintermediacion('escríbeme a ventas@correo.com'));
        $this->assertFalse((new ChatService($this->pdo))->detectarDesintermediacion('gracias por todo'));
    }

    // --- Reclamos (RN-11) --------------------------------------------------

    public function test_reclamo_dentro_de_plazo_se_acepta(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'entregado' WHERE id = {$this->pedidoId}");
        $this->pdo->prepare("INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota) VALUES (?, 'en_puerta', 'entregado', 'Entrega')")
            ->execute([$this->pedidoId]);

        $r = (new ReclamoService($this->pdo, new PedidoService($this->pdo)))
            ->abrir($this->pedidoId, $this->comercioUsuario, 'faltante', 'Faltaron dos cajas del pedido');

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('en_disputa', $this->pdo->query("SELECT estado FROM pedidos WHERE id = {$this->pedidoId}")->fetchColumn());
    }

    public function test_reclamo_fuera_de_plazo_se_rechaza(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'entregado' WHERE id = {$this->pedidoId}");
        $this->pdo->prepare("INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota, created_at) VALUES (?, 'en_puerta', 'entregado', 'Entrega', DATE_SUB(NOW(), INTERVAL 72 HOUR))")
            ->execute([$this->pedidoId]);

        $r = (new ReclamoService($this->pdo, new PedidoService($this->pdo)))
            ->abrir($this->pedidoId, $this->comercioUsuario, 'danado', 'Llegó todo roto y mojado');

        $this->assertFalse($r['ok']);
        $this->assertSame('FUERA_DE_PLAZO', $r['codigo']);
    }

    public function test_descripcion_muy_corta_se_rechaza(): void
    {
        $r = (new ReclamoService($this->pdo, new PedidoService($this->pdo)))
            ->abrir($this->pedidoId, $this->comercioUsuario, 'calidad', 'malo');

        $this->assertFalse($r['ok']);
        $this->assertSame('DESCRIPCION_CORTA', $r['codigo']);
    }

    // --- Finanzas ----------------------------------------------------------

    public function test_los_totales_cuadran_con_los_pedidos_completados(): void
    {
        $r = (new FinanzasService($this->pdo))->comercio($this->comercioId, date('Y-m-01'), date('Y-m-d'));

        $esperado = (float) $this->pdo->query(
            "SELECT COALESCE(SUM(total),0) FROM pedidos WHERE comercio_id = {$this->comercioId} AND estado = 'completado'"
        )->fetchColumn();

        $this->assertSame(round($esperado, 2), round((float) $r['resumen']['total'], 2));
    }

    public function test_el_csv_solo_trae_los_pedidos_del_comercio(): void
    {
        $csv = (new FinanzasService($this->pdo))->csvComercio($this->comercioId, date('Y-m-01'), date('Y-m-d'));

        $this->assertStringContainsString('pedido,fecha,mayorista', $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'Encabezado + un pedido');
    }
}
