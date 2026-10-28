<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de una impresora según el último envío. */
enum PrinterStatus: string implements HasLabel
{
    case Ok = 'ok';
    case Error = 'error';
    case Unknown = 'desconocido';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'Funciona',
            self::Error => 'Con error',
            self::Unknown => 'Sin comprobar',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
