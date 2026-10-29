<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Cómo se conecta una impresora: por red (TCP 9100) o por USB (dispositivo /dev/...). */
enum PrinterConnection: string implements HasLabel
{
    case Network = 'red';
    case Usb = 'usb';

    public function label(): string
    {
        return match ($this) {
            self::Network => 'Red (IP)',
            self::Usb => 'USB',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
