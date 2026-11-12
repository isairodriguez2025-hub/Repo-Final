<?php

declare(strict_types=1);

namespace App\Modules\Logistica;

use App\Core\Cache;
use PDO;

/**
 * Tracking en vivo y ETA (RF-C06, DEC-09, SEG-19).
 *
 * El comercio ve al repartidor SOLO durante el viaje activo: antes de
 * `en_camino` no hay mapa, y al entregarse la posición deja de exponerse.
 */
final class TrackingService
{
    /** Precisión máxima aceptable en metros (RF-R03). */
    public const PRECISION_MAX = 100;

    /** Estados en los que el repartidor puede transmitir posición. */
    private const ESTADOS_TRANSMITEN = ['en_recoleccion', 'en_camino', 'en_puerta'];

    /** Estados en los que el comercio puede ver el mapa. */
    private const ESTADOS_VISIBLES = ['en_camino', 'en_puerta'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Cache $cache,
    ) {
    }

    /**
     * Guarda un lote de posiciones del repartidor.
     *
     * @param array<int, array{lat:float|string, lng:float|string, precision_m?:int, capturado_en?:string}> $puntos
     * @return array{aceptados:int, descartados:int, motivo?:string}
     */
    public function registrar(int $repartidorId, array $puntos): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.id, p.estado FROM envios e JOIN pedidos p ON p.id = e.pedido_id
             WHERE e.repartidor_id = ? AND p.estado IN (?,?,?)
             ORDER BY e.id DESC LIMIT 1'
        );
        $stmt->execute([$repartidorId, ...self::ESTADOS_TRANSMITEN]);
        $envio = $stmt->fetch();

        if ($envio === false) {
            return ['aceptados' => 0, 'descartados' => count($puntos), 'motivo' => 'No tienes un viaje activo.'];
        }

        $envioId = (int) $envio['id'];
        $insertar = $this->pdo->prepare(
            'INSERT INTO tracking_puntos (envio_id, latitud, longitud, precision_m, capturado_en) VALUES (?,?,?,?,?)'
        );

        $aceptados = 0;
        $descartados = 0;
        $ultimo = null;
        $ahora = time();

        $this->pdo->beginTransaction();

        try {
            foreach ($puntos as $punto) {
                $precision = isset($punto['precision_m']) ? (int) $punto['precision_m'] : null;

                // Lecturas imprecisas se tiran: un punto con 150 m de error
                // dibuja al repartidor en la cuadra equivocada.
                if ($precision !== null && $precision > self::PRECISION_MAX) {
                    $descartados++;
                    continue;
                }

                $capturado = isset($punto['capturado_en'])
                    ? strtotime((string) $punto['capturado_en'])
                    : $ahora;

                // Una hora futura es un reloj mal configurado o un intento de
                // falsear el recorrido.
                if ($capturado === false || $capturado > $ahora + 60) {
                    $descartados++;
                    continue;
                }

                $lat = (float) ($punto['lat'] ?? 0);
                $lng = (float) ($punto['lng'] ?? 0);

                if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                    $descartados++;
                    continue;
                }

                $insertar->execute([$envioId, $lat, $lng, $precision, date('Y-m-d H:i:s', $capturado)]);
                $aceptados++;
                $ultimo = [$lat, $lng];
            }

            if ($ultimo !== null) {
                $this->pdo->prepare(
                    'UPDATE repartidores_perfil SET ultima_posicion_lat = ?, ultima_posicion_lng = ?, ultima_posicion_at = NOW()
                     WHERE id = ?'
                )->execute([$ultimo[0], $ultimo[1], $repartidorId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return ['aceptados' => $aceptados, 'descartados' => $descartados];
    }

    /**
     * Estado del seguimiento para el comercio dueño del pedido.
     *
     * @return array{ok:bool, codigo?:string, mensaje?:string, data?:array<string,mixed>, etag?:string}
     */
    public function consultar(string $pedidoUuid, int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.uuid, p.estado, c.usuario_id AS comercio_usuario, d.latitud AS destino_lat,
                    d.longitud AS destino_lng, d.calle, d.colonia, d.ciudad, e.id AS envio_id
             FROM pedidos p
             JOIN comercios_perfil c ON c.id = p.comercio_id
             JOIN direcciones d ON d.id = p.direccion_entrega_id
             LEFT JOIN envios e ON e.pedido_id = p.id
             WHERE p.uuid = ? LIMIT 1'
        );
        $stmt->execute([$pedidoUuid]);
        $pedido = $stmt->fetch();

        // SEG-09: si no es suyo, se responde igual que si no existiera.
        if ($pedido === false || (int) $pedido['comercio_usuario'] !== $usuarioId) {
            return ['ok' => false, 'codigo' => 'NO_ENCONTRADO', 'mensaje' => 'El pedido no existe.'];
        }

        if (!in_array((string) $pedido['estado'], self::ESTADOS_VISIBLES, true)) {
            return ['ok' => false, 'codigo' => 'SIN_SEGUIMIENTO', 'mensaje' => 'El seguimiento no está disponible todavía.'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT latitud, longitud, precision_m, capturado_en FROM tracking_puntos
             WHERE envio_id = ? ORDER BY capturado_en DESC LIMIT 40'
        );
        $stmt->execute([(int) $pedido['envio_id']]);
        $puntos = array_reverse($stmt->fetchAll() ?: []);

        $ultimo = $puntos === [] ? null : end($puntos);

        $eta = null;
        $distancia = null;

        if ($ultimo !== null) {
            $distancia = $this->distanciaKm((float) $ultimo['latitud'], (float) $ultimo['longitud'],
                (float) $pedido['destino_lat'], (float) $pedido['destino_lng']);
            $eta = $this->eta((float) $ultimo['latitud'], (float) $ultimo['longitud'],
                (float) $pedido['destino_lat'], (float) $pedido['destino_lng']);
        }

        $data = [
            'pedido'   => $pedido['uuid'],
            'estado'   => $pedido['estado'],
            'destino'  => [
                'lat' => (float) $pedido['destino_lat'],
                'lng' => (float) $pedido['destino_lng'],
                'calle' => $pedido['calle'],
                'colonia' => $pedido['colonia'],
                'ciudad' => $pedido['ciudad'],
            ],
            'repartidor' => $ultimo === null ? null : [
                'lat' => (float) $ultimo['latitud'],
                'lng' => (float) $ultimo['longitud'],
                'actualizado' => $ultimo['capturado_en'],
            ],
            'ruta'        => array_map(static fn (array $p): array => [
                (float) $p['latitud'], (float) $p['longitud'],
            ], $puntos),
            'distancia_km' => $distancia,
            'eta_min'      => $eta,
        ];

        return ['ok' => true, 'data' => $data, 'etag' => '"' . md5(json_encode($data) ?: '') . '"'];
    }

    /**
     * ETA en minutos (DEC-09).
     *
     * Se pide a OSRM y se cachea 2 minutos por celda geográfica redondeada a
     * 3 decimales (~110 m). Si el servicio no responde, se estima con la
     * distancia en línea recta por 1.3, que es el factor típico de ruta urbana.
     */
    public function eta(float $lat, float $lng, float $destLat, float $destLng): int
    {
        $clave = sprintf('eta:%.3f,%.3f:%.3f,%.3f', $lat, $lng, $destLat, $destLng);
        $cacheado = $this->cache->get($clave);

        if ($cacheado !== null && isset($cacheado['min'])) {
            return (int) $cacheado['min'];
        }

        $minutos = $this->etaOsrm($lat, $lng, $destLat, $destLng);

        if ($minutos === null) {
            $km = $this->distanciaKm($lat, $lng, $destLat, $destLng) * 1.3;
            $minutos = (int) max(1, ceil($km / 0.35)); // ~21 km/h promedio urbano
        }

        $this->cache->put($clave, ['min' => $minutos], 120);

        return $minutos;
    }

    private function etaOsrm(float $lat, float $lng, float $destLat, float $destLng): ?int
    {
        $url = sprintf(
            'https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false',
            $lng, $lat, $destLng, $destLat
        );

        $contexto = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $respuesta = @file_get_contents($url, false, $contexto);

        if ($respuesta === false) {
            return null;
        }

        $datos = json_decode($respuesta, true);

        if (!is_array($datos) || ($datos['code'] ?? '') !== 'Ok' || !isset($datos['routes'][0]['duration'])) {
            return null;
        }

        return (int) max(1, ceil(((float) $datos['routes'][0]['duration']) / 60));
    }

    public function distanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round(2 * $r * asin(sqrt($a)), 2);
    }
}
