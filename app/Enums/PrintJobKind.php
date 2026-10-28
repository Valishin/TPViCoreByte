<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Qué se imprime. */
enum PrintJobKind: string implements HasLabel
{
    case Kitchen = 'comanda';
    case Void = 'anulacion';
    case Precheck = 'cuenta';

    public function label(): string
    {
        return match ($this) {
            self::Kitchen => 'Comanda',
            self::Void => 'Anulación',
            self::Precheck => 'Cuenta',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
