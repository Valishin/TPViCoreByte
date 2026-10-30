<?php

namespace App\Exceptions;

use App\Support\Format;
use App\Support\InvoiceRules;
use RuntimeException;

/** Total por encima del límite de la factura simplificada (art. 4.2.e RD 1619/2012): hay que emitir factura completa. */
class InvoiceRequiredException extends RuntimeException
{
    public function __construct(public readonly int $total)
    {
        parent::__construct('El total ('.Format::money($total).') supera '.Format::money(InvoiceRules::SIMPLIFIED_LIMIT)
            .': no se puede emitir ticket. Emite una factura con los datos del cliente.');
    }
}
