<?php

namespace App\Exceptions;

use RuntimeException;

/** No hay caja abierta: no se puede cobrar. La API responde 409 con el código. */
class CashSessionRequiredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No hay ninguna caja abierta. Ábrela para poder cobrar.');
    }
}
