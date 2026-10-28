<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Precio de una pizza mitad y mitad. */
enum HalfAndHalfPricing: string implements HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }
}
