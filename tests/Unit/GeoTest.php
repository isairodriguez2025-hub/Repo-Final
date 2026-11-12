<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Geo;
use PHPUnit\Framework\TestCase;

final class GeoTest extends TestCase
{
    public function test_distancia_entre_dos_puntos_conocidos(): void
    {
        // Zócalo de CDMX a Bellas Artes: unos 2 km en línea recta.
        $d = Geo::distanciaKm(19.4326, -99.1332, 19.4352, -99.1412);

        $this->assertGreaterThan(0.7, $d);
        $this->assertLessThan(1.2, $d);
    }

    public function test_distancia_cero_al_mismo_punto(): void
    {
        $this->assertSame(0.0, Geo::distanciaKm(19.4326, -99.1332, 19.4326, -99.1332));
    }

    public function test_punto_dentro_del_poligono(): void
    {
        $cuadrado = [[19.40, -99.15], [19.40, -99.10], [19.45, -99.10], [19.45, -99.15]];

        $this->assertTrue(Geo::puntoEnPoligono(19.42, -99.12, $cuadrado));
    }

    public function test_punto_fuera_del_poligono(): void
    {
        $cuadrado = [[19.40, -99.15], [19.40, -99.10], [19.45, -99.10], [19.45, -99.15]];

        $this->assertFalse(Geo::puntoEnPoligono(19.50, -99.12, $cuadrado));
        $this->assertFalse(Geo::puntoEnPoligono(19.42, -99.20, $cuadrado));
    }

    public function test_poligono_invalido_no_contiene_nada(): void
    {
        $this->assertFalse(Geo::puntoEnPoligono(19.42, -99.12, [[19.4, -99.1], [19.5, -99.2]]));
    }

    public function test_caja_envolvente_crece_con_el_radio(): void
    {
        $caja5 = Geo::cajaEnvolvente(19.4326, -99.1332, 5);
        $caja10 = Geo::cajaEnvolvente(19.4326, -99.1332, 10);

        $this->assertLessThan($caja5['lat_min'], $caja10['lat_min']);
        $this->assertGreaterThan($caja5['lat_max'], $caja10['lat_max']);
    }
}
