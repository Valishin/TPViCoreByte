<?php

namespace App\Enums;

/** Estado de un ticket. Solo admite emitido → rectificado. */
enum TicketStatus: string
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
}
