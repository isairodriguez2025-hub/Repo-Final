<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Caché en archivos con TTL.
 *
 * Se usa para las búsquedas frecuentes (30 s) y el perfil de mayorista (60 s).
 * No requiere Redis ni Memcached: escribe un archivo por clave dentro de
 * storage/cache y lo descarta cuando vence.
 */
final class Cache
{
    public function __construct(
        private readonly string $directorio,
        private readonly int $ttlDefault = 30,
    ) {
        if (!is_dir($this->directorio)) {
            @mkdir($this->directorio, 0775, true);
        }
    }

    /** @return array<string, mixed>|null */
    public function get(string $clave): ?array
    {
        $ruta = $this->ruta($clave);

        if (!is_file($ruta)) {
            return null;
        }

        $contenido = @file_get_contents($ruta);

        if ($contenido === false) {
            return null;
        }

        $datos = json_decode($contenido, true);

        if (!is_array($datos) || !isset($datos['expira'])) {
            @unlink($ruta);

            return null;
        }

        if ($datos['expira'] < time()) {
            @unlink($ruta);

            return null;
        }

        return is_array($datos['valor'] ?? null) ? $datos['valor'] : null;
    }

    public function put(string $clave, array $valor, ?int $ttl = null): void
    {
        $datos = [
            'expira' => time() + ($ttl ?? $this->ttlDefault),
            'valor'  => $valor,
        ];

        @file_put_contents($this->ruta($clave), json_encode($datos, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** Borra todas las claves que empiecen con el prefijo dado. */
    public function limpiarPrefijo(string $prefijo): int
    {
        $borrados = 0;

        foreach (glob($this->directorio . '/*.json') ?: [] as $archivo) {
            $nombre = basename($archivo, '.json');

            if (str_starts_with($nombre, $this->normalizar($prefijo))) {
                @unlink($archivo);
                $borrados++;
            }
        }

        return $borrados;
    }

    private function ruta(string $clave): string
    {
        return $this->directorio . '/' . $this->normalizar($clave) . '.json';
    }

    private function normalizar(string $clave): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $clave) ?? md5($clave);
    }
}
