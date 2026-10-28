<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de registro fiscal (VeriFactu). */
enum FiscalRecordType: string implements HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }
}
