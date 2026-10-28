<?php

namespace App\Enums;

/** Tipo de factura emitida. */
enum TicketType: string
{
    case Simplified = 'simplificada';
    case Rectifying = 'rectificativa';

    public function label(): string
    {
        return match ($this) {
            self::Simplified => 'Factura simplificada',
            self::Rectifying => 'Rectificativa',
        };
    }
}
