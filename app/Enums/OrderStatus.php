<?php

namespace App\Enums;

/** Estado de un pedido. */
enum OrderStatus: string
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
}
