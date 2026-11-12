<?php

declare(strict_types=1);

namespace App\Modules\Favoritos;

use App\Modules\Carrito\CarritoService;
use PDO;

/**
 * Favoritos y recompra en un clic (RF-C07, RF-C08).
 *
 * La recompra nunca agrega a ciegas: vuelve a leer precio y stock, y si algo
 * se agotó avisa en lugar de meterlo al carrito.
 */
final class FavoritoService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CarritoService $carrito,
    ) {
    }

    public function agregar(int $comercioId, string $productoUuid, string $carpeta = 'General'): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM productos WHERE uuid = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$productoUuid]);
        $productoId = $stmt->fetchColumn();

        if ($productoId === false) {
            return ['ok' => false, 'mensaje' => 'El producto no existe.'];
        }

        $this->pdo->prepare(
            "INSERT INTO favoritos (comercio_id, producto_id, carpeta) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE carpeta = VALUES(carpeta)"
        )->execute([$comercioId, (int) $productoId, mb_substr($carpeta !== '' ? $carpeta : 'General', 0, 60)]);

        return ['ok' => true, 'mensaje' => 'Guardado en favoritos.'];
    }

    public function quitar(int $comercioId, string $productoUuid): void
    {
        $this->pdo->prepare(
            'DELETE f FROM favoritos f JOIN productos p ON p.id = f.producto_id
             WHERE f.comercio_id = ? AND p.uuid = ?'
        )->execute([$comercioId, $productoUuid]);
    }

    public function listar(int $comercioId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.uuid, p.nombre, p.precio_base, p.iva_tasa, p.unidad_venta, p.contenido,
                    p.stock_disponible, p.activo, p.cantidad_minima, p.multiplo_venta,
                    f.carpeta, m.nombre_comercial AS mayorista, m.uuid AS mayorista_uuid,
                    c.nombre AS categoria
             FROM favoritos f
             JOIN productos p ON p.id = f.producto_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             JOIN categorias c ON c.id = p.categoria_id
             WHERE f.comercio_id = ? AND p.deleted_at IS NULL
             ORDER BY f.carpeta, p.nombre"
        );
        $stmt->execute([$comercioId]);

        return $stmt->fetchAll() ?: [];
    }

    public function esFavorito(int $comercioId, string $productoUuid): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM favoritos f JOIN productos p ON p.id = f.producto_id
             WHERE f.comercio_id = ? AND p.uuid = ? LIMIT 1'
        );
        $stmt->execute([$comercioId, $productoUuid]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Recompra en un clic (RF-C07).
     *
     * @return array{agregados:int, omitidos:array<int,array{nombre:string,motivo:string,alternativa:?string}>}
     */
    public function recomprar(int $comercioId, int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.cantidad, p.uuid, p.nombre, p.stock_disponible, p.categoria_id, p.activo, p.deleted_at
             FROM detalle_pedido d JOIN productos p ON p.id = d.producto_id
             WHERE d.pedido_id = ?'
        );
        $stmt->execute([$pedidoId]);

        $agregados = 0;
        $omitidos = [];

        foreach ($stmt->fetchAll() ?: [] as $linea) {
            $disponible = (int) $linea['stock_disponible'];
            $cantidad = (int) $linea['cantidad'];

            if ((int) $linea['activo'] !== 1 || $linea['deleted_at'] !== null || $disponible < $cantidad) {
                $omitidos[] = [
                    'nombre'      => (string) $linea['nombre'],
                    'motivo'      => $disponible === 0 ? 'agotado' : "solo quedan {$disponible}",
                    'alternativa' => $this->alternativa((int) $linea['categoria_id'], $cantidad, (string) $linea['uuid']),
                ];
                continue;
            }

            $r = $this->carrito->agregarItem($comercioId, (string) $linea['uuid'], $cantidad);

            if ($r['ok']) {
                $agregados++;
            } else {
                $omitidos[] = ['nombre' => (string) $linea['nombre'], 'motivo' => $r['mensaje'], 'alternativa' => null];
            }
        }

        return ['agregados' => $agregados, 'omitidos' => $omitidos];
    }

    private function alternativa(int $categoriaId, int $stockMinimo, string $uuidExcluido): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT nombre FROM productos
             WHERE categoria_id = ? AND uuid <> ? AND activo = 1 AND deleted_at IS NULL
               AND stock_disponible >= GREATEST(1, ?)
             ORDER BY stock_disponible DESC LIMIT 1'
        );
        $stmt->execute([$categoriaId, $uuidExcluido, $stockMinimo]);
        $nombre = $stmt->fetchColumn();

        return $nombre === false ? null : (string) $nombre;
    }

    /**
     * Sugerencias de reabastecimiento (regla simple del MVP).
     *
     * @return array<int, array{producto:string, dias:int}>
     */
    public function sugerencias(int $comercioId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT p.nombre, COUNT(DISTINCT pe.id) AS veces,
                    DATEDIFF(NOW(), MAX(pe.created_at)) AS dias_desde,
                    DATEDIFF(MAX(pe.created_at), MIN(pe.created_at)) AS rango
             FROM detalle_pedido d
             JOIN pedidos pe ON pe.id = d.pedido_id
             JOIN productos p ON p.id = d.producto_id
             WHERE pe.comercio_id = ? AND pe.estado IN ('entregado','completado')
             GROUP BY p.id, p.nombre
             HAVING veces >= 2 AND rango > 0
             ORDER BY dias_desde DESC
             LIMIT 5"
        );
        $stmt->execute([$comercioId]);

        $sugerencias = [];

        foreach ($stmt->fetchAll() ?: [] as $fila) {
            $veces = (int) $fila['veces'];
            $rango = (int) $fila['rango'];
            $dias = (int) $fila['dias_desde'];
            $frecuencia = max(1, (int) round($rango / max(1, $veces - 1)));

            if ($dias >= $frecuencia) {
                $sugerencias[] = ['producto' => (string) $fila['nombre'], 'dias' => $dias];
            }
        }

        return $sugerencias;
    }
}
