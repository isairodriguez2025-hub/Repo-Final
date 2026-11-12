<?php

declare(strict_types=1);

namespace App\Core;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;

/**
 * Log estructurado en JSON, un objeto por línea, en storage/logs/.
 * Cada entrada lleva correlation_id para poder rastrear una petición completa.
 */
final class Logger
{
    private MonologLogger $logger;

    public function __construct(string $directorio, private readonly string $correlationId)
    {
        if (!is_dir($directorio)) {
            mkdir($directorio, 0775, true);
        }

        $archivo = rtrim($directorio, '/') . '/app-' . date('Y-m-d') . '.log';

        $handler = new StreamHandler($archivo, Level::Debug);
        $handler->setFormatter(new JsonFormatter());

        $this->logger = new MonologLogger('didi2');
        $this->logger->pushHandler($handler);
        // Monolog 3 entrega un LogRecord, no un arreglo: se modifica su
        // propiedad extra y se devuelve el mismo objeto.
        $this->logger->pushProcessor(function (LogRecord $registro): LogRecord {
            $registro->extra['correlation_id'] = $this->correlationId;
            $registro->extra['user_id'] = $_SESSION['user_id'] ?? null;
            $registro->extra['route'] = $_SERVER['REQUEST_URI'] ?? null;

            return $registro;
        });
    }

    public function info(string $mensaje, array $contexto = []): void
    {
        $this->logger->info($mensaje, $contexto);
    }

    public function warning(string $mensaje, array $contexto = []): void
    {
        $this->logger->warning($mensaje, $contexto);
    }

    public function error(string $mensaje, array $contexto = []): void
    {
        $this->logger->error($mensaje, $contexto);
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }
}
