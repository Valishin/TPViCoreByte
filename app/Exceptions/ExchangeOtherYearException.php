<?php

namespace App\Exceptions;

use RuntimeException;

/** Canje de un ticket de otro año natural: se permite (art. 15.6 no fija plazo) pero hay que confirmarlo. */
class ExchangeOtherYearException extends RuntimeException
{
    public function __construct(public readonly int $year)
    {
        parent::__construct("Este ticket es de {$year}: la factura se emitirá con fecha de hoy y fecha de operación de {$year}. ¿Seguro que quieres canjearlo?");
    }
}
