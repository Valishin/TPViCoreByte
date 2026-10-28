<?php

namespace App\Enums;

/** Forma de pago. */
enum PaymentMethod: string
{
    case Cash = 'efectivo';
    case Card = 'tarjeta';
    case Bizum = 'bizum';
    case Other = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::Bizum => 'Bizum',
            self::Other => 'Otro',
        };
    }
}
