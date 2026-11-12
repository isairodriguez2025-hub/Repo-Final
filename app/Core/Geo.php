<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Cálculos geográficos sin dependencias externas.
 */
final class Geo
{
    private const RADIO_TIERRA_KM = 6371.0;

    /** Distancia en kilómetros entre dos coordenadas (Haversine). */
    public static function distanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::RADIO_TIERRA_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * ¿El punto cae dentro del polígono? Algoritmo de ray casting.
     *
     * @param array<int, array{0: float, 1: float}> $poligono [[lat, lng], ...]
     */
    public static function puntoEnPoligono(float $lat, float $lng, array $poligono): bool
    {
        $n = count($poligono);

        if ($n < 3) {
            return false;
        }

        $dentro = false;

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$latI, $lngI] = $poligono[$i];
            [$latJ, $lngJ] = $poligono[$j];

            $cruza = (($lngI > $lng) !== ($lngJ > $lng))
                && ($lat < ($latJ - $latI) * ($lng - $lngI) / (($lngJ - $lngI) ?: 1e-12) + $latI);

            if ($cruza) {
                $dentro = !$dentro;
            }
        }

        return $dentro;
    }

    /** Caja envolvente para filtrar por índice antes de calcular distancias. */
    public static function cajaEnvolvente(float $lat, float $lng, float $radioKm): array
    {
        $dLat = $radioKm / 111.0;
        $dLng = $radioKm / (111.0 * max(0.01, cos(deg2rad($lat))));

        return [
            'lat_min' => $lat - $dLat,
            'lat_max' => $lat + $dLat,
            'lng_min' => $lng - $dLng,
            'lng_max' => $lng + $dLng,
        ];
    }
}
