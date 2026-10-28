<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cómo se cobran los extras de una mitad. */
enum HalfAndHalfModifierPricing: string implements HasLabel
{
    case Full = 'entero';
    case Half = 'mitad';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Precio entero',
            self::Half => 'Mitad de precio',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
