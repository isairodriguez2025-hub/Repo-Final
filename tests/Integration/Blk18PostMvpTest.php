<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

final class Blk18PostMvpTest extends TestCase
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

        // Limpiar datos de prueba Post-MVP
        $this->pdo->exec("DELETE FROM licitaciones_cotizaciones WHERE licitacion_id IN (SELECT id FROM licitaciones WHERE titulo LIKE 'test-%')");
        $this->pdo->exec("DELETE FROM licitaciones WHERE titulo LIKE 'test-%'");
        $this->pdo->exec("DELETE FROM bnpl_pagos WHERE bnpl_plan_id IN (SELECT id FROM bnpl_plan WHERE uuid LIKE 'test-%')");
        $this->pdo->exec("DELETE FROM bnpl_plan WHERE uuid LIKE 'test-%'");
        $this->pdo->exec("DELETE FROM consolidacion_fletes WHERE uuid LIKE 'test-%'");
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }

        $this->pdo->exec("DELETE FROM licitaciones_cotizaciones WHERE licitacion_id IN (SELECT id FROM licitaciones WHERE titulo LIKE 'test-%')");
        $this->pdo->exec("DELETE FROM licitaciones WHERE titulo LIKE 'test-%'");
        $this->pdo->exec("DELETE FROM bnpl_pagos WHERE bnpl_plan_id IN (SELECT id FROM bnpl_plan WHERE uuid LIKE 'test-%')");
        $this->pdo->exec("DELETE FROM bnpl_plan WHERE uuid LIKE 'test-%'");
        $this->pdo->exec("DELETE FROM consolidacion_fletes WHERE uuid LIKE 'test-%'");
    }

    public function test_dashboard_postmvp(): void
    {
        $ctrl = new \App\Modules\PostMvp\PostMvpController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->dashboard();

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Post-MVP', $r->body());
    }

    public function test_consolidaciones_vacia_por_defecto(): void
    {
        $ctrl = new \App\Modules\PostMvp\PostMvpController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->listarConsolidaciones(\App\Core\Request::fromGlobals());

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Consolidación de fletes', $r->body());
    }

    public function test_bnpl_vacia_por_defecto(): void
    {
        $ctrl = new \App\Modules\PostMvp\PostMvpController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->listarBnpl(\App\Core\Request::fromGlobals());

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Planes BNPL', $r->body());
    }

    public function test_licitaciones_vacia_por_defecto(): void
    {
        $ctrl = new \App\Modules\PostMvp\PostMvpController($this->pdo, __DIR__ . '/../../app/Views');
        $r = $ctrl->listarLicitaciones(\App\Core\Request::fromGlobals());

        $this->assertSame(200, $r->status());
        $this->assertStringContainsString('Licitaciones', $r->body());
    }

    public function test_migracion_consolidacion_fletes_tabla_exist(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'consolidacion_fletes'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_migracion_bnpl_plan_tabla_exist(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bnpl_plan'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_migracion_licitaciones_tabla_exist(): void
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'licitaciones'");
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function test_parametros_postmvp_existen(): void
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM parametros WHERE clave LIKE 'bnpl.%' OR clave LIKE 'licitacion.%' OR clave LIKE 'flete.%'");
        $stmt->execute();
        $this->assertGreaterThan(0, (int) $stmt->fetchColumn());
    }
}
