<?php

namespace App\Exceptions;

use App\Support\Format;
use RuntimeException;

/** El domicilio no llega al pedido mínimo de su zona. La SPA ofrece continuar con autorización. */
class MinimumOrderException extends RuntimeException
{
    public function __construct(public readonly string $zone, public readonly int $minimum, public readonly int $amount)
    {
        parent::__construct(sprintf(
            'No llega al pedido mínimo de %s (%s): faltan %s.',
            $zone, Format::money($minimum), Format::money($minimum - $amount),
        ));
    }
}
