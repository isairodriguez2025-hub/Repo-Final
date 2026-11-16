<?php

declare(strict_types=1);

namespace App\Modules\Busqueda;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

final class BusquedaController
{
    public function __construct(
        private readonly BusquedaService $servicio,
        private readonly string $directorioVistas,
    ) {
    }

    private function vista(string $plantilla, array $datos, int $estado = 200): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $vista = new View($this->directorioVistas, $nonce);

        $datos['nonce'] ??= $nonce;
        $datos['csrf'] ??= Csrf::token();

        return Response::html($vista->render($plantilla, $datos), $estado)->withNonce($nonce);
    }

    /** GET /busqueda/productos — API de búsqueda. */
    public function productos(Request $peticion): Response
    {
        $resultado = $this->servicio->buscar([
            'q'            => $peticion->query('q', ''),
            'categoria_id' => $peticion->query('categoria_id'),
            'lat'          => $peticion->query('lat', 0),
            'lng'          => $peticion->query('lng', 0),
            'radio'        => $peticion->query('radio', BusquedaService::RADIO_DEFAULT),
            'orden'        => $peticion->query('orden', 'distancia'),
            'page'         => $peticion->query('page', 1),
            'per_page'     => $peticion->query('per_page', BusquedaService::PER_PAGE_DEFAULT),
        ]);

        if (!$resultado['ok']) {
            $codigo = $resultado['error']['code'] ?? 'ERROR';
            $estado = $codigo === 'FUERA_DE_COBERTURA' ? 422 : 400;

            return Response::json(['ok' => false, 'error' => $resultado['error']], $estado);
        }

        return Response::json(['ok' => true, 'data' => $resultado['data']]);
    }

    /** GET /buscar — pantalla con mapa y fichas. */
    public function pagina(Request $peticion): Response
    {
        $lat = (float) $peticion->query('lat', 0);
        $lng = (float) $peticion->query('lng', 0);

        // Sin coordenadas se usa el centro de la zona piloto.
        if ($lat === 0.0 && $lng === 0.0) {
            $lat = 19.4326;
            $lng = -99.1332;
        }

        $resultado = $this->servicio->buscar([
            'q'            => $peticion->query('q', ''),
            'categoria_id' => $peticion->query('categoria_id'),
            'lat'          => $lat,
            'lng'          => $lng,
            'radio'        => $peticion->query('radio', BusquedaService::RADIO_DEFAULT),
            'orden'        => $peticion->query('orden', 'distancia'),
            'page'         => $peticion->query('page', 1),
        ]);

        return $this->vista('busqueda/index', [
            'titulo'     => 'Buscar productos',
            'data'       => $resultado['ok'] ? $resultado['data'] : null,
            'error'      => $resultado['ok'] ? null : ($resultado['error']['message'] ?? 'Error al buscar.'),
            'filtros'    => [
                'q'            => (string) $peticion->query('q', ''),
                'categoria_id' => (int) ($peticion->query('categoria_id') ?? 0),
                'radio'        => (float) $peticion->query('radio', BusquedaService::RADIO_DEFAULT),
                'orden'        => (string) $peticion->query('orden', 'distancia'),
            ],
            'lat'        => $lat,
            'lng'        => $lng,
            'categorias' => $this->servicio->categorias(),
        ]);
    }

    /** GET /mayoristas/{uuid} — perfil público. */
    public function mayorista(Request $peticion, array $args): Response
    {
        $perfil = $this->servicio->mayorista((string) $args['uuid']);

        if ($perfil === null) {
            return $this->vista('busqueda/no-encontrado', ['titulo' => 'Mayorista no encontrado'], 404);
        }

        return $this->vista('busqueda/mayorista', [
            'titulo'  => $perfil['nombre_comercial'],
            'perfil'  => $perfil,
            'usuario' => Session::obtener('user_id', null),
        ]);
    }
}
