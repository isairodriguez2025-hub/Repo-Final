<?php

declare(strict_types=1);

namespace App\Modules\Confianza;

use App\Modules\Auth\UsuarioRepository;
use App\Modules\Pedidos\PedidoService;
use PDO;

/**
 * Reclamos con evidencia (RN-11).
 *
 * Ventana de 48 horas desde la entrega. Abrir un reclamo congela el dinero:
 * el pedido pasa a `en_disputa` y no se dispersa hasta que se resuelva.
 */
final class ReclamoService
{
    public const VENTANA_HORAS = 48;
    public const MAX_FOTOS = 5;

    public const TIPOS = ['faltante', 'danado', 'producto_incorrecto', 'calidad'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly PedidoService $pedidos,
    ) {
    }

    /**
     * @param string[] $fotos rutas ya guardadas por Upload
     * @return array{ok:bool, codigo?:string, mensaje:string, uuid?:string}
     */
    public function abrir(int $pedidoId, int $comercioUsuarioId, string $tipo, string $descripcion, array $fotos = []): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'codigo' => 'TIPO_INVALIDO', 'mensaje' => 'Tipo de reclamo no válido.'];
        }

        if (mb_strlen(trim($descripcion)) < 15) {
            return ['ok' => false, 'codigo' => 'DESCRIPCION_CORTA', 'mensaje' => 'Describe el problema con al menos 15 caracteres.'];
        }

        if (count($fotos) > self::MAX_FOTOS) {
            return ['ok' => false, 'codigo' => 'DEMASIADAS_FOTOS', 'mensaje' => 'Máximo ' . self::MAX_FOTOS . ' fotos.'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.estado, c.usuario_id AS comercio_usuario
             FROM pedidos p JOIN comercios_perfil c ON c.id = p.comercio_id
             WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $pedido = $stmt->fetch();

        if ($pedido === false || (int) $pedido['comercio_usuario'] !== $comercioUsuarioId) {
            return ['ok' => false, 'codigo' => 'NO_ENCONTRADO', 'mensaje' => 'El pedido no existe.'];
        }

        // Solo 'entregado': pasadas 48 h el pedido pasa a 'completado' y la
        // ventana de reclamo ya cerró, así que no tiene sentido aceptarlo.
        if ((string) $pedido['estado'] !== 'entregado') {
            return ['ok' => false, 'codigo' => 'ESTADO_INVALIDO', 'mensaje' => 'Solo puedes reclamar un pedido entregado y dentro del plazo.'];
        }

        // Ventana de 48 h contada desde el evento de entrega.
        $stmt = $this->pdo->prepare(
            "SELECT created_at FROM pedido_eventos WHERE pedido_id = ? AND estado_nuevo = 'entregado' ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$pedidoId]);
        $entregado = $stmt->fetchColumn();

        if ($entregado !== false) {
            $horas = (time() - strtotime((string) $entregado)) / 3600;

            if ($horas > self::VENTANA_HORAS) {
                return ['ok' => false, 'codigo' => 'FUERA_DE_PLAZO', 'mensaje' => 'La ventana de reclamo de 48 horas ya cerró.'];
            }
        }

        $stmt = $this->pdo->prepare("SELECT id FROM reclamos WHERE pedido_id = ? AND estado IN ('abierto','respondido') LIMIT 1");
        $stmt->execute([$pedidoId]);

        if ($stmt->fetchColumn() !== false) {
            return ['ok' => false, 'codigo' => 'YA_EXISTE', 'mensaje' => 'Ya hay un reclamo abierto para este pedido.'];
        }

        $uuid = UsuarioRepository::uuid4();

        $this->pdo->beginTransaction();

        try {
            $this->pdo->prepare(
                "INSERT INTO reclamos (uuid, pedido_id, tipo, descripcion, estado) VALUES (?,?,?,?, 'abierto')"
            )->execute([$uuid, $pedidoId, $tipo, mb_substr($descripcion, 0, 2000)]);

            $reclamoId = (int) $this->pdo->lastInsertId();

            $insertar = $this->pdo->prepare("INSERT INTO reclamo_evidencias (reclamo_id, ruta, tipo) VALUES (?,?, 'foto')");

            foreach ($fotos as $ruta) {
                $insertar->execute([$reclamoId, $ruta]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        // Congela el dinero: el pedido pasa a disputa.
        $transicion = $this->pedidos->transicionar($pedidoId, 'en_disputa', $comercioUsuarioId, ['comercio'], 'Reclamo abierto: ' . $tipo);

        if (!$transicion['ok']) {
            // Sin transición no hay congelamiento: se deshace el reclamo para
            // no dejar un caso abierto que no bloquea el pago.
            $this->pdo->prepare('DELETE FROM reclamo_evidencias WHERE reclamo_id = ?')->execute([$reclamoId]);
            $this->pdo->prepare('DELETE FROM reclamos WHERE id = ?')->execute([$reclamoId]);

            return ['ok' => false, 'codigo' => 'TRANSICION_FALLIDA', 'mensaje' => $transicion['mensaje']];
        }

        return ['ok' => true, 'mensaje' => 'Reclamo abierto. El mayorista tiene 24 horas para responder.', 'uuid' => $uuid];
    }

    public function responder(int $reclamoId, int $usuarioId, string $respuesta): bool
    {
        $stmt = $this->pdo->prepare("UPDATE reclamos SET respuesta = ?, estado = 'respondido' WHERE id = ? AND estado = 'abierto'");
        $stmt->execute([mb_substr($respuesta, 0, 2000), $reclamoId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Resolución por administración.
     *
     * @param string $resolucion reembolso_total | reembolso_parcial | sin_reembolso
     */
    public function resolver(int $reclamoId, int $adminId, string $resolucion, ?float $monto, string $responsable): bool
    {
        if (!in_array($resolucion, ['reembolso_total', 'reembolso_parcial', 'sin_reembolso'], true)) {
            return false;
        }

        $estado = $resolucion === 'sin_reembolso' ? 'rechazado' : 'resuelto';

        $stmt = $this->pdo->prepare(
            'UPDATE reclamos SET estado = ?, resolucion = ?, monto_reembolso = ?, responsable = ?, resuelto_por = ?
             WHERE id = ? AND estado IN (\'abierto\',\'respondido\')'
        );
        $stmt->execute([$estado, $resolucion, $monto, $responsable, $adminId, $reclamoId]);

        return $stmt->rowCount() > 0;
    }

    public function dePedido(int $pedidoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reclamos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$pedidoId]);

        return $stmt->fetch() ?: null;
    }

    public function evidencias(int $reclamoId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reclamo_evidencias WHERE reclamo_id = ?');
        $stmt->execute([$reclamoId]);

        return $stmt->fetchAll() ?: [];
    }
}
