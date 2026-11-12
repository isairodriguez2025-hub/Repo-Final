<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Core\AuditLogger;
use App\Modules\Catalogo\ProductoRepository;
use App\Modules\Catalogo\ProductoService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas contra la base real. Se saltan si no hay conexión configurada.
 */
final class CatalogoTest extends TestCase
{
    private PDO $pdo;
    private ProductoService $servicio;
    private ProductoRepository $repo;
    private int $usuarioId;
    private int $mayoristaId;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::conexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $this->repo = new ProductoRepository($this->pdo);
        $this->servicio = new ProductoService($this->repo, new AuditLogger($this->pdo), $this->pdo);

        // Usuario mayorista de prueba, con KYC aprobado.
        $email = 'test-catalogo-' . bin2hex(random_bytes(4)) . '@didi2.mx';
        $this->pdo->prepare(
            "INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')"
        )->execute([$email]);
        $this->usuarioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO mayoristas_perfil (uuid, usuario_id, nombre_comercial, categoria_giro, estado_kyc, activo)
             VALUES (UUID(), ?, 'Prueba', 'abarrotes', 'aprobado', 1)"
        )->execute([$this->usuarioId]);
        $this->mayoristaId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM productos WHERE mayorista_id = ?')->execute([$this->mayoristaId]);
            $this->pdo->prepare('DELETE FROM mayoristas_perfil WHERE id = ?')->execute([$this->mayoristaId]);
            $this->pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$this->usuarioId]);
        }
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre'          => 'Producto de prueba',
            'categoria_id'    => 1,
            'precio_base'     => 100.0,
            'iva_tasa'        => 16.0,
            'unidad_venta'    => 'caja',
            'contenido'       => '12 piezas',
            'peso_kg'         => 10.0,
            'volumen_m3'      => 0.01,
            'cantidad_minima' => 1,
            'multiplo_venta'  => 1,
            'stock_inicial'   => 10,
            'activo'          => true,
        ], $cambios);
    }

    public function test_crea_un_producto_valido(): void
    {
        $r = $this->servicio->crear($this->usuarioId, $this->datos(), '127.0.0.1');

        $this->assertTrue($r['ok'], $r['mensaje']);
        $this->assertNotNull($r['id']);
    }

    public function test_rechaza_precio_cero(): void
    {
        $r = $this->servicio->crear($this->usuarioId, $this->datos(['precio_base' => 0]), '127.0.0.1');

        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('precio_base', $r['errores']);
    }

    public function test_rechaza_peso_negativo(): void
    {
        $r = $this->servicio->crear($this->usuarioId, $this->datos(['peso_kg' => -1]), '127.0.0.1');

        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('peso_kg', $r['errores']);
    }

    public function test_rechaza_minimo_que_no_es_multiplo(): void
    {
        $r = $this->servicio->crear($this->usuarioId, $this->datos(['cantidad_minima' => 5, 'multiplo_venta' => 6]), '127.0.0.1');

        $this->assertFalse($r['ok']);
        $this->assertArrayHasKey('cantidad_minima', $r['errores']);
    }

    public function test_el_stock_inicial_queda_como_movimiento(): void
    {
        $r = $this->servicio->crear($this->usuarioId, $this->datos(['stock_inicial' => 25]), '127.0.0.1');
        $producto = $this->repo->buscarPorUuid(
            (string) $this->pdo->query('SELECT uuid FROM productos WHERE id = ' . (int) $r['id'])->fetchColumn()
        );

        $this->assertSame(25, (int) $producto['stock_disponible']);
        $this->assertCount(1, $this->repo->movimientos((int) $r['id']));
    }

    public function test_no_deja_el_stock_en_negativo(): void
    {
        $r = $this->servicio->crear($this->usuarioId, $this->datos(['stock_inicial' => 5]), '127.0.0.1');

        $ok = $this->repo->ajustarStock((int) $r['id'], -100, 'ajuste', $this->usuarioId, 'prueba');

        $this->assertFalse($ok);
        $producto = $this->pdo->query('SELECT stock_disponible FROM productos WHERE id = ' . (int) $r['id'])->fetch();
        $this->assertSame(5, (int) $producto['stock_disponible']);
    }

    public function test_un_mayorista_sin_kyc_no_publica(): void
    {
        $this->pdo->prepare("UPDATE mayoristas_perfil SET estado_kyc = 'pendiente', activo = 0 WHERE id = ?")
            ->execute([$this->mayoristaId]);

        $r = $this->servicio->crear($this->usuarioId, $this->datos(['activo' => true]), '127.0.0.1');
        $producto = $this->pdo->query('SELECT activo FROM productos WHERE id = ' . (int) $r['id'])->fetch();

        $this->assertSame(0, (int) $producto['activo']);
    }
}
