<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Contenedor de dependencias mínimo: guarda instancias ya construidas y las
 * devuelve siempre iguales (singleton). Suficiente para este proyecto y sin
 * magia que después nadie entiende.
 */
final class Container
{
    /** @var array<string, mixed> */
    private array $instancias = [];

    public function set(string $id, mixed $valor): void
    {
        $this->instancias[$id] = $valor;
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->instancias)) {
            throw new RuntimeException("No hay nada registrado con el id '{$id}' en el contenedor.");
        }

        return $this->instancias[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instancias);
    }
}
