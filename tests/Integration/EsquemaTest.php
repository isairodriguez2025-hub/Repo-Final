<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\Database;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Reglas de esquema que el plan exige y que no se ven en el código.
 */
final class EsquemaTest extends TestCase
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

    public function test_ninguna_columna_monetaria_es_float_o_double(): void
    {
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN ('float','double')"
        );

        $malas = $stmt->fetchAll() ?: [];

        $this->assertSame(
            [],
            $malas,
            'Hay columnas FLOAT/DOUBLE: ' . json_encode($malas, JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_los_importes_son_decimal_12_2(): void
    {
        $stmt = $this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, NUMERIC_PRECISION, NUMERIC_SCALE
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND COLUMN_NAME IN ('precio_base','valor','subtotal','total','iva','precio_unitario')"
        );

        $columnas = $stmt->fetchAll() ?: [];

        $this->assertNotEmpty($columnas);

        foreach ($columnas as $c) {
            $this->assertSame(
                '12',
                (string) $c['NUMERIC_PRECISION'],
                "{$c['TABLE_NAME']}.{$c['COLUMN_NAME']} no es DECIMAL(12,2)"
            );
            $this->assertSame('2', (string) $c['NUMERIC_SCALE']);
        }
    }

    public function test_la_escala_no_permite_duplicados_por_producto(): void
    {
        $stmt = $this->pdo->query("SHOW INDEX FROM escala_descuentos WHERE Key_name = 'uq_escala'");
        $indice = $stmt->fetchAll() ?: [];

        $this->assertCount(2, $indice, 'uq_escala debe cubrir (producto_id, cantidad_minima)');
        $this->assertSame('0', (string) $indice[0]['Non_unique']);
    }
}
