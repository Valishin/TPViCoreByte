<?php

namespace App\Enums;

/** Cómo se cobran los extras de una mitad. */
enum HalfAndHalfModifierPricing: string
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
}
