<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use App\Core\Cache;
use App\Modules\Busqueda\BusquedaRepository;
use App\Modules\Busqueda\BusquedaService;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class BusquedaTest extends TestCase
{
    private PDO $pdo;
    private BusquedaService $servicio;
    private int $usuarioId;
    private int $mayoristaId;
    private int $productoId;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::conexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }

        $cacheDir = sys_get_temp_dir() . '/didi2-cache-' . bin2hex(random_bytes(4));
        $this->servicio = new BusquedaService(
            new BusquedaRepository($this->pdo),
            new Cache($cacheDir, 30),
            $this->pdo,
        );

        // Zona de cobertura que cubre la CDMX.
        $this->pdo->prepare('DELETE FROM zonas_cobertura WHERE nombre = ?')->execute(['Zona de prueba']);
        $this->pdo->prepare('INSERT INTO zonas_cobertura (nombre, activa, poligono) VALUES (?, 1, ?)')
            ->execute(['Zona de prueba', json_encode([
                [19.60, -99.40], [19.60, -98.90], [19.20, -98.90], [19.20, -99.40], [19.60, -99.40],
            ])]);

        $email = 'test-busqueda-' . bin2hex(random_bytes(4)) . '@didi2.mx';
        $this->pdo->prepare(
            "INSERT INTO usuarios (uuid, email, password_hash, telefono, estado) VALUES (UUID(), ?, 'x', '5500000000', 'activo')"
        )->execute([$email]);
        $this->usuarioId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO mayoristas_perfil
             (uuid, usuario_id, nombre_comercial, categoria_giro, latitud, longitud, estado_kyc, reputacion, activo)
             VALUES (UUID(), ?, 'Mayorista de prueba', 'abarrotes', 19.4326, -99.1332, 'aprobado', 4.5, 1)"
        )->execute([$this->usuarioId]);
        $this->mayoristaId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO productos
             (uuid, mayorista_id, categoria_id, nombre, descripcion, precio_base, iva_tasa,
              unidad_venta, contenido, peso_kg, volumen_m3, cantidad_minima, multiplo_venta,
              stock_disponible, activo)
             VALUES (UUID(), ?, 1, 'Café de prueba', 'Café tostado de altura', 100.00, 16.00,
                     'caja', '12 piezas', 10, 0.01, 1, 1, 50, 1)"
        )->execute([$this->mayoristaId]);
        $this->productoId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->prepare('DELETE FROM productos WHERE mayorista_id = ?')->execute([$this->mayoristaId]);
            $this->pdo->prepare('DELETE FROM mayoristas_perfil WHERE id = ?')->execute([$this->mayoristaId]);
            $this->pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$this->usuarioId]);
            $this->pdo->prepare('DELETE FROM zonas_cobertura WHERE nombre = ?')->execute(['Zona de prueba']);
        }
    }

    private function buscar(array $extra = []): array
    {
        return $this->servicio->buscar(array_merge([
            'lat' => 19.4326, 'lng' => -99.1332, 'radio' => 10,
        ], $extra));
    }

    public function test_encuentra_el_producto_cercano(): void
    {
        $r = $this->buscar();

        $this->assertTrue($r['ok']);
        $nombres = array_column($r['data']['resultados'], 'nombre');
        $this->assertContains('Café de prueba', $nombres);
    }

    public function test_fuera_de_cobertura_no_devuelve_datos(): void
    {
        $r = $this->servicio->buscar(['lat' => 20.6597, 'lng' => -103.3496]);

        $this->assertFalse($r['ok']);
        $this->assertSame('FUERA_DE_COBERTURA', $r['error']['code']);
        $this->assertArrayNotHasKey('data', $r);
    }

    public function test_busqueda_por_texto(): void
    {
        $r = $this->buscar(['q' => 'cafe']);

        $this->assertTrue($r['ok']);
        $this->assertContains('Café de prueba', array_column($r['data']['resultados'], 'nombre'));
    }

    public function test_texto_que_no_existe_no_devuelve_nada(): void
    {
        $r = $this->buscar(['q' => 'zzzznoexiste']);

        $this->assertTrue($r['ok']);
        $this->assertNotContains('Café de prueba', array_column($r['data']['resultados'], 'nombre'));
    }

    public function test_producto_agotado_no_aparece(): void
    {
        $this->pdo->prepare('UPDATE productos SET stock_disponible = 0 WHERE id = ?')->execute([$this->productoId]);

        $r = $this->buscar();

        $this->assertNotContains('Café de prueba', array_column($r['data']['resultados'], 'nombre'));
    }

    public function test_mayorista_sin_kyc_no_aparece(): void
    {
        $this->pdo->prepare("UPDATE mayoristas_perfil SET estado_kyc = 'pendiente' WHERE id = ?")->execute([$this->mayoristaId]);

        $r = $this->buscar();

        $this->assertNotContains('Café de prueba', array_column($r['data']['resultados'], 'nombre'));
    }

    public function test_mayorista_inactivo_no_aparece(): void
    {
        $this->pdo->prepare('UPDATE mayoristas_perfil SET activo = 0 WHERE id = ?')->execute([$this->mayoristaId]);

        $r = $this->buscar();

        $this->assertNotContains('Café de prueba', array_column($r['data']['resultados'], 'nombre'));
    }

    public function test_radio_pequeno_excluye_lejanos(): void
    {
        // El mayorista está en el centro; a 1 km de un punto a 5 km no debe salir.
        $r = $this->servicio->buscar(['lat' => 19.4776, 'lng' => -99.1332, 'radio' => 1]);

        $this->assertTrue($r['ok']);
        $this->assertNotContains('Café de prueba', array_column($r['data']['resultados'], 'nombre'));
    }

    public function test_la_segunda_busqueda_igual_viene_del_cache(): void
    {
        $primera = $this->buscar(['q' => 'cafe']);
        $segunda = $this->buscar(['q' => 'cafe']);

        $this->assertFalse($primera['data']['cacheado']);
        $this->assertTrue($segunda['data']['cacheado']);
    }

    public function test_paginacion_no_repite_resultados(): void
    {
        $p1 = $this->buscar(['per_page' => 1, 'page' => 1]);
        $p2 = $this->buscar(['per_page' => 1, 'page' => 2]);

        $uuids1 = array_column($p1['data']['resultados'], 'uuid');
        $uuids2 = array_column($p2['data']['resultados'], 'uuid');

        $this->assertSame([], array_intersect($uuids1, $uuids2));
    }

    public function test_orden_por_precio_es_ascendente(): void
    {
        $r = $this->buscar(['orden' => 'precio_asc', 'per_page' => 50]);
        $precios = array_column($r['data']['resultados'], 'precio_base');

        $ordenados = $precios;
        sort($ordenados);

        $this->assertSame($ordenados, $precios);
    }

    public function test_orden_invalido_cae_en_distancia(): void
    {
        $r = $this->buscar(['orden' => 'DROP TABLE productos']);

        $this->assertTrue($r['ok']);
        $this->assertSame('distancia', $r['data']['orden']);
    }

    public function test_radio_se_limita_a_30_km(): void
    {
        $r = $this->buscar(['radio' => 500]);

        $this->assertTrue($r['ok']);
        $this->assertSame(30.0, $r['data']['radio']);
    }

    public function test_per_page_se_limita_a_50(): void
    {
        $r = $this->buscar(['per_page' => 500]);

        $this->assertTrue($r['ok']);
        $this->assertSame(50, $r['data']['per_page']);
    }

    public function test_sin_ubicacion_devuelve_error(): void
    {
        $r = $this->servicio->buscar([]);

        $this->assertFalse($r['ok']);
        $this->assertSame('SIN_UBICACION', $r['error']['code']);
    }
}
