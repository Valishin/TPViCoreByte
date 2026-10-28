<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de una sesión de caja. */
enum CashSessionStatus: string implements HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }
}
