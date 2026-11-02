<?php

namespace App\Exceptions;

use LogicException;

/**
 * No se puede emitir (instalación «cliente»): faltan los datos fiscales o,
 * desde la fecha de obligación, el certificado o VERI*FACTU activo.
 * `reason` va en la respuesta de la API: fiscal_data_required | verifactu_required.
 */
class VerifactuRequiredException extends LogicException
{
    public function __construct(string $message, public readonly string $reason = 'verifactu_required')
    {
        parent::__construct($message);
    }
}
