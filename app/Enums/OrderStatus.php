<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de un pedido. */
enum OrderStatus: string implements HasLabel
{
    case Open = 'abierto';
    case Closed = 'cerrado';
    case Cancelled = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
