<?php

declare(strict_types=1);

namespace App\Modules\Logistica;

use PDO;

/**
 * Tarifa de envío (RN-07).
 *
 * tarifa = base + distancia × precio_km + peso × recargo_peso + espera × recargo_espera
 *
 * Los parámetros viven en `tarifas_logistica` por tipo de vehículo, así que
 * cambiar precios no requiere tocar código.
 */
final class TarifaService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Tarifa vigente para un tipo de vehículo. */
    public function tarifaVigente(string $tipoVehiculo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tarifas_logistica
             WHERE tipo_vehiculo = ? AND vigente_desde <= CURDATE()
               AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())
             ORDER BY vigente_desde DESC LIMIT 1'
        );
        $stmt->execute([$tipoVehiculo]);

        return $stmt->fetch() ?: null;
    }

    public function calcular(string $tipoVehiculo, float $distanciaKm, float $pesoKg, float $esperaMin = 0.0): float
    {
        $tarifa = $this->tarifaVigente($tipoVehiculo);

        if ($tarifa === null) {
            // Sin tarifa configurada se usa la de moto como respaldo razonable.
            $tarifa = $this->tarifaVigente('moto') ?? [
                'tarifa_base' => 35, 'precio_por_km' => 5, 'recargo_por_peso' => 0.5, 'recargo_espera' => 1,
            ];
        }

        $total = (float) $tarifa['tarifa_base']
            + $distanciaKm * (float) $tarifa['precio_por_km']
            + $pesoKg * (float) $tarifa['recargo_por_peso']
            + $esperaMin * (float) $tarifa['recargo_espera'];

        return round($total, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Distancia en km entre la bodega del mayorista y el repartidor.
     */
    public function distanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(2 * $r * asin(sqrt($a)), 2);
    }
}
