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

    /** Avisos por estado. La clave es el estado nuevo. */
    private const AVISOS = [
        'pendiente_aceptacion' => ['destino' => 'mayorista', 'tipo' => 'pedido_nuevo',
            'asunto' => 'Tienes un pedido por aceptar', 'texto' => 'Revisa el pedido y acéptalo dentro de los próximos 30 minutos.'],
        'aceptado'  => ['destino' => 'comercio', 'tipo' => 'pedido_aceptado',
            'asunto' => 'Tu pedido fue aceptado', 'texto' => 'El mayorista confirmó tu pedido y lo está preparando.'],
        'rechazado' => ['destino' => 'comercio', 'tipo' => 'pedido_rechazado',
            'asunto' => 'Tu pedido fue rechazado', 'texto' => 'El mayorista no pudo aceptarlo.'],
        'preparando' => ['destino' => 'comercio', 'tipo' => 'pedido_preparando',
            'asunto' => 'Tu pedido está en preparación', 'texto' => 'El mayorista está surtiendo tu pedido.'],
        'en_camino' => ['destino' => 'comercio', 'tipo' => 'pedido_en_camino',
            'asunto' => 'Tu pedido va en camino', 'texto' => 'El repartidor ya salió con tu pedido.'],
        'entregado' => ['destino' => 'comercio', 'tipo' => 'pedido_entregado',
            'asunto' => 'Pedido entregado', 'texto' => 'Tu pedido llegó. Si algo salió mal, tienes 48 horas para reportarlo.'],
        'expirado'  => ['destino' => 'comercio', 'tipo' => 'pedido_expirado',
            'asunto' => 'Tu pedido expiró', 'texto' => 'El pedido se canceló y el stock volvió al catálogo. Puedes volver a intentarlo.'],
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?\App\Modules\Notificaciones\NotificacionService $notificaciones = null,
    ) {
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

        // Puede venir dentro de otra transacción (por ejemplo, desde el
        // procesamiento de un webhook). PDO no soporta transacciones anidadas,
        // así que solo se abre una si no hay ninguna activa.
        $propia = !$this->pdo->inTransaction();

        if ($propia) {
            $this->pdo->beginTransaction();
        }

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

            $this->avisar($pedidoId, $estadoNuevo, $nota);

            if ($propia) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($propia && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return ['ok' => true, 'mensaje' => 'Pedido actualizado.', 'estado' => $estadoNuevo];
    }

    /** Encola el aviso correspondiente al nuevo estado. */
    private function avisar(int $pedidoId, string $estadoNuevo, ?string $nota): void
    {
        if ($this->notificaciones === null || !isset(self::AVISOS[$estadoNuevo])) {
            return;
        }

        $aviso = self::AVISOS[$estadoNuevo];

        $stmt = $this->pdo->prepare(
            'SELECT m.usuario_id AS mayorista, c.usuario_id AS comercio, p.uuid
             FROM pedidos p
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             JOIN comercios_perfil c ON c.id = p.comercio_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $fila = $stmt->fetch();

        if ($fila === false) {
            return;
        }

        $usuarioId = $aviso['destino'] === 'mayorista'
            ? (int) $fila['mayorista']
            : (int) $fila['comercio'];

        $cuerpo = $aviso['texto'] . ($nota !== null ? '<br><br><em>' . htmlspecialchars($nota, ENT_QUOTES, 'UTF-8') . '</em>' : '');

        $this->notificaciones->encolar(
            $usuarioId,
            $aviso['tipo'],
            $aviso['asunto'],
            $cuerpo,
            '/pedidos/' . $fila['uuid'],
        );
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

    /**
     * La reserva se vuelve venta: el stock ya no vuelve al catálogo, pero
     * tampoco sigue contando como reservado. Si no se descuenta aquí, el
     * contador `stock_reservado` crece para siempre y los reportes mienten.
     */
    private function consumirReservas(int $pedidoId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT producto_id, cantidad FROM inventario_reservas WHERE pedido_id = ? AND estado = 'activa'"
        );
        $stmt->execute([$pedidoId]);

        foreach ($stmt->fetchAll() ?: [] as $reserva) {
            $this->pdo->prepare(
                'UPDATE productos SET stock_reservado = GREATEST(stock_reservado - ?, 0) WHERE id = ?'
            )->execute([(int) $reserva['cantidad'], (int) $reserva['producto_id']]);
        }

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
