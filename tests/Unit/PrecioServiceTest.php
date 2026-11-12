<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Catalogo\Excepciones\EscalaInvalidaException;
use App\Modules\Catalogo\Excepciones\EscalaNoAplicaException;
use App\Modules\Catalogo\PrecioService;
use PHPUnit\Framework\TestCase;

/**
 * Casos del plan (BLK-05), con los números exactos que ahí se piden.
 */
final class PrecioServiceTest extends TestCase
{
    private PrecioService $precio;

    protected function setUp(): void
    {
        $this->precio = new PrecioService();
    }

    private function producto(array $cambios = []): array
    {
        return array_merge([
            'precio_base'     => 100.00,
            'iva_tasa'        => 16.00,
            'cantidad_minima' => 1,
            'multiplo_venta'  => 1,
        ], $cambios);
    }

    // --- Casos del plan ----------------------------------------------------

    public function test_sin_escala_100_mas_iva_16_en_cantidad_10(): void
    {
        $r = $this->precio->calcularLinea($this->producto(), 10);

        $this->assertSame(100.00, $r['precio_unitario']);
        $this->assertSame(16.00, $r['iva']);
        $this->assertSame(116.00, $r['precio_con_iva']);
        // El plan dice "subtotal 10 unidades = 1160", pero en su segundo caso
        // usa subtotal sin IVA (630) y total con IVA (730.80). Se sigue la
        // definición coherente: subtotal sin IVA, total con IVA.
        $this->assertSame(1000.00, $r['subtotal']);
        $this->assertSame(160.00, $r['iva_total']);
        $this->assertSame(1160.00, $r['total']);
        $this->assertNull($r['escala_aplicada']);
    }

    public function test_escala_10_por_ciento_desde_5_en_cantidad_7(): void
    {
        $escalas = [['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 10]];

        $r = $this->precio->calcularLinea($this->producto(), 7, $escalas);

        $this->assertSame(90.00, $r['precio_unitario']);
        $this->assertSame(14.40, $r['iva']);
        $this->assertSame(630.00, $r['subtotal']);
        $this->assertSame(100.80, $r['iva_total']);
        $this->assertSame(730.80, $r['total']);
        $this->assertSame('Desde 5 pzas: -10%', $r['escala_aplicada']);
    }

    public function test_rechaza_dos_escalas_con_la_misma_cantidad_minima(): void
    {
        $this->expectException(EscalaInvalidaException::class);

        $this->precio->validarEscalas([
            ['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 10],
            ['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 15],
        ]);
    }

    public function test_rechaza_escalas_fuera_de_orden(): void
    {
        $this->expectException(EscalaInvalidaException::class);

        $this->precio->validarEscalas([
            ['cantidad_minima' => 10, 'tipo' => 'porcentaje', 'valor' => 5],
            ['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 10],
            ['cantidad_minima' => 20, 'tipo' => 'porcentaje', 'valor' => 15],
        ]);
    }

    public function test_acepta_escalas_crecientes(): void
    {
        $this->precio->validarEscalas([
            ['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 10],
            ['cantidad_minima' => 10, 'tipo' => 'porcentaje', 'valor' => 15],
            ['cantidad_minima' => 20, 'tipo' => 'precio_fijo', 'valor' => 80],
        ]);

        $this->assertTrue(true);
    }

    // --- Reglas adicionales ------------------------------------------------

    public function test_escala_de_precio_fijo_ignora_el_porcentaje(): void
    {
        $escalas = [['cantidad_minima' => 10, 'tipo' => 'precio_fijo', 'valor' => 75.50]];

        $r = $this->precio->calcularPrecioUnitario($this->producto(), 12, $escalas);

        $this->assertSame(75.50, $r['precio_unitario']);
        $this->assertSame(12.08, $r['iva']);
    }

    public function test_toma_la_escala_mas_alta_que_alcanza(): void
    {
        $escalas = [
            ['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 10],
            ['cantidad_minima' => 20, 'tipo' => 'porcentaje', 'valor' => 25],
        ];

        $this->assertSame(90.00, $this->precio->calcularPrecioUnitario($this->producto(), 10, $escalas)['precio_unitario']);
        $this->assertSame(75.00, $this->precio->calcularPrecioUnitario($this->producto(), 25, $escalas)['precio_unitario']);
    }

    public function test_iva_cero_no_agrega_impuesto(): void
    {
        $r = $this->precio->calcularLinea($this->producto(['iva_tasa' => 0]), 3);

        $this->assertSame(0.00, $r['iva']);
        $this->assertSame(300.00, $r['total']);
    }

    public function test_redondeo_comercial_half_up(): void
    {
        // 33.33 * 1.16 = 38.6628 → 38.66 ; 0.005 debe subir
        $r = $this->precio->calcularPrecioUnitario($this->producto(['precio_base' => 33.33]), 1);

        $this->assertSame(5.33, $r['iva']);
        $this->assertSame(38.66, $r['precio_con_iva']);
        $this->assertSame(0.01, $this->precio->redondear(0.005));
    }

    public function test_rechaza_cantidad_menor_al_minimo(): void
    {
        $this->expectException(EscalaNoAplicaException::class);

        $this->precio->calcularPrecioUnitario($this->producto(['cantidad_minima' => 5]), 3);
    }

    public function test_rechaza_cantidad_que_no_es_multiplo(): void
    {
        $this->expectException(EscalaNoAplicaException::class);

        $this->precio->calcularPrecioUnitario($this->producto(['multiplo_venta' => 6]), 7);
    }

    public function test_acepta_multiplo_exacto(): void
    {
        $r = $this->precio->calcularPrecioUnitario($this->producto(['multiplo_venta' => 6]), 12);

        $this->assertSame(100.00, $r['precio_unitario']);
    }

    public function test_rechaza_porcentaje_de_100_o_mas(): void
    {
        $this->expectException(EscalaInvalidaException::class);

        $this->precio->validarEscalas([
            ['cantidad_minima' => 5, 'tipo' => 'porcentaje', 'valor' => 100],
        ]);
    }

    public function test_rechaza_valor_cero(): void
    {
        $this->expectException(EscalaInvalidaException::class);

        $this->precio->validarEscalas([
            ['cantidad_minima' => 5, 'tipo' => 'precio_fijo', 'valor' => 0],
        ]);
    }
}
