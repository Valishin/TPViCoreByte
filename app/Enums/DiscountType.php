<?php

namespace App\Enums;

/** Tipo de descuento. Porcentaje en puntos básicos (1000 = 10 %), importe en céntimos. */
enum DiscountType: string
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
}
