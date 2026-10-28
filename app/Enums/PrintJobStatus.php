<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de un envío a impresora. */
enum PrintJobStatus: string implements HasLabel
{
    case Pending = 'pendiente';
    case Printed = 'impreso';
    case Failed = 'fallido';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Printed => 'Impreso',
            self::Failed => 'Fallido',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
