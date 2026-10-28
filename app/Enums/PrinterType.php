<?php

namespace App\Enums;

/** Uso de una impresora. */
enum PrinterType: string
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
}
