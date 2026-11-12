<?php

declare(strict_types=1);

namespace App\Modules\Finanzas;

use PDO;

/**
 * Paneles financieros por rol (RF-C09, RF-M07, RF-R06).
 *
 * Todos los totales salen de pedidos `completados` en el rango pedido, así que
 * el panel y la exportación siempre cuadran con la base.
 */
final class FinanzasService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** Gasto del comercio por período y categoría. */
    public function comercio(int $comercioId, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS pedidos, COALESCE(SUM(p.total),0) AS total,
                    COALESCE(SUM(p.subtotal),0) AS subtotal, COALESCE(SUM(p.iva),0) AS iva
             FROM pedidos p
             WHERE p.comercio_id = ? AND p.estado = 'completado' AND DATE(p.created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$comercioId, $desde, $hasta]);
        $resumen = $stmt->fetch() ?: ['pedidos' => 0, 'total' => 0, 'subtotal' => 0, 'iva' => 0];

        $stmt = $this->pdo->prepare(
            "SELECT c.nombre AS categoria, COALESCE(SUM(d.subtotal),0) AS monto
             FROM detalle_pedido d
             JOIN pedidos p ON p.id = d.pedido_id
             JOIN productos pr ON pr.id = d.producto_id
             JOIN categorias c ON c.id = pr.categoria_id
             WHERE p.comercio_id = ? AND p.estado = 'completado' AND DATE(p.created_at) BETWEEN ? AND ?
             GROUP BY c.nombre ORDER BY monto DESC"
        );
        $stmt->execute([$comercioId, $desde, $hasta]);

        return ['resumen' => $resumen, 'por_categoria' => $stmt->fetchAll() ?: []];
    }

    /** Ventas del mayorista con la comisión ya descontada. */
    public function mayorista(int $mayoristaId, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS pedidos, COALESCE(SUM(p.subtotal),0) AS subtotal,
                    COALESCE(SUM(p.iva),0) AS iva, COALESCE(SUM(p.total),0) AS total
             FROM pedidos p
             WHERE p.mayorista_id = ? AND p.estado = 'completado' AND DATE(p.created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$mayoristaId, $desde, $hasta]);
        $resumen = $stmt->fetch() ?: ['pedidos' => 0, 'subtotal' => 0, 'iva' => 0, 'total' => 0];

        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(monto),0) FROM comisiones
             WHERE tipo = 'mayorista' AND pedido_id IN (
                SELECT id FROM pedidos WHERE mayorista_id = ? AND estado = 'completado' AND DATE(created_at) BETWEEN ? AND ?
             )"
        );
        $stmt->execute([$mayoristaId, $desde, $hasta]);
        $comision = (float) $stmt->fetchColumn();

        $resumen['comision'] = round($comision, 2);
        $resumen['neto'] = round((float) $resumen['subtotal'] + (float) $resumen['iva'] - $comision, 2);

        return ['resumen' => $resumen];
    }

    /** Ganancias del repartidor por viaje. */
    public function repartidor(int $repartidorId, string $desde, string $hasta): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS viajes, COALESCE(SUM(e.tarifa),0) AS total
             FROM envios e JOIN pedidos p ON p.id = e.pedido_id
             WHERE e.repartidor_id = ? AND p.estado = 'completado' AND DATE(p.created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([$repartidorId, $desde, $hasta]);
        $resumen = $stmt->fetch() ?: ['viajes' => 0, 'total' => 0];

        $stmt = $this->pdo->prepare(
            "SELECT p.uuid, DATE(p.created_at) AS fecha, e.tarifa, e.distancia_km
             FROM envios e JOIN pedidos p ON p.id = e.pedido_id
             WHERE e.repartidor_id = ? AND p.estado = 'completado' AND DATE(p.created_at) BETWEEN ? AND ?
             ORDER BY p.created_at DESC"
        );
        $stmt->execute([$repartidorId, $desde, $hasta]);

        return ['resumen' => $resumen, 'viajes' => $stmt->fetchAll() ?: []];
    }

    /**
     * CSV de pedidos del comercio. Solo sus propios datos.
     */
    public function csvComercio(int $comercioId, string $desde, string $hasta): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.uuid, DATE(p.created_at) AS fecha, m.nombre_comercial AS mayorista,
                    p.estado, p.subtotal, p.iva, p.envio, p.total
             FROM pedidos p JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             WHERE p.comercio_id = ? AND DATE(p.created_at) BETWEEN ? AND ?
             ORDER BY p.created_at"
        );
        $stmt->execute([$comercioId, $desde, $hasta]);

        $salida = fopen('php://temp', 'r+');
        fputcsv($salida, ['pedido', 'fecha', 'mayorista', 'estado', 'subtotal', 'iva', 'envio', 'total'], ',', '"', '');

        foreach ($stmt->fetchAll() ?: [] as $fila) {
            fputcsv($salida, array_values($fila), ',', '"', '');
        }

        rewind($salida);
        $csv = (string) stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }
}
