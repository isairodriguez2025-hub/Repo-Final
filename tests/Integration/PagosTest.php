<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Core\AuditLogger;
use App\Modules\Pagos\PagoService;
use App\Modules\Pagos\SimuladoGateway;
use App\Modules\Pedidos\PedidoService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class PagosTest extends TestCase
{
    private const SECRETO = 'secreto-de-prueba';

    private PDO $pdo;
    private PagoService $pagos;
    private SimuladoGateway $pasarela;
    private int $pedidoId;
    private string $referencia;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::nuevaConexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $this->pasarela = new SimuladoGateway(self::SECRETO, 300);
        $this->pagos = new PagoService(
            $this->pdo,
            new PedidoService($this->pdo),
            $this->pasarela,
            new AuditLogger($this->pdo),
            [
                'comision_mayorista_pct' => 8,
                'comision_logistica_pct'  => 12,
                'cargo_servicio'          => 15,
                'comision_pasarela_pct'   => 3.49,
                'webhook_secret'          => self::SECRETO,
            ],
        );

        $sufijo = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["pm-{$sufijo}@t.mx"]);
        $uMayo = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'MayoPago', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$uMayo]);
        $mayoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["pc-{$sufijo}@t.mx"]);
        $uCom = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioPago')")->execute([$uCom]);
        $comId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 1', 'CDMX', 19.4, -99.1, 1)")
            ->execute([$uCom]);
        $dirId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 1231.00, 'pendiente_pago')")
            ->execute([$comId, "chk-{$sufijo}"]);
        $checkoutId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id,
                                  subtotal, iva, envio, total, estado)
             VALUES (UUID(), ?, ?, ?, ?, 1000.00, 160.00, 56.00, 1231.00, 'pendiente_pago')"
        )->execute([$checkoutId, $comId, $mayoId, $dirId]);
        $this->pedidoId = (int) $this->pdo->lastInsertId();

        $this->referencia = 'sim_test_' . $sufijo;

        $this->pdo->prepare(
            "INSERT INTO pagos (checkout_id, pasarela, referencia_externa, metodo, monto, estado)
             VALUES (?, 'simulado', ?, 'tarjeta', 1231.00, 'creado')"
        )->execute([$checkoutId, $this->referencia]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->pedidoId)) {
            return;
        }

        $p = (int) $this->pedidoId;
        $fila = $this->pdo->query("SELECT comercio_id, mayorista_id, checkout_id FROM pedidos WHERE id = {$p}")->fetch();

        $this->pdo->exec("DELETE FROM comisiones WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM pagos_dispersion WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM reembolsos WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM pedido_eventos WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM inventario_reservas WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM webhook_eventos");

        if ($fila) {
            $com = (int) $fila['comercio_id'];
            $may = (int) $fila['mayorista_id'];
            $chk = (int) $fila['checkout_id'];

            $this->pdo->exec("DELETE FROM pagos WHERE checkout_id = {$chk}");
            $this->pdo->exec("DELETE FROM pedidos WHERE id = {$p}");
            $this->pdo->exec("DELETE FROM checkouts WHERE id = {$chk}");
            $this->pdo->exec("DELETE FROM direcciones WHERE usuario_id = (SELECT usuario_id FROM comercios_perfil WHERE id = {$com})");
            $this->pdo->exec("DELETE FROM comercios_perfil WHERE id = {$com}");
            $this->pdo->exec("DELETE FROM mayoristas_perfil WHERE id = {$may}");
        }

        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE '%@t.mx'");
    }

    private function firmar(string $payload, ?int $timestamp = null): array
    {
        $ts = $timestamp ?? time();

        return [$this->pasarela->firmar($payload, $ts), (string) $ts];
    }

    // --- Seguridad del webhook ---------------------------------------------

    public function test_firma_invalida_devuelve_error_sin_procesar(): void
    {
        $payload = json_encode(['tipo' => 'pago.aprobado', 'referencia' => $this->referencia]);

        $r = $this->pagos->procesarWebhook($payload, 'firma-falsa', (string) time(), 'evt_1');

        $this->assertFalse($r['ok']);
        $this->assertSame('FIRMA_INVALIDA', $r['codigo']);
        $this->assertSame('creado', $this->pdo->query("SELECT estado FROM pagos WHERE referencia_externa = '{$this->referencia}'")->fetchColumn());
    }

    public function test_marca_de_tiempo_vieja_se_rechaza(): void
    {
        $payload = json_encode(['tipo' => 'pago.aprobado', 'referencia' => $this->referencia]);
        $viejo = time() - 600; // 10 minutos: fuera de la ventana de 5
        [$firma, $ts] = $this->firmar($payload, $viejo);

        $r = $this->pagos->procesarWebhook($payload, $firma, $ts, 'evt_viejo');

        $this->assertFalse($r['ok']);
        $this->assertSame('FIRMA_INVALIDA', $r['codigo']);
    }

    public function test_evento_repetido_no_duplica(): void
    {
        $payload = json_encode(['tipo' => 'pago.aprobado', 'referencia' => $this->referencia, 'metodo' => 'tarjeta']);
        [$firma, $ts] = $this->firmar($payload);

        $primera = $this->pagos->procesarWebhook($payload, $firma, $ts, 'evt_repetido');
        $segunda = $this->pagos->procesarWebhook($payload, $firma, $ts, 'evt_repetido');

        $this->assertTrue($primera['ok']);
        $this->assertSame('PAGO_AUTORIZADO', $primera['codigo']);
        $this->assertTrue($segunda['ok']);
        $this->assertSame('REPETIDO', $segunda['codigo']);

        $eventos = (int) $this->pdo->query("SELECT COUNT(*) FROM webhook_eventos WHERE evento_id = 'evt_repetido'")->fetchColumn();
        $this->assertSame(1, $eventos);
    }

    public function test_webhook_valido_autoriza_y_avanza_el_pedido(): void
    {
        $payload = json_encode(['tipo' => 'pago.aprobado', 'referencia' => $this->referencia, 'metodo' => 'tarjeta']);
        [$firma, $ts] = $this->firmar($payload);

        $r = $this->pagos->procesarWebhook($payload, $firma, $ts, 'evt_ok');

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('autorizado', $this->pdo->query("SELECT estado FROM pagos WHERE referencia_externa = '{$this->referencia}'")->fetchColumn());
        $this->assertSame('pendiente_aceptacion', $this->pdo->query("SELECT estado FROM pedidos WHERE id = {$this->pedidoId}")->fetchColumn());
    }

    // --- Cierre contable ----------------------------------------------------

    public function test_la_dispersion_cuadra_al_centavo(): void
    {
        $pedido = $this->pdo->query("SELECT * FROM pedidos WHERE id = {$this->pedidoId}")->fetch();
        $r = $this->pagos->calcularDispersion($pedido);

        $this->assertTrue($r['ok'], $r['mensaje']);

        $d = $r['detalle'];
        $suma = $d['disp_mayorista'] + $d['disp_repartidor'] + $d['disp_plataforma'] + $d['comision_pasarela'];

        $this->assertSame($d['total_cobrado'], round($suma, 2));
    }

    public function test_dispersion_registra_comisiones_y_repartos(): void
    {
        $r = $this->pagos->dispersar($this->pedidoId);

        $this->assertTrue($r['ok'], $r['mensaje']);

        $comisiones = (int) $this->pdo->query("SELECT COUNT(*) FROM comisiones WHERE pedido_id = {$this->pedidoId}")->fetchColumn();
        $dispersiones = (int) $this->pdo->query("SELECT COUNT(*) FROM pagos_dispersion WHERE pedido_id = {$this->pedidoId}")->fetchColumn();

        $this->assertSame(4, $comisiones);
        $this->assertSame(2, $dispersiones);
    }

    // --- Reembolsos ---------------------------------------------------------

    public function test_reembolso_total_marca_el_pago(): void
    {
        $this->pdo->prepare("UPDATE pagos SET estado = 'capturado' WHERE referencia_externa = ?")->execute([$this->referencia]);

        $r = $this->pagos->reembolsar($this->pedidoId, null, 'Cancelación antes de preparar');

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('reembolsado', $this->pdo->query("SELECT estado FROM pagos WHERE referencia_externa = '{$this->referencia}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM reembolsos WHERE pedido_id = {$this->pedidoId}")->fetchColumn());
    }

    public function test_reembolso_parcial_marca_estado_parcial(): void
    {
        $this->pdo->prepare("UPDATE pagos SET estado = 'capturado' WHERE referencia_externa = ?")->execute([$this->referencia]);

        $r = $this->pagos->reembolsar($this->pedidoId, 100.0, 'Producto dañado');

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertSame('reembolsado_parcial', $this->pdo->query("SELECT estado FROM pagos WHERE referencia_externa = '{$this->referencia}'")->fetchColumn());
    }

    // --- SEG-23: nada de datos de tarjeta -----------------------------------

    public function test_ninguna_tabla_guarda_datos_de_tarjeta(): void
    {
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND (LOWER(COLUMN_NAME) LIKE '%pan%'
                    OR LOWER(COLUMN_NAME) LIKE '%cvv%'
                    OR LOWER(COLUMN_NAME) LIKE '%cvc%'
                    OR LOWER(COLUMN_NAME) LIKE '%tarjeta%'
                    OR LOWER(COLUMN_NAME) LIKE '%card_number%')"
        );

        $columnas = $stmt->fetchAll() ?: [];

        $this->assertSame([], $columnas, 'Hay columnas con datos de tarjeta: ' . json_encode($columnas));
    }
}
