<?php

declare(strict_types=1);

namespace App\Modules\Logistica;

use PDO;

/**
 * Pruebas de recolección y entrega con PIN (RN-08).
 *
 * El PIN nunca se guarda en claro: se almacena con password_hash BCRYPT y se
 * compara con password_verify. Así, aunque alguien lea la base, no puede
 * usarlo para cerrar una entrega.
 */
final class PruebaEntregaService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** PIN de 6 dígitos que el repartidor pide al mayorista al recoger. */
    public function generarRecoleccion(int $pedidoId): string
    {
        $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($pin, PASSWORD_BCRYPT);

        // El envío puede no existir todavía: el mayorista marca "listo" antes
        // de buscar repartidor. Con UPDATE simple el PIN se perdía en silencio.
        $this->pdo->prepare(
            "INSERT INTO envios (pedido_id, codigo_recoleccion_hash, estado)
             VALUES (?, ?, 'pendiente')
             ON DUPLICATE KEY UPDATE codigo_recoleccion_hash = VALUES(codigo_recoleccion_hash)"
        )->execute([$pedidoId, $hash]);

        return $pin;
    }

    public function validarRecoleccion(int $pedidoId, string $pin): bool
    {
        $stmt = $this->pdo->prepare('SELECT codigo_recoleccion_hash FROM envios WHERE pedido_id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $hash = $stmt->fetchColumn();

        if ($hash === false || $hash === null || $hash === '') {
            return false;
        }

        return password_verify($pin, (string) $hash);
    }

    /** PIN de 4 dígitos que el comercio dicta al repartidor al recibir. */
    public function generarEntrega(int $pedidoId): string
    {
        $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        $hash = password_hash($pin, PASSWORD_BCRYPT);

        $this->pdo->prepare('UPDATE pedidos SET pin_entrega_hash = ?, pin_entrega_visible = ? WHERE id = ?')
            ->execute([$hash, $pin, $pedidoId]);

        return $pin;
    }

    public function validarEntrega(int $pedidoId, string $pin): bool
    {
        $stmt = $this->pdo->prepare('SELECT pin_entrega_hash FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $hash = $stmt->fetchColumn();

        if ($hash === false || $hash === null || $hash === '') {
            return false;
        }

        return password_verify($pin, (string) $hash);
    }

    /** PIN de entrega en claro, solo para mostrarlo una vez al comercio. */
    public function pinEntregaVisible(int $pedidoId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT pin_entrega_visible FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $pin = $stmt->fetchColumn();

        return $pin === false || $pin === null ? null : (string) $pin;
    }
}
