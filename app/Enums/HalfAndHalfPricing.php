<?php

namespace App\Enums;

/** Precio de una pizza mitad y mitad. */
enum HalfAndHalfPricing: string
{
    case MostExpensive = 'mas_cara';
    case Average = 'media';

    public function label(): string
    {
        return match ($this) {
            self::MostExpensive => 'La mitad más cara',
            self::Average => 'Media de las dos mitades',
        };
    }
}
