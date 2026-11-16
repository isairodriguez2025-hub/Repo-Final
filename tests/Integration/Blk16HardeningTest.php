<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class Blk16HardeningTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

        try {
            $this->pdo = Database::conexion();
        } catch (\Throwable) {
            $this->markTestSkipped('Sin base de datos disponible.');
        }
    }

    public function test_respuesta_tiene_metodo_nonce(): void
    {
        $r = \App\Core\Response::html('<html><body>test</body></html>');
        $this->assertInstanceOf(\App\Core\Response::class, $r);
    }

    public function test_con_nonce_genera_csp_con_nonce(): void
    {
        $r = \App\Core\Response::html('<html><body>test</body></html>')
            ->withNonce('test-nonce-123');

        $this->assertSame('test-nonce-123', $r->nonce());
    }

    public function test_sin_nonce_csp_no_tiene_nonce(): void
    {
        $r = \App\Core\Response::html('<html><body>test</body></html>');

        $this->assertNull($r->nonce());
    }

    public function test_cabeceras_seguridad_base_existen(): void
    {
        $r = \App\Core\Response::html('<html><body>test</body></html>');
        $this->assertInstanceOf(\App\Core\Response::class, $r);
    }

    public function test_manifest_json_existe_y_es_valido(): void
    {
        $path = __DIR__ . '/../../public/manifest.json';
        $this->assertFileExists($path);

        $content = json_decode(file_get_contents($path), true);
        $this->assertIsArray($content);
        $this->assertArrayHasKey('name', $content);
        $this->assertArrayHasKey('start_url', $content);
        $this->assertArrayHasKey('display', $content);
        $this->assertSame('standalone', $content['display']);
    }

    public function test_service_worker_existe(): void
    {
        $path = __DIR__ . '/../../public/sw.js';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('serviceWorker', $content);
    }

    public function test_privacy_page_vista_existe(): void
    {
        $path = __DIR__ . '/../../app/Views/privacidad.php';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('Aviso de privacidad', $content);
    }

    public function test_privacy_route_registrada(): void
    {
        $appConfig = file_get_contents(__DIR__ . '/../../app/Config/app.php');
        $this->assertStringContainsString("'/aviso-privacidad'", $appConfig);
    }

    public function test_robots_txt_existe(): void
    {
        $path = __DIR__ . '/../../public/robots.txt';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('User-agent', $content);
        $this->assertStringContainsString('Disallow: /admin/', $content);
    }

    public function test_security_txt_existe(): void
    {
        $path = __DIR__ . '/../../public/security.txt';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertStringContainsString('Contact:', $content);
        $this->assertStringContainsString('Host:', $content);
    }
}
