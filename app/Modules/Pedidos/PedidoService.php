<?php

declare(strict_types=1);

namespace App\Modules\Pedidos;

use PDO;

/**
 * Máquina de estados del pedido (RN-05).
 *
 * Es el ÚNICO lugar donde `pedidos.estado` cambia. Cualquier otro código que
 * quiera mover un pedido tiene que pasar por `transicionar()`, y eso garantiza
 * que se valide la transición, se registre el evento y se ajuste el stock.
 */
final class PedidoService
{
    /** estado actual => [estados destino => actores permitidos] */
    public const TRANSICIONES = [
        'borrador' => [
            'pendiente_pago' => ['comercio'],
            'cancelado'      => ['comercio'],
        ],
        'pendiente_pago' => [
            'pago_autorizado' => ['pasarela', 'sistema'],
            'expirado'        => ['worker', 'sistema'],
        ],
        'pago_autorizado' => [
            'pendiente_aceptacion' => ['sistema'],
        ],
        'pendiente_aceptacion' => [
            'aceptado'  => ['mayorista'],
            'rechazado' => ['mayorista'],
            'expirado'  => ['worker', 'sistema'],
        ],
        'aceptado' => [
            'preparando' => ['mayorista'],
            'cancelado'  => ['mayorista'],
        ],
        'preparando' => [
            'listo_para_recoger' => ['mayorista'],
        ],
        'listo_para_recoger' => [
            'buscando_repartidor' => ['mayorista', 'sistema'],
        ],
        'buscando_repartidor' => [
            'repartidor_asignado' => ['sistema'],
            'cancelado'           => ['sistema', 'admin_soporte'],
        ],
        'repartidor_asignado' => [
            'en_recoleccion'      => ['repartidor'],
            'buscando_repartidor' => ['repartidor', 'sistema'],
        ],
        'en_recoleccion' => [
            'en_camino'       => ['repartidor'],
            'entrega_fallida' => ['repartidor'],
        ],
        'en_camino' => [
            'en_puerta'       => ['repartidor'],
            'entrega_fallida' => ['repartidor'],
        ],
        'en_puerta' => [
            'entregado'       => ['repartidor'],
            'entrega_fallida' => ['repartidor'],
        ],
        'entregado' => [
            'completado' => ['sistema', 'comercio'],
            'en_disputa' => ['comercio'],
        ],
        'completado' => [],
        'en_disputa' => [
            'completado'  => ['admin_soporte'],
            'reembolsado' => ['admin_soporte'],
        ],
        'cancelado'   => [],
        'rechazado'   => [],
        'expirado'    => [],
        'reembolsado' => [],
        'entrega_fallida' => [
            'buscando_repartidor' => ['sistema', 'admin_soporte'],
            'cancelado'           => ['admin_soporte'],
        ],
    ];

    /** Estados en los que el stock deja de estar reservado y vuelve al catálogo. */
    private const LIBERAN_STOCK = ['cancelado', 'rechazado', 'expirado', 'reembolsado'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param string[] $rolesDelActor roles del usuario que ejecuta la acción
     * @return array{ok:bool, mensaje:string, estado?:string}
     */
    public function transicionar(
        int $pedidoId,
        string $estadoNuevo,
        ?int $actorId,
        array $rolesDelActor = [],
        ?string $nota = null,
    ): array {
        $stmt = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();

        if ($pedido === false) {
            return ['ok' => false, 'mensaje' => 'El pedido no existe.'];
        }

        $actual = (string) $pedido['estado'];
        $permitidos = self::TRANSICIONES[$actual] ?? [];

        if (!isset($permitidos[$estadoNuevo])) {
            return ['ok' => false, 'mensaje' => "No se puede pasar de '{$actual}' a '{$estadoNuevo}'."];
        }

        $actores = $permitidos[$estadoNuevo];

        if ($actorId !== null && !array_intersect($actores, $rolesDelActor) && !array_intersect($actores, ['sistema'])) {
            return ['ok' => false, 'mensaje' => 'Tu rol no puede hacer esta transición.'];
        }

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare('UPDATE pedidos SET estado = ? WHERE id = ? AND estado = ?')
                ->execute([$estadoNuevo, $pedidoId, $actual]);

            $this->pdo->prepare(
                'INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, actor_usuario_id, nota)
                 VALUES (?,?,?,?,?)'
            )->execute([$pedidoId, $actual, $estadoNuevo, $actorId, $nota]);

            if (in_array($estadoNuevo, self::LIBERAN_STOCK, true)) {
                $this->liberarStock($pedidoId);
            }

            if ($estadoNuevo === 'aceptado') {
                $this->consumirReservas($pedidoId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['ok' => true, 'mensaje' => 'Pedido actualizado.', 'estado' => $estadoNuevo];
    }

    /** Devuelve al catálogo el stock que estaba reservado. */
    private function liberarStock(int $pedidoId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT producto_id, cantidad FROM inventario_reservas WHERE pedido_id = ? AND estado = 'activa'"
        );
        $stmt->execute([$pedidoId]);

        foreach ($stmt->fetchAll() ?: [] as $reserva) {
            $this->pdo->prepare(
                'UPDATE productos SET stock_disponible = stock_disponible + ?, stock_reservado = GREATEST(stock_reservado - ?, 0)
                 WHERE id = ?'
            )->execute([(int) $reserva['cantidad'], (int) $reserva['cantidad'], (int) $reserva['producto_id']]);

            $this->pdo->prepare(
                "INSERT INTO inventario_movimientos
                 (producto_id, tipo, cantidad, cantidad_previa, cantidad_nueva, motivo)
                 SELECT id, 'liberacion', ?, stock_disponible - ?, stock_disponible, 'Reserva liberada'
                 FROM productos WHERE id = ?"
            )->execute([(int) $reserva['cantidad'], (int) $reserva['cantidad'], (int) $reserva['producto_id']]);
        }

        $this->pdo->prepare("UPDATE inventario_reservas SET estado = 'liberada' WHERE pedido_id = ? AND estado = 'activa'")
            ->execute([$pedidoId]);
    }

    /** La reserva se vuelve venta: el stock ya no vuelve. */
    private function consumirReservas(int $pedidoId): void
    {
        $this->pdo->prepare("UPDATE inventario_reservas SET estado = 'consumida' WHERE pedido_id = ? AND estado = 'activa'")
            ->execute([$pedidoId]);
    }

    /** Libera las reservas vencidas (lo llama el worker). */
    public function expirarReservasVencidas(): int
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT pedido_id FROM inventario_reservas
             WHERE estado = 'activa' AND expira_en < NOW()"
        );
        $pedidos = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $liberados = 0;

        foreach ($pedidos as $pedidoId) {
            $this->pdo->beginTransaction();

            try {
                $this->liberarStock((int) $pedidoId);
                $this->pdo->prepare(
                    "INSERT INTO pedido_eventos (pedido_id, estado_anterior, estado_nuevo, nota)
                     SELECT id, estado, 'expirado', 'Reserva de stock vencida' FROM pedidos WHERE id = ?"
                )->execute([(int) $pedidoId]);
                $this->pdo->prepare("UPDATE pedidos SET estado = 'expirado' WHERE id = ? AND estado IN ('borrador','pendiente_pago')")
                    ->execute([(int) $pedidoId]);

                $this->pdo->commit();
                $liberados++;
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }
        }

        return $liberados;
    }

    public function pedido(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function eventos(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pedido_eventos WHERE pedido_id = ? ORDER BY created_at, id');
        $stmt->execute([$pedidoId]);

        return $stmt->fetchAll() ?: [];
    }
}
