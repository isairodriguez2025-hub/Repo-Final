<?php

declare(strict_types=1);

namespace App\Modules\Catalogo\Excepciones;

use InvalidArgumentException;

/** Las escalas de un producto no cumplen las reglas (crecientes, sin solape, valor > 0). */
final class EscalaInvalidaException extends InvalidArgumentException
{
}
