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
    case Online = 'online'; // pagado por la web (pedidos online en el futuro)

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Card => 'Tarjeta',
            self::Bizum => 'Bizum',
            self::Other => 'Otro',
            self::Online => 'Online',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
