<?php

namespace App\Enums;

/** Estado de cobro de un ticket. */
enum PaymentStatus: string
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
}
