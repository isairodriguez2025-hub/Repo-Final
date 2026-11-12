<?php

declare(strict_types=1);

namespace App\Modules\Busqueda;

use PDO;

/**
 * Motor de búsqueda geográfica (DEC-09).
 *
 * Patrón obligatorio: primero una caja envolvente que aprovecha el índice
 * `idx_geo` de `mayoristas_perfil`, y solo sobre esos candidatos se calcula la
 * distancia real con Haversine. Así la base no recorre la tabla completa.
 */
final class BusquedaRepository
{
    /** Tope de candidatos antes de ordenar y paginar. */
    public const MAX_CANDIDATOS = 100;

    private const ORDENES = [
        'distancia'   => 't.distancia_km ASC, t.nombre ASC',
        'precio_asc'  => 't.precio_base ASC, t.distancia_km ASC',
        'precio_desc' => 't.precio_base DESC, t.distancia_km ASC',
        'reputacion'  => 't.reputacion DESC, t.distancia_km ASC',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{q?:?string, categoria_id?:?int, lat:float, lng:float, radio:float,
     *              orden?:string, page?:int, per_page?:int} $filtros
     * @return array{filas:array<int,array<string,mixed>>, total:int}
     */
    public function buscar(array $filtros): array
    {
        $lat = (float) $filtros['lat'];
        $lng = (float) $filtros['lng'];
        $radio = (float) ($filtros['radio'] ?? 10.0);

        // Caja envolvente: 1 grado de latitud ≈ 111 km; en longitud se divide
        // entre el coseno de la latitud porque los meridianos se juntan.
        $dlat = $radio / 111.0;
        $dlng = $radio / (111.0 * max(cos(deg2rad($lat)), 0.01));

        $orden = self::ORDENES[$filtros['orden'] ?? 'distancia'] ?? self::ORDENES['distancia'];
        $limite = (int) ($filtros['per_page'] ?? 20);
        $offset = (((int) ($filtros['page'] ?? 1)) - 1) * $limite;

        $q = trim((string) ($filtros['q'] ?? ''));
        $categoria = $filtros['categoria_id'] ?? null;

        $condiciones = '';
        $params = [
            ':lat1'    => $lat,
            ':lat2'    => $lat,
            ':lng1'    => $lng,
            ':lat_min' => $lat - $dlat,
            ':lat_max' => $lat + $dlat,
            ':lng_min' => $lng - $dlng,
            ':lng_max' => $lng + $dlng,
        ];

        if ($categoria !== null && $categoria > 0) {
            $condiciones .= ' AND p.categoria_id = :categoria';
            $params[':categoria'] = (int) $categoria;
        }

        if ($q !== '') {
            // BOOLEAN MODE con comodín al final: "caf*" encuentra "café" y "cafetera".
            $condiciones .= ' AND MATCH(p.nombre, p.descripcion) AGAINST (:q IN BOOLEAN MODE)';
            $params[':q'] = $this->textoBoolean($q);
        }

        $base = "
            FROM mayoristas_perfil m
            JOIN productos p ON p.mayorista_id = m.id
                AND p.activo = 1
                AND p.stock_disponible > 0
                AND p.deleted_at IS NULL
            JOIN categorias c ON c.id = p.categoria_id
            WHERE m.latitud  BETWEEN :lat_min AND :lat_max
              AND m.longitud BETWEEN :lng_min AND :lng_max
              AND m.estado_kyc = 'aprobado'
              AND m.activo = 1
              {$condiciones}
        ";

        $seleccion = "
            SELECT p.uuid, p.nombre, p.descripcion, p.precio_base, p.iva_tasa,
                   p.unidad_venta, p.contenido, p.stock_disponible, p.categoria_id,
                   p.cantidad_minima, p.multiplo_venta,
                   c.nombre AS categoria,
                   m.uuid AS mayorista_uuid, m.nombre_comercial, m.reputacion,
                   m.latitud, m.longitud, m.monto_minimo,
                   (6371 * ACOS(LEAST(1,
                       COS(RADIANS(:lat1)) * COS(RADIANS(m.latitud)) *
                       COS(RADIANS(m.longitud) - RADIANS(:lng1)) +
                       SIN(RADIANS(:lat2)) * SIN(RADIANS(m.latitud))
                   ))) AS distancia_km
            {$base}
            LIMIT " . self::MAX_CANDIDATOS;

        $sql = "
            SELECT * FROM ({$seleccion}) t
            WHERE t.distancia_km <= :radio
            ORDER BY {$orden}
            LIMIT :limite OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $nombre => $valor) {
            $stmt->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->bindValue(':radio', $radio, PDO::PARAM_STR);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $filas = $stmt->fetchAll() ?: [];

        // Total para la paginación: mismos filtros, sin orden ni límite.
        $sqlTotal = "SELECT COUNT(*) FROM (
                        SELECT p.uuid,
                        (6371 * ACOS(LEAST(1,
                            COS(RADIANS(:lat1)) * COS(RADIANS(m.latitud)) *
                            COS(RADIANS(m.longitud) - RADIANS(:lng1)) +
                            SIN(RADIANS(:lat2)) * SIN(RADIANS(m.latitud))
                        ))) AS distancia_km
                        {$base}
                        LIMIT " . self::MAX_CANDIDATOS . "
                     ) t WHERE t.distancia_km <= :radio";

        $stmtTotal = $this->pdo->prepare($sqlTotal);

        foreach ($params as $nombre => $valor) {
            $stmtTotal->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmtTotal->bindValue(':radio', $radio, PDO::PARAM_STR);
        $stmtTotal->execute();

        return ['filas' => $filas, 'total' => (int) $stmtTotal->fetchColumn()];
    }

    /** Perfil público de un mayorista con su catálogo activo. */
    public function mayoristaPorUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, uuid, nombre_comercial, categoria_giro, reputacion, monto_minimo,
                    latitud, longitud, horario_apertura, horario_cierre
             FROM mayoristas_perfil
             WHERE uuid = ? AND estado_kyc = 'aprobado' AND activo = 1
             LIMIT 1"
        );
        $stmt->execute([$uuid]);
        $mayorista = $stmt->fetch();

        if ($mayorista === false) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.uuid, p.nombre, p.precio_base, p.iva_tasa, p.unidad_venta,
                    p.contenido, p.stock_disponible, c.nombre AS categoria
             FROM productos p
             JOIN categorias c ON c.id = p.categoria_id
             WHERE p.mayorista_id = ? AND p.activo = 1 AND p.stock_disponible > 0 AND p.deleted_at IS NULL
             ORDER BY p.nombre'
        );
        $stmt->execute([(int) $mayorista['id']]);

        $mayorista['productos'] = $stmt->fetchAll() ?: [];

        return $mayorista;
    }

    /** Convierte "café molido" en "+caf* +mol*" para BOOLEAN MODE. */
    private function textoBoolean(string $texto): string
    {
        $palabras = preg_split('/\s+/u', $texto) ?: [];
        $partes = [];

        foreach ($palabras as $palabra) {
            $limpia = preg_replace('/[+\-><()~*"@]/u', '', $palabra) ?? '';

            if (mb_strlen($limpia) >= 2) {
                $partes[] = '+' . $limpia . '*';
            }
        }

        return $partes === [] ? '' : implode(' ', $partes);
    }
}
