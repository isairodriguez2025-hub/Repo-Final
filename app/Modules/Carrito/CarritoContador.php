<?php

declare(strict_types=1);

namespace App\Modules\Carrito;

use App\Config\Database;
use PDO;

/**
 * Cuenta de artículos del carrito para el botón flotante.
 *
 * Se consulta en cada página, así que es una sola consulta indexada por
 * comercio_id (UNIQUE) y no toca la tabla de productos.
 */
final class CarritoContador
{
    public static function paraUsuario(int $usuarioId): int
    {
        try {
            $pdo = Database::conexion();

            $stmt = $pdo->prepare(
                'SELECT COALESCE(SUM(ci.cantidad), 0)
                 FROM carritos c
                 JOIN comercios_perfil cp ON cp.id = c.comercio_id
                 LEFT JOIN carrito_items ci ON ci.carrito_id = c.id
                 WHERE cp.usuario_id = ?'
            );
            $stmt->execute([$usuarioId]);

            return (int) $stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
