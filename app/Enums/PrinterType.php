<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Uso de una impresora. */
enum PrinterType: string implements HasLabel
{
    case Ticket = 'ticket';
    case Kitchen = 'cocina';
    case Bar = 'barra';

    public function label(): string
    {
        return match ($this) {
            self::Ticket => 'Ticket',
            self::Kitchen => 'Cocina',
            self::Bar => 'Barra',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
