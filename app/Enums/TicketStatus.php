<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Estado de un ticket. Solo admite emitido → rectificado. */
enum TicketStatus: string implements HasLabel
{
    case Issued = 'emitido';
    case Rectified = 'rectificado';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitido',
            self::Rectified => 'Rectificado',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
