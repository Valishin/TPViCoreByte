<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de un pedido. */
enum OrderStatus: string implements HasLabel
{
    case Open = 'abierto';
    case Closed = 'cerrado';
    case Cancelled = 'cancelado';
    // Mesa unida a otra: sus líneas pasaron al pedido merged_into_order_id (sin ticket).
    case Merged = 'unido';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
            self::Cancelled => 'Cancelado',
            self::Merged => 'Unido a otra mesa',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
