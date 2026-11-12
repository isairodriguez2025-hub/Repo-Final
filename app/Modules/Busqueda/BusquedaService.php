<?php

declare(strict_types=1);

namespace App\Modules\Busqueda;

use App\Core\Cache;
use App\Core\Geo;
use PDO;

/**
 * Reglas de la búsqueda (RF-C02, RF-C03, RN-15).
 *
 * Antes de tocar la base valida los filtros, comprueba la geocerca y, si ya se
 * hizo la misma búsqueda hace menos de 30 segundos, la sirve del caché.
 */
final class BusquedaService
{
    public const RADIO_DEFAULT = 10.0;
    public const RADIO_MAX = 30.0;
    public const PER_PAGE_DEFAULT = 20;
    public const PER_PAGE_MAX = 50;
    public const TTL_BUSQUEDA = 30;
    public const TTL_PERFIL = 60;

    public const ORDENES = ['distancia', 'precio_asc', 'precio_desc', 'reputacion'];

    public function __construct(
        private readonly BusquedaRepository $repo,
        private readonly Cache $cache,
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @return array{ok:bool, error?:array{code:string,message:string}, data?:array<string,mixed>}
     */
    public function buscar(array $entrada): array
    {
        $lat = (float) ($entrada['lat'] ?? 0);
        $lng = (float) ($entrada['lng'] ?? 0);

        if ($lat === 0.0 && $lng === 0.0) {
            return ['ok' => false, 'error' => [
                'code'    => 'SIN_UBICACION',
                'message' => 'Indica la ubicación de tu comercio para buscar cerca.',
            ]];
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['ok' => false, 'error' => [
                'code'    => 'UBICACION_INVALIDA',
                'message' => 'Las coordenadas no son válidas.',
            ]];
        }

        // RN-15: fuera de la zona de cobertura no se ejecuta nada.
        if (!$this->dentroDeCobertura($lat, $lng)) {
            return ['ok' => false, 'error' => [
                'code'    => 'FUERA_DE_COBERTURA',
                'message' => 'El servicio no está disponible en tu zona.',
            ]];
        }

        $radio = min(max((float) ($entrada['radio'] ?? self::RADIO_DEFAULT), 0.5), self::RADIO_MAX);
        $perPage = min(max((int) ($entrada['per_page'] ?? self::PER_PAGE_DEFAULT), 1), self::PER_PAGE_MAX);
        $page = max((int) ($entrada['page'] ?? 1), 1);
        $orden = in_array($entrada['orden'] ?? '', self::ORDENES, true) ? (string) $entrada['orden'] : 'distancia';
        $q = mb_substr(trim((string) ($entrada['q'] ?? '')), 0, 80);
        $categoria = (int) ($entrada['categoria_id'] ?? 0) ?: null;

        $filtros = [
            'q'            => $q,
            'categoria_id' => $categoria,
            'lat'          => $lat,
            'lng'          => $lng,
            'radio'        => $radio,
            'orden'        => $orden,
            'page'         => $page,
            'per_page'     => $perPage,
        ];

        // Clave con coordenadas redondeadas a ~110 m: búsquedas vecinas comparten
        // resultado en vez de generar una entrada por cada metro.
        $clave = 'busqueda:' . md5(json_encode([
            round($lat, 3), round($lng, 3), $radio, $categoria, $q, $orden, $page, $perPage,
        ]) ?: '');

        $cacheado = $this->cache->get($clave);

        if ($cacheado !== null) {
            $cacheado['cacheado'] = true;

            return ['ok' => true, 'data' => $cacheado];
        }

        $resultado = $this->repo->buscar($filtros);

        $data = [
            'resultados' => array_map([$this, 'formatear'], $resultado['filas']),
            'total'      => $resultado['total'],
            'page'       => $page,
            'per_page'   => $perPage,
            'paginas'    => (int) ceil($resultado['total'] / $perPage),
            'radio'      => $radio,
            'orden'      => $orden,
            'cacheado'   => false,
        ];

        $this->cache->put($clave, $data, self::TTL_BUSQUEDA);

        return ['ok' => true, 'data' => $data];
    }

    /** RN-15: ¿la coordenada cae en alguna zona activa? */
    public function dentroDeCobertura(float $lat, float $lng): bool
    {
        $zonas = $this->pdo->query('SELECT poligono FROM zonas_cobertura WHERE activa = 1')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        if ($zonas === []) {
            // Sin zonas configuradas no se bloquea: es el caso de una instalación
            // nueva que aún no define su área de reparto.
            return true;
        }

        foreach ($zonas as $json) {
            $poligono = json_decode((string) $json, true);

            if (is_array($poligono) && Geo::puntoEnPoligono($lat, $lng, $poligono)) {
                return true;
            }
        }

        return false;
    }

    /** Perfil público con caché de 60 s. */
    public function mayorista(string $uuid): ?array
    {
        $clave = 'mayorista:' . md5($uuid);
        $cacheado = $this->cache->get($clave);

        if ($cacheado !== null) {
            return $cacheado;
        }

        $perfil = $this->repo->mayoristaPorUuid($uuid);

        if ($perfil === null) {
            return null;
        }

        $this->cache->put($clave, $perfil, self::TTL_PERFIL);

        return $perfil;
    }

    /** Categorías activas para el filtro de la pantalla. */
    public function categorias(): array
    {
        return $this->pdo->query('SELECT id, nombre FROM categorias WHERE activa = 1 ORDER BY nombre')->fetchAll() ?: [];
    }

    /** Se llama cuando un mayorista cambia su catálogo. */
    public function invalidarCatalogo(): int
    {
        return $this->cache->limpiarPrefijo('busqueda:') + $this->cache->limpiarPrefijo('mayorista:');
    }

    /** Normaliza una fila para la API y la vista. */
    private function formatear(array $fila): array
    {
        $precio = (float) $fila['precio_base'];
        $iva = (float) $fila['iva_tasa'];

        return [
            'uuid'             => $fila['uuid'],
            'nombre'           => $fila['nombre'],
            'categoria'        => $fila['categoria'],
            'categoria_id'     => (int) $fila['categoria_id'],
            'precio_base'      => $precio,
            'precio_con_iva'   => round($precio * (1 + $iva / 100), 2, PHP_ROUND_HALF_UP),
            'unidad_venta'     => $fila['unidad_venta'],
            'contenido'        => $fila['contenido'],
            'stock_disponible' => (int) $fila['stock_disponible'],
            'cantidad_minima'  => (int) $fila['cantidad_minima'],
            'multiplo_venta'   => (int) $fila['multiplo_venta'],
            'distancia_km'     => round((float) $fila['distancia_km'], 2),
            'mayorista'        => [
                'uuid'       => $fila['mayorista_uuid'],
                'nombre'     => $fila['nombre_comercial'],
                'reputacion' => $fila['reputacion'] === null ? null : (float) $fila['reputacion'],
                'latitud'    => (float) $fila['latitud'],
                'longitud'   => (float) $fila['longitud'],
                'monto_minimo' => (float) $fila['monto_minimo'],
            ],
        ];
    }
}
