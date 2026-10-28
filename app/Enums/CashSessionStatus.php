<?php

namespace App\Enums;

/** Estado de una sesión de caja. */
enum CashSessionStatus: string
{
    case Open = 'abierta';
    case Closed = 'cerrada';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Closed => 'Cerrada',
        };
    }
}
