<?php

declare(strict_types=1);

namespace App\Modules\Catalogo\Excepciones;

use InvalidArgumentException;

/** La cantidad pedida no alcanza el mínimo del producto o no es múltiplo de venta. */
final class EscalaNoAplicaException extends InvalidArgumentException
{
}
