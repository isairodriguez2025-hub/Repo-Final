<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

/**
 * Captura cualquier error o excepción, lo registra completo en el log y
 * devuelve al navegador un mensaje genérico con un id de correlación.
 * Nunca se expone la ruta del archivo, la tabla ni el SQL.
 */
final class ErrorHandler
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function registrar(): void
    {
        set_error_handler(function (int $severidad, string $mensaje, string $archivo, int $linea): bool {
            throw new \ErrorException($mensaje, 0, $severidad, $archivo, $linea);
        });

        set_exception_handler(function (Throwable $e): void {
            $this->manejar($e);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logger->error('Error fatal', [
                    'mensaje' => $error['message'],
                    'archivo' => $error['file'],
                    'linea'   => $error['line'],
                ]);
            }
        });
    }

    public function manejar(Throwable $e): void
    {
        $this->logger->error('Excepción no controlada', [
            'tipo'      => $e::class,
            'mensaje'   => $e->getMessage(),
            'archivo'   => $e->getFile(),
            'linea'     => $e->getLine(),
            'traza'     => $e->getTraceAsString(),
        ]);

        $esApi = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api/')
            || str_contains(strtolower($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

        $cuerpo = $esApi
            ? (string) json_encode([
                'ok'    => false,
                'error' => [
                    'code'    => 'SERVER_ERROR',
                    'message' => 'Error interno. Ref: ' . $this->logger->correlationId(),
                ],
            ], JSON_UNESCAPED_UNICODE)
            : '<!doctype html><meta charset="utf-8"><title>Error</title>'
              . '<p style="font-family:system-ui;padding:40px">Ocurrió un error. Ref: '
              . htmlspecialchars($this->logger->correlationId(), ENT_QUOTES, 'UTF-8') . '</p>';

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: ' . ($esApi ? 'application/json' : 'text/html') . '; charset=utf-8');
        }

        echo $cuerpo;
    }
}
