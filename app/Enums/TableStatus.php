<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de una mesa. Solo lo cambian los servicios de pedidos (OrderService). */
enum TableStatus: string implements HasLabel
{
    case Free = 'libre';
    case Occupied = 'ocupada';
    case BillRequested = 'cuenta_pedida';
    case OutOfService = 'fuera_servicio';

    public function label(): string
    {
        return match ($this) {
            self::Free => 'Libre',
            self::Occupied => 'Ocupada',
            self::BillRequested => 'Cuenta pedida',
            self::OutOfService => 'Fuera de servicio',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
