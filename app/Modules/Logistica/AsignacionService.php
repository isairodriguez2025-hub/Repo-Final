<?php

declare(strict_types=1);

namespace App\Modules\Logistica;

use App\Modules\Pedidos\PedidoService;
use PDO;

/**
 * Asignación de repartidor por rondas (RN-07).
 *
 * Se oferta a los tres repartidores más cercanos con vehículo compatible, con
 * 60 segundos para aceptar. Si nadie acepta, la siguiente ronda amplía el
 * radio. A la tercera ronda sin éxito se avisa a administración.
 */
final class AsignacionService
{
    /** Radio de cada ronda en kilómetros. */
    public const RADIOS = [1 => 5.0, 2 => 8.0, 3 => 12.0];
    public const OFERTAS_POR_RONDA = 3;
    public const SEGUNDOS_OFERTA = 60;

    public function __construct(
        private readonly PDO $pdo,
        private readonly TarifaService $tarifas,
        private readonly PedidoService $pedidos,
    ) {
    }

    /**
     * Crea (o recupera) el envío del pedido con sus datos de carga.
     */
    public function prepararEnvio(int $pedidoId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM envios WHERE pedido_id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);
        $envio = $stmt->fetch();

        if ($envio !== false) {
            return $envio;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(d.cantidad * p.peso_kg), 0) AS peso,
                    COALESCE(SUM(d.cantidad * p.volumen_m3), 0) AS volumen,
                    MAX(CASE WHEN p.requiere_frio = 1 THEN 1 ELSE 0 END) AS frio
             FROM detalle_pedido d
             JOIN productos p ON p.id = d.producto_id
             WHERE d.pedido_id = ?'
        );
        $stmt->execute([$pedidoId]);
        $carga = $stmt->fetch() ?: ['peso' => 0, 'volumen' => 0, 'frio' => 0];

        $this->pdo->prepare(
            "INSERT INTO envios (pedido_id, peso_total_kg, estado) VALUES (?, ?, 'pendiente')"
        )->execute([$pedidoId, (float) $carga['peso']]);

        $stmt = $this->pdo->prepare('SELECT * FROM envios WHERE pedido_id = ? LIMIT 1');
        $stmt->execute([$pedidoId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Lanza una ronda de ofertas.
     *
     * @return int número de ofertas creadas
     */
    public function lanzarRonda(int $pedidoId, int $ronda): int
    {
        $radio = self::RADIOS[$ronda] ?? self::RADIOS[3];
        $envio = $this->prepararEnvio($pedidoId);

        if ($envio === null) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'SELECT m.latitud, m.longitud,
                    COALESCE(SUM(d.cantidad * p.peso_kg), 0) AS peso,
                    COALESCE(SUM(d.cantidad * p.volumen_m3), 0) AS volumen,
                    MAX(CASE WHEN p.requiere_frio = 1 THEN 1 ELSE 0 END) AS frio
             FROM pedidos pe
             JOIN mayoristas_perfil m ON m.id = pe.mayorista_id
             LEFT JOIN detalle_pedido d ON d.pedido_id = pe.id
             LEFT JOIN productos p ON p.id = d.producto_id
             WHERE pe.id = ?
             GROUP BY m.latitud, m.longitud'
        );
        $stmt->execute([$pedidoId]);
        $origen = $stmt->fetch();

        if ($origen === false) {
            return 0;
        }

        $lat = (float) $origen['latitud'];
        $lng = (float) $origen['longitud'];
        $peso = (float) $origen['peso'];
        $volumen = (float) $origen['volumen'];
        $frio = (int) $origen['frio'] === 1;

        $dlat = $radio / 111.0;
        $dlng = $radio / (111.0 * max(cos(deg2rad($lat)), 0.01));

        $sql = "
            SELECT r.id, r.calificacion_promedio, v.tipo,
                   (6371 * ACOS(LEAST(1,
                       COS(RADIANS(:lat1)) * COS(RADIANS(r.ultima_posicion_lat)) *
                       COS(RADIANS(r.ultima_posicion_lng) - RADIANS(:lng1)) +
                       SIN(RADIANS(:lat2)) * SIN(RADIANS(r.ultima_posicion_lat))
                   ))) AS distancia_km
            FROM repartidores_perfil r
            JOIN vehiculos v ON v.repartidor_id = r.id AND v.activo = 1
            WHERE r.disponible = 1
              AND r.estado_kyc = 'aprobado'
              AND r.ultima_posicion_lat IS NOT NULL
              AND r.ultima_posicion_lat BETWEEN :lat_min AND :lat_max
              AND r.ultima_posicion_lng BETWEEN :lng_min AND :lng_max
              AND v.capacidad_peso_kg >= :peso
              AND v.capacidad_volumen_m3 >= :volumen
              " . ($frio ? ' AND v.tiene_refrigeracion = 1 ' : '') . "
            ORDER BY distancia_km ASC, r.calificacion_promedio DESC
            LIMIT " . self::OFERTAS_POR_RONDA;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lat1', $lat);
        $stmt->bindValue(':lat2', $lat);
        $stmt->bindValue(':lng1', $lng);
        $stmt->bindValue(':lat_min', $lat - $dlat);
        $stmt->bindValue(':lat_max', $lat + $dlat);
        $stmt->bindValue(':lng_min', $lng - $dlng);
        $stmt->bindValue(':lng_max', $lng + $dlng);
        $stmt->bindValue(':peso', $peso);
        $stmt->bindValue(':volumen', $volumen);
        $stmt->execute();

        $candidatos = $stmt->fetchAll() ?: [];
        $creadas = 0;

        foreach ($candidatos as $c) {
            if ((float) $c['distancia_km'] > $radio) {
                continue;
            }

            $tarifa = $this->tarifas->calcular((string) $c['tipo'], (float) $c['distancia_km'], $peso);

            try {
                $this->pdo->prepare(
                    "INSERT INTO ofertas_envio (envio_id, repartidor_id, tarifa, distancia_km, ronda, estado, expira_en)
                     VALUES (?,?,?,?,?, 'pendiente', DATE_ADD(NOW(), INTERVAL ? SECOND))"
                )->execute([
                    (int) $envio['id'], (int) $c['id'], $tarifa, (float) $c['distancia_km'], $ronda, self::SEGUNDOS_OFERTA]);

                $creadas++;
            } catch (\PDOException $e) {
                // Ya se le ofertó antes (unique): no se duplica.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        $this->pdo->prepare(
            "UPDATE envios SET estado = 'ofertando', distancia_km = ? WHERE id = ?"
        )->execute([$candidatos[0]['distancia_km'] ?? null, (int) $envio['id']]);

        return $creadas;
    }

    /**
     * Aceptación atómica (RF-R04).
     *
     * El `FOR UPDATE` bloquea la fila de la oferta. Si dos repartidores
     * aceptan al mismo tiempo, el segundo encuentra el estado ya cambiado y
     * recibe error: solo uno gana.
     */
    public function aceptar(int $ofertaId, int $repartidorId): array
    {
        $this->pdo->beginTransaction();

        try {
            // Se bloquea el ENVÍO, no solo la oferta: cada repartidor tiene su
            // propia fila de oferta, así que bloquear solo la oferta dejaría
            // que dos repartidores distintos "ganaran" el mismo envío.
            $stmt = $this->pdo->prepare(
                "SELECT o.*, e.pedido_id, e.id AS envio_id, e.repartidor_id AS repartidor_asignado
                 FROM ofertas_envio o
                 JOIN envios e ON e.id = o.envio_id
                 WHERE o.id = ?
                 FOR UPDATE"
            );
            $stmt->execute([$ofertaId]);
            $oferta = $stmt->fetch();

            if ($oferta === false) {
                $this->pdo->rollBack();

                return ['ok' => false, 'mensaje' => 'La oferta no existe.'];
            }

            if ($oferta['repartidor_asignado'] !== null) {
                $this->pdo->rollBack();

                return ['ok' => false, 'mensaje' => 'Otro repartidor ya tomó este envío.'];
            }

            if ($oferta['estado'] !== 'pendiente') {
                $this->pdo->rollBack();

                return ['ok' => false, 'mensaje' => 'La oferta ya no está disponible.'];
            }

            if ((int) $oferta['repartidor_id'] !== $repartidorId) {
                $this->pdo->rollBack();

                return ['ok' => false, 'mensaje' => 'Esta oferta no es tuya.'];
            }

            $stmt = $this->pdo->prepare("UPDATE ofertas_envio SET estado = 'aceptada' WHERE id = ? AND estado = 'pendiente'");
            $stmt->execute([$ofertaId]);

            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();

                return ['ok' => false, 'mensaje' => 'Otro repartidor tomó el envío primero.'];
            }

            // La tarifa se congela aquí: es la que vio el repartidor.
            $this->pdo->prepare(
                "UPDATE envios SET repartidor_id = ?, tarifa = ?, estado = 'asignado' WHERE id = ?"
            )->execute([$repartidorId, $oferta['tarifa'], (int) $oferta['envio_id']]);

            // Las demás ofertas del mismo envío ya no sirven.
            $this->pdo->prepare(
                "UPDATE ofertas_envio SET estado = 'expirada'
                 WHERE envio_id = ? AND id <> ? AND estado = 'pendiente'"
            )->execute([(int) $oferta['envio_id'], $ofertaId]);

            $this->pdo->commit();

            $transicion = $this->pedidos->transicionar(
                (int) $oferta['pedido_id'],
                'repartidor_asignado',
                null,
                ['sistema'],
                'Repartidor asignado',
            );

            return ['ok' => true, 'mensaje' => 'Envío asignado.', 'pedido_id' => (int) $oferta['pedido_id'], 'transicion' => $transicion];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Expira ofertas vencidas y avanza de ronda. Lo llama el worker.
     *
     * @return array{pasadas:int, rondas:int, alertas:int}
     */
    public function procesarVencidas(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DISTINCT envio_id FROM ofertas_envio WHERE estado = 'pendiente' AND expira_en < NOW() LIMIT 50"
        );
        $envios = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $rondas = 0;
        $alertas = 0;

        foreach ($envios as $envioId) {
            $this->pdo->prepare(
                "UPDATE ofertas_envio SET estado = 'expirada' WHERE envio_id = ? AND estado = 'pendiente' AND expira_en < NOW()"
            )->execute([(int) $envioId]);

            // Si alguna oferta sigue viva, no se avanza.
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM ofertas_envio WHERE envio_id = ? AND estado = 'pendiente'"
            );
            $stmt->execute([(int) $envioId]);

            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $stmt = $this->pdo->prepare(
                'SELECT o.ronda, e.pedido_id FROM ofertas_envio o JOIN envios e ON e.id = o.envio_id
                 WHERE o.envio_id = ? ORDER BY o.ronda DESC LIMIT 1'
            );
            $stmt->execute([(int) $envioId]);
            $ultima = $stmt->fetch();

            if ($ultima === false) {
                continue;
            }

            $rondaActual = (int) $ultima['ronda'];
            $pedidoId = (int) $ultima['pedido_id'];

            if ($rondaActual < 3) {
                $this->lanzarRonda($pedidoId, $rondaActual + 1);
                $rondas++;
            } else {
                $this->alertarSinRepartidor($pedidoId);
                $alertas++;
            }
        }

        return ['pasadas' => count($envios), 'rondas' => $rondas, 'alertas' => $alertas];
    }

    /** Tras tres rondas sin éxito se avisa a administración. */
    private function alertarSinRepartidor(int $pedidoId): void
    {
        $this->pdo->prepare(
            "INSERT INTO jobs (tipo, payload, estado) VALUES ('alerta_sin_repartidor', ?, 'pendiente')"
        )->execute([json_encode(['pedido_id' => $pedidoId], JSON_UNESCAPED_UNICODE)]);

        $this->pdo->prepare("UPDATE envios SET estado = 'sin_repartidor' WHERE pedido_id = ?")->execute([$pedidoId]);
    }

    /** Ofertas pendientes de un repartidor, con lo que necesita para decidir. */
    public function ofertasDe(int $repartidorId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.id, o.tarifa, o.distancia_km, o.expira_en, o.ronda,
                    e.pedido_id, p.uuid AS pedido_uuid, p.total,
                    m.nombre_comercial AS mayorista,
                    c.nombre_negocio AS comercio,
                    d.calle, d.colonia, d.ciudad
             FROM ofertas_envio o
             JOIN envios e ON e.id = o.envio_id
             JOIN pedidos p ON p.id = e.pedido_id
             JOIN mayoristas_perfil m ON m.id = p.mayorista_id
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN direcciones d ON d.id = p.direccion_entrega_id
             WHERE o.repartidor_id = ? AND o.estado = 'pendiente' AND o.expira_en > NOW()
             ORDER BY o.expira_en ASC"
        );
        $stmt->execute([$repartidorId]);

        return $stmt->fetchAll() ?: [];
    }
}
