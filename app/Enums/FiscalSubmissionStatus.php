<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de envío a la AEAT (VeriFactu). */
enum FiscalSubmissionStatus: string implements HasLabel
{
    case Pending = 'pendiente';
    case Sent = 'enviado';
    case Accepted = 'aceptado';
    case AcceptedWithErrors = 'aceptado_con_errores';
    case Rejected = 'rechazado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Sent => 'Enviado',
            self::Accepted => 'Aceptado',
            self::AcceptedWithErrors => 'Aceptado con errores',
            self::Rejected => 'Rechazado',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
