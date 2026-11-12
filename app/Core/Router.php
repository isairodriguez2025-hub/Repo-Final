<?php

declare(strict_types=1);

namespace App\Core;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as RouteParser;
use FastRoute\DataGenerator\GroupCountBased as DataGenerator;

/**
 * Router sobre FastRoute. Cada ruta declara su método, su patrón y una función
 * que recibe (Request, argumentos) y devuelve un Response.
 */
final class Router
{
    private RouteCollector $colector;
    private ?Dispatcher $despachador = null;

    public function __construct()
    {
        $this->colector = new RouteCollector(new RouteParser(), new DataGenerator());
    }

    public function get(string $patron, callable $manejador): void
    {
        $this->colector->addRoute('GET', $patron, $manejador);
    }

    public function post(string $patron, callable $manejador): void
    {
        $this->colector->addRoute('POST', $patron, $manejador);
    }

    public function put(string $patron, callable $manejador): void
    {
        $this->colector->addRoute('PUT', $patron, $manejador);
    }

    public function delete(string $patron, callable $manejador): void
    {
        $this->colector->addRoute('DELETE', $patron, $manejador);
    }

    public function dispatch(Request $peticion): Response
    {
        $this->despachador ??= new Dispatcher\GroupCountBased($this->colector->getData());

        $resultado = $this->despachador->dispatch($peticion->method(), $peticion->path());

        if ($resultado[0] === Dispatcher::NOT_FOUND) {
            return Response::json([
                'ok'    => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'El recurso solicitado no existe.'],
            ], 404);
        }

        if ($resultado[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            return Response::json([
                'ok'    => false,
                'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Método no permitido para esta ruta.'],
            ], 405);
        }

        [$estado, $manejador, $argumentos] = $resultado;

        return $manejador($peticion, $argumentos);
    }
}
