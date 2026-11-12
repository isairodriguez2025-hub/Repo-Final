<?php

declare(strict_types=1);

namespace App\Modules\Confianza;

use PDO;

/**
 * Chat interno del pedido (RN-14).
 *
 * Existe para que comercio y mayorista se coordinen SIN intercambiar teléfonos
 * ni correos. Si alguien intenta hacerlo, el mensaje se guarda marcado y se
 * avisa a administración: la plataforma no quiere que se salten la comisión.
 */
final class ChatService
{
    /** Estados en los que el chat está abierto. */
    private const ESTADOS = [
        'aceptado', 'preparando', 'listo_para_recoger', 'buscando_repartidor',
        'repartidor_asignado', 'en_recoleccion', 'en_camino', 'en_puerta', 'entregado',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function disponible(int $pedidoId): bool
    {
        $stmt = $this->pdo->prepare('SELECT estado FROM pedidos WHERE id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $estado = $stmt->fetchColumn();

        return $estado !== false && in_array((string) $estado, self::ESTADOS, true);
    }

    /**
     * @return array{ok:bool, codigo?:string, mensaje:string, alerta?:bool}
     */
    public function enviar(int $pedidoId, int $autorId, string $cuerpo): array
    {
        $cuerpo = trim($cuerpo);

        if ($cuerpo === '') {
            return ['ok' => false, 'codigo' => 'VACIO', 'mensaje' => 'El mensaje está vacío.'];
        }

        if (!$this->disponible($pedidoId)) {
            return ['ok' => false, 'codigo' => 'CHAT_CERRADO', 'mensaje' => 'El chat se abre cuando el pedido es aceptado.'];
        }

        $alerta = $this->detectarDesintermediacion($cuerpo);

        $this->pdo->prepare(
            'INSERT INTO mensajes (pedido_id, autor_id, cuerpo, tiene_alerta_desintermediacion) VALUES (?,?,?,?)'
        )->execute([$pedidoId, $autorId, mb_substr($cuerpo, 0, 2000), $alerta ? 1 : 0]);

        if ($alerta) {
            $this->pdo->prepare(
                "INSERT INTO jobs (tipo, payload, estado) VALUES ('alerta_desintermediacion', ?, 'pendiente')"
            )->execute([json_encode(['pedido_id' => $pedidoId, 'autor_id' => $autorId], JSON_UNESCAPED_UNICODE)]);
        }

        return ['ok' => true, 'mensaje' => 'Mensaje enviado.', 'alerta' => $alerta];
    }

    /**
     * Busca teléfonos (10 dígitos seguidos, con o sin separadores) y correos.
     */
    public function detectarDesintermediacion(string $texto): bool
    {
        $telefono = '/\b(?:\+?52\s?)?(?:\(?\d{2,3}\)?[\s.\-]?){2,4}\d{4}\b/';
        $correo = '/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i';

        // Solo cuenta como teléfono si tiene al menos 10 dígitos en total.
        if (preg_match_all('/\d/', $texto) >= 10 && preg_match($telefono, $texto)) {
            return true;
        }

        return (bool) preg_match($correo, $texto);
    }

    public function mensajes(int $pedidoId, int $limite = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.*, u.email FROM mensajes m JOIN usuarios u ON u.id = m.autor_id
             WHERE m.pedido_id = ? ORDER BY m.created_at LIMIT ?'
        );
        $stmt->bindValue(1, $pedidoId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }
}
