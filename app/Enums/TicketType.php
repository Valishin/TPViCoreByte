<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de factura emitida. */
enum TicketType: string implements HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }
}
