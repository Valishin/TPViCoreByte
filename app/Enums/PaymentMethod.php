<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Forma de pago. */
enum PaymentMethod: string implements HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }
}
