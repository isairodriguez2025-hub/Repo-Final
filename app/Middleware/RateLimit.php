<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;

/**
 * Límite de peticiones por IP y ruta, con ventana deslizante.
 *
 * Se guarda en archivos JSON dentro de storage/rate/ porque APCu no siempre
 * está disponible. Para un solo servidor es suficiente; con varios habría que
 * moverlo a Redis.
 */
final class RateLimit implements Middleware
{
    /**
     * @param string|null $campo Campo del formulario que se suma a la clave.
     *        En el login se usa el correo: así dos personas detrás del mismo
     *        túnel o NAT no se bloquean entre sí, y el límite por cuenta lo
     *        sigue aplicando AuthService.
     */
    public function __construct(
        private readonly int $maximo,
        private readonly int $ventanaSegundos,
        private readonly string $directorio,
        private readonly ?string $campo = null,
    ) {
    }

    public function handle(Request $peticion, callable $siguiente): Response
    {
        $sufijo = '';

        if ($this->campo !== null) {
            $valor = $peticion->input($this->campo, '');
            $sufijo = '|' . mb_strtolower(trim((string) $valor));
        }

        $clave = hash('sha256', $peticion->ip() . '|' . $peticion->path() . $sufijo);
        $archivo = rtrim($this->directorio, '/') . '/' . $clave . '.json';

        if (!is_dir($this->directorio)) {
            mkdir($this->directorio, 0775, true);
        }

        $ahora = time();
        $marcas = [];

        if (is_file($archivo)) {
            $previas = json_decode((string) file_get_contents($archivo), true);
            if (is_array($previas)) {
                $marcas = array_values(array_filter(
                    $previas,
                    fn ($t) => is_int($t) && ($ahora - $t) < $this->ventanaSegundos,
                ));
            }
        }

        if (count($marcas) >= $this->maximo) {
            $espera = $this->ventanaSegundos - ($ahora - min($marcas));

            // Al navegador se le devuelve al formulario con aviso; a la API, 429.
            if (str_contains((string) $peticion->header('Accept'), 'text/html')) {
                return Response::redirect($peticion->path() . '?bloqueado=1')
                    ->withHeader('Retry-After', (string) max(1, $espera));
            }

            return Response::json([
                'ok'    => false,
                'error' => ['code' => 'DEMASIADAS_PETICIONES', 'message' => 'Demasiados intentos. Espera un momento.'],
            ], 429)->withHeader('Retry-After', (string) max(1, $espera));
        }

        $marcas[] = $ahora;
        file_put_contents($archivo, json_encode($marcas), LOCK_EX);

        return $siguiente($peticion);
    }
}
