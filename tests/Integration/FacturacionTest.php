<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Modules\Facturacion\FacturacionService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class FacturacionTest extends TestCase
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

        $this->pdo->exec("DELETE FROM facturas WHERE pedido_id IN (SELECT id FROM pedidos WHERE uuid LIKE 'fac-%')");
        $this->pdo->exec("DELETE FROM pedidos WHERE uuid LIKE 'fac-%'");
        $this->pdo->exec("DELETE FROM datos_fiscales WHERE usuario_id IN (SELECT id FROM usuarios WHERE email LIKE 'fac-%@t.mx')");
        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'fac-%@t.mx'");

        $sufijo = bin2hex(random_bytes(4));

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["fac-m-{$sufijo}@t.mx"]);
        $this->mayoristaUsuario = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'MayoFac', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$this->mayoristaUsuario]);
        $this->mayoristaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')")
            ->execute(["fac-c-{$sufijo}@t.mx"]);
        $this->comercioUsuario = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'ComercioFac')")->execute([$this->comercioUsuario]);
        $this->comercioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO direcciones (usuario_id, calle, ciudad, latitud, longitud, es_principal) VALUES (?, 'Calle 1', 'CDMX', 19.4, -99.1, 1)")
            ->execute([$this->comercioUsuario]);
        $dirId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO checkouts (uuid, comercio_id, idempotency_key, total, estado) VALUES (UUID(), ?, ?, 1160, 'pagado')")
            ->execute([$this->comercioId, "fac-{$sufijo}"]);
        $checkoutId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO pedidos (uuid, checkout_id, comercio_id, mayorista_id, direccion_entrega_id, subtotal, iva, envio, total, estado) VALUES (?, ?, ?, ?, ?, 1000, 160, 50, 1210, 'completado')")
            ->execute(["fac-{$sufijo}", $checkoutId, $this->comercioId, $this->mayoristaId, $dirId]);
        $this->pedidoId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare("INSERT INTO detalle_pedido (pedido_id, producto_id, nombre_producto, cantidad, precio_unitario, iva_tasa, subtotal) VALUES (?, 1, 'Producto de prueba', 10, 100.00, 0.16, 1000.00)")
            ->execute([$this->pedidoId]);

        // Datos fiscales de ambos lados.
        $this->pdo->prepare("INSERT INTO datos_fiscales (usuario_id, rfc, razon_social, regimen_fiscal, cp, uso_cfdi, correo_receptor) VALUES (?, 'MAY980101CD2', 'MayoFac SA de CV', '601', '06700', 'G03', 'f@mayo.mx')")
            ->execute([$this->mayoristaUsuario]);
        $this->pdo->prepare("INSERT INTO datos_fiscales (usuario_id, rfc, razon_social, regimen_fiscal, cp, uso_cfdi, correo_receptor) VALUES (?, 'COM850101AB1', 'ComercioFac SA de CV', '601', '03100', 'G03', 'f@com.mx')")
            ->execute([$this->comercioUsuario]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->pedidoId)) {
            return;
        }

        $p = (int) $this->pedidoId;
        $this->pdo->exec("DELETE FROM facturas WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM detalle_pedido WHERE pedido_id = {$p}");
        $this->pdo->exec("DELETE FROM pedidos WHERE id = {$p}");
        $this->pdo->exec("DELETE FROM checkouts WHERE comercio_id = {$this->comercioId}");
        $this->pdo->exec("DELETE FROM direcciones WHERE usuario_id = {$this->comercioUsuario}");
        $this->pdo->exec("DELETE FROM comercios_perfil WHERE id = {$this->comercioId}");
        $this->pdo->exec("DELETE FROM mayoristas_perfil WHERE id = {$this->mayoristaId}");
        $this->pdo->exec("DELETE FROM datos_fiscales WHERE usuario_id IN ({$this->comercioUsuario}, {$this->mayoristaUsuario})");
        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'fac-%@t.mx'");
    }

    public function test_guardar_datos_fiscales_valida_el_rfc(): void
    {
        $svc = new FacturacionService($this->pdo);
        $r = $svc->guardarDatosFiscales($this->comercioUsuario, [
            'rfc' => 'RFC_INVALIDO',
            'razon_social' => 'Algo',
            'regimen_fiscal' => '601',
            'cp' => '03100',
            'uso_cfdi' => 'G03',
        ]);

        $this->assertFalse($r['ok']);
        $this->assertSame('RFC_INVALIDO', $r['codigo']);
    }

    public function test_facturar_pedido_no_completado_se_rechaza(): void
    {
        $this->pdo->exec("UPDATE pedidos SET estado = 'aceptado' WHERE id = {$this->pedidoId}");

        $r = (new FacturacionService($this->pdo))->facturar($this->pedidoId);

        $this->assertFalse($r['ok']);
        $this->assertSame('PEDIDO_NO_COMPLETADO', $r['codigo']);
    }

    public function test_facturar_genera_xml_cfdi_40_con_flete(): void
    {
        $r = (new FacturacionService($this->pdo))->facturar($this->pedidoId);

        $this->assertTrue($r['ok'], $r['mensaje']);

        $stmt = $this->pdo->prepare('SELECT xml_texto, total, subtotal, iva, estado, uuid_timbrado FROM facturas WHERE pedido_id = ?');
        $stmt->execute([$this->pedidoId]);
        $f = $stmt->fetch();

        $this->assertNotFalse($f);
        $this->assertSame('timbrada', $f['estado']);
        $this->assertSame(1210.0, (float) $f['total']);
        // subtotal = 1000 (productos) + 50 (flete)
        $this->assertSame(1050.0, (float) $f['subtotal']);
        $this->assertSame(160.0, (float) $f['iva']);
        $this->assertNotEmpty($f['uuid_timbrado']);

        $xml = (string) $f['xml_texto'];
        $this->assertStringContainsString('Version="4.0"', $xml);
        $this->assertStringContainsString('Flete / envío del pedido', $xml);
        $this->assertStringContainsString('<tfd:TimbreFiscalDigital', $xml);
        $this->assertStringContainsString($f['uuid_timbrado'], $xml);
    }

    public function test_no_se_puede_facturar_dos_veces_el_mismo_pedido(): void
    {
        $svc = new FacturacionService($this->pdo);
        $svc->facturar($this->pedidoId);
        $r = $svc->facturar($this->pedidoId);

        $this->assertFalse($r['ok']);
        $this->assertSame('PEDIDO_YA_FACTURADO', $r['codigo'] ?? 'PEDIDO_YA_FACTURADO');
    }

    public function test_datos_fiscales_faltantes_se_rechazan(): void
    {
        $this->pdo->exec("DELETE FROM datos_fiscales WHERE usuario_id = {$this->comercioUsuario}");

        $r = (new FacturacionService($this->pdo))->facturar($this->pedidoId);

        $this->assertFalse($r['ok']);
        $this->assertSame('RECEPTOR_SIN_DATOS', $r['codigo']);
    }
}
