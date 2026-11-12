<?php

declare(strict_types=1);

namespace App\Modules\Sistema;

use App\Core\Request;
use App\Core\Response;

/**
 * Sirve los archivos de public/assets.
 *
 * El front controller atiende todas las rutas, así que sin esto el CSS y el JS
 * de Leaflet darían 404. Se resuelve la ruta real y se comprueba que quede
 * dentro de la carpeta permitida: así un `../` no puede salirse a leer el .env.
 */
final class StaticController
{
    private const MIME = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'svg'   => 'image/svg+xml',
        'webp'  => 'image/webp',
        'ico'   => 'image/x-icon',
        'woff2' => 'font/woff2',
    ];

    public function __construct(private readonly string $directorio)
    {
    }

    public function servir(Request $peticion, array $args): Response
    {
        $relativa = (string) ($args['ruta'] ?? '');
        $extension = strtolower(pathinfo($relativa, PATHINFO_EXTENSION));

        if (!isset(self::MIME[$extension])) {
            return Response::json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO', 'message' => 'Archivo no encontrado.']], 404);
        }

        $base = realpath($this->directorio);
        $ruta = realpath($this->directorio . '/' . $relativa);

        // realpath resuelve los "..": si el resultado no empieza por la carpeta
        // base, la petición intentaba salirse.
        if ($base === false || $ruta === false || !str_starts_with($ruta, $base . DIRECTORY_SEPARATOR)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO', 'message' => 'Archivo no encontrado.']], 404);
        }

        if (!is_file($ruta)) {
            return Response::json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADO', 'message' => 'Archivo no encontrado.']], 404);
        }

        return Response::archivo($ruta, self::MIME[$extension]);
    }
}
