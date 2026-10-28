<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de descuento. Porcentaje en puntos básicos (1000 = 10 %), importe en céntimos. */
enum DiscountType: string implements HasLabel
{
    case Percentage = 'porcentaje';
    case Amount = 'importe';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Porcentaje',
            self::Amount => 'Importe',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
