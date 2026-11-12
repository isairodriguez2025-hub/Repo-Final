<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Renderiza plantillas PHP con escape automático.
 *
 * Las variables NO se imprimen directo: se pasan por e() o por el helper que
 * corresponda. El nonce CSP se inyecta en la plantilla para que ningún script
 * inline quede sin autorización.
 */
final class View
{
    public function __construct(
        private readonly string $directorio,
        private readonly string $nonce,
    ) {
    }

    public function render(string $plantilla, array $datos = []): string
    {
        $archivo = rtrim($this->directorio, '/') . '/' . $plantilla . '.php';

        if (!is_file($archivo)) {
            throw new RuntimeException("No existe la plantilla '{$plantilla}'.");
        }

        $nonce = $this->nonce;

        // e() queda disponible dentro de la plantilla.
        $e = static fn (?string $valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        extract($datos, EXTR_SKIP);

        ob_start();

        try {
            require $archivo;
        } catch (\Throwable $e) {
            // Si la plantilla falla, se descarta la salida a medias para que el
            // manejador de errores responda limpio en vez de mezclar HTML.
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
