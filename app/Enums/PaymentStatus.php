<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de cobro de un ticket. */
enum PaymentStatus: string implements HasLabel
{
    case Paid = 'pagado';
    case Pending = 'pendiente';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Pagado',
            self::Pending => 'Pendiente de cobro',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
