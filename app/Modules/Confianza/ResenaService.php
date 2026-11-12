<?php

declare(strict_types=1);

namespace App\Modules\Confianza;

use PDO;

/**
 * Reseñas bidireccionales (RN-16).
 *
 * Solo se puede calificar un pedido completado, una vez por autor y por rol.
 * El comentario pasa por una lista de palabras bloqueadas; si la contiene, la
 * reseña se guarda oculta para revisión en vez de publicarse.
 */
final class ResenaService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{ok:bool, codigo?:string, mensaje:string, id?:int}
     */
    public function crear(int $pedidoId, int $autorId, string $rolAutor, int $calificacion, ?string $comentario): array
    {
        if ($calificacion < 1 || $calificacion > 5) {
            return ['ok' => false, 'codigo' => 'CALIFICACION_INVALIDA', 'mensaje' => 'La calificación va de 1 a 5.'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.estado, p.comercio_id, p.mayorista_id, c.usuario_id AS comercio_usuario, m.usuario_id AS mayorista_usuario
             FROM pedidos p
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();

        if ($pedido === false) {
            return ['ok' => false, 'codigo' => 'NO_ENCONTRADO', 'mensaje' => 'El pedido no existe.'];
        }

        if (!in_array((string) $pedido['estado'], ['completado'], true)) {
            return ['ok' => false, 'codigo' => 'PEDIDO_NO_COMPLETADO', 'mensaje' => 'Solo puedes calificar pedidos completados.'];
        }

        // El destinatario depende de quién califica.
        $destinatario = match ($rolAutor) {
            'comercio'   => (int) $pedido['mayorista_usuario'],
            'mayorista'  => (int) $pedido['comercio_usuario'],
            default      => null,
        };

        if ($destinatario === null) {
            return ['ok' => false, 'codigo' => 'ROL_INVALIDO', 'mensaje' => 'Rol no válido para calificar.'];
        }

        $oculta = $this->tienePalabraBloqueada((string) $comentario);

        try {
            $this->pdo->prepare(
                'INSERT INTO resenas (pedido_id, autor_id, destinatario_id, rol_autor, calificacion, comentario, oculta, motivo_oculta)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute([
                $pedidoId, $autorId, $destinatario, $rolAutor, $calificacion,
                $comentario, $oculta ? 1 : 0, $oculta ? 'Lenguaje inapropiado' : null,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'codigo' => 'DUPLICADA', 'mensaje' => 'Ya calificaste este pedido.'];
            }

            throw $e;
        }

        $id = (int) $this->pdo->lastInsertId();

        if (!$oculta) {
            $this->recalcularReputacion($destinatario);
        }

        return ['ok' => true, 'mensaje' => $oculta ? 'Reseña recibida; queda en revisión.' : 'Gracias por tu calificación.', 'id' => $id];
    }

    private function tienePalabraBloqueada(string $texto): bool
    {
        if (trim($texto) === '') {
            return false;
        }

        $palabras = $this->pdo->query('SELECT palabra FROM palabras_bloqueadas')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        foreach ($palabras as $palabra) {
            if (mb_stripos($texto, (string) $palabra) !== false) {
                return true;
            }
        }

        return false;
    }

    /** Promedio de las últimas 20 reseñas visibles. */
    public function recalcularReputacion(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT AVG(calificacion) FROM (
                SELECT calificacion FROM resenas
                WHERE destinatario_id = ? AND oculta = 0
                ORDER BY created_at DESC LIMIT 20
             ) t'
        );
        $stmt->execute([$usuarioId]);
        $promedio = $stmt->fetchColumn();

        if ($promedio === false || $promedio === null) {
            return;
        }

        $valor = round((float) $promedio, 2);

        $this->pdo->prepare('UPDATE mayoristas_perfil SET reputacion = ? WHERE usuario_id = ?')->execute([$valor, $usuarioId]);
        $this->pdo->prepare('UPDATE repartidores_perfil SET calificacion_promedio = ? WHERE usuario_id = ?')->execute([$valor, $usuarioId]);
    }

    public function dePedido(int $pedidoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.*, u.email FROM resenas r JOIN usuarios u ON u.id = r.autor_id
             WHERE r.pedido_id = ? AND r.oculta = 0 ORDER BY r.created_at'
        );
        $stmt->execute([$pedidoId]);

        return $stmt->fetchAll() ?: [];
    }
}
