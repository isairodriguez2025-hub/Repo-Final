<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Modules\Admin\AdminController;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminTest extends TestCase
{
    private PDO $pdo;
    private int $adminId;
    private int $comercioId;
    private int $mayoristaId;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::conexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        // Limpiar datos de prueba
        $this->pdo->exec("DELETE FROM parametros_historial WHERE parametro_id IN (SELECT id FROM parametros WHERE clave LIKE 'test_%')");
        $this->pdo->exec("DELETE FROM parametros WHERE clave LIKE 'test_%'");
        $this->pdo->exec("DELETE FROM reclamo_valoraciones WHERE admin_id IN (SELECT id FROM usuarios WHERE email LIKE 'admin-test-%')");
        $this->pdo->exec("DELETE FROM reclamos WHERE id IN (SELECT id FROM pedidos WHERE uuid LIKE 'admin-test-%')");
        $this->pdo->exec("DELETE FROM pedidos WHERE uuid LIKE 'admin-test-%'");
        $this->pdo->exec("DELETE FROM comercios_perfil WHERE usuario_id IN (SELECT id FROM usuarios WHERE email LIKE 'admin-test-%')");
        $this->pdo->exec("DELETE FROM mayoristas_perfil WHERE usuario_id IN (SELECT id FROM usuarios WHERE email LIKE 'admin-test-%')");
        $this->pdo->exec("DELETE FROM usuarios WHERE email LIKE 'admin-test-%'");

        // Crear admin
        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), 'admin-test@didi2.mx', 'x', '5500000000', 'activo')")
            ->execute([]);
        $this->adminId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO usuario_roles (usuario_id, rol_id) VALUES (?, (SELECT id FROM roles WHERE nombre = 'admin_total' LIMIT 1))")
            ->execute([$this->adminId]);

        // Crear comercio
        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), 'comercio-test@didi2.mx', 'x', '5500000000', 'activo')")
            ->execute([]);
        $comId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO comercios_perfil (usuario_id, nombre_negocio) VALUES (?, 'Comercio Test')")->execute([$comId]);
        $this->comercioId = $comId;

        // Crear mayorista
        $this->pdo->prepare("INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), 'mayorista-test@didi2.mx', 'x', '5500000000', 'activo')")
            ->execute([]);
        $mayId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, activo) VALUES (UUID(), ?, 'Mayorista Test', 'abarrotes', 19.4, -99.1, 'aprobado', 1)")
            ->execute([$mayId]);
        $this->mayoristaId = $mayId;
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo, $this->adminId)) {
            return;
        }

        $this->pdo->exec("DELETE FROM parametros_historial WHERE parametro_id IN (SELECT id FROM parametros WHERE clave LIKE 'test_%')");
        $this->pdo->exec("DELETE FROM parametros WHERE clave LIKE 'test_%'");
        $this->pdo->exec("DELETE FROM reclamo_valoraciones WHERE admin_id = {$this->adminId}");
        $this->pdo->exec("DELETE FROM reclamos WHERE id IN (SELECT id FROM pedidos WHERE uuid LIKE 'admin-test-%')");
        $this->pdo->exec("DELETE FROM pedidos WHERE uuid LIKE 'admin-test-%'");
        $this->pdo->exec("DELETE FROM comercios_perfil WHERE usuario_id = {$this->comercioId}");
        $this->pdo->exec("DELETE FROM mayoristas_perfil WHERE usuario_id = {$this->mayoristaId}");
        $this->pdo->exec("DELETE FROM usuarios WHERE id IN ({$this->adminId}, {$this->comercioId}, {$this->mayoristaId})");
    }

    public function test_dashboard_devuelve_estadisticas(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->dashboard();

        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString('Panel de administración', $body);
        $this->assertStringContainsString('Usuarios', $body);
    }

    public function test_listar_usuarios_muestra_admin(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->listarUsuarios(\App\Core\Request::fromGlobals());

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('admin-test@didi2.mx', $r->body());
    }

    public function test_cambiar_rol_requiere_admin_total(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $reqPost = new \App\Core\Request('POST', '/admin/usuarios/' . $this->comercioId . '/rol', [], ['rol' => 'mayorista', '_csrf' => 'test'], [], []);

        $r = $ctrl->cambiarRol($reqPost, ['id' => (string) $this->comercioId]);
        $this->assertSame(403, $r->status());
    }

    public function test_cambiar_estado_requiere_admin_total(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $reqPost = new \App\Core\Request('POST', '/admin/usuarios/' . $this->comercioId . '/estado', [], ['estado' => 'suspendido', '_csrf' => 'test'], [], []);

        $r = $ctrl->cambiarEstado($reqPost, ['id' => (string) $this->comercioId]);
        $this->assertSame(403, $r->status());
    }

    public function test_listar_reclamos_vacia_por_defecto(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->listarReclamos(\App\Core\Request::fromGlobals());

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Reclamos y disputas', $r->body());
    }

    public function test_parametros_lista_los_iniciales(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->listarParametros();

        $this->assertSame(200, $r->status());
        $body = $r->body();
        $this->assertStringContainsString('app.nombre', $body);
        $this->assertStringContainsString('Didi2 B2B', $body);
    }

    public function test_actualizar_parametro_requiere_admin_total(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $reqPost = new \App\Core\Request('POST', '/admin/parametros/app.nombre', [], ['valor' => 'nuevo-valor', '_csrf' => 'test'], [], []);

        $r = $ctrl->actualizarParametro($reqPost, ['clave' => 'app.nombre']);
        $this->assertSame(403, $r->status());
    }

    public function test_historial_parametro_vacio_sin_cambios(): void
    {
        $ctrl = new AdminController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->historialParametro(\App\Core\Request::fromGlobals(), ['clave' => 'app.nombre']);

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Historial: app.nombre', $r->body());
    }
}
