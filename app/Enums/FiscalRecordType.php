<?php

namespace App\Enums;

/** Tipo de registro fiscal (VeriFactu). */
enum FiscalRecordType: string
{
    case Registration = 'alta';
    case Cancellation = 'anulacion';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Alta',
            self::Cancellation => 'Anulación',
        };
    }
}
