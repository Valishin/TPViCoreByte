<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Estado de un documento fiscal. Solo admite emitido → rectificado (art. 15)
 * o emitido → canjeado (ticket sustituido por una factura completa, art. 15.6).
 */
enum TicketStatus: string implements HasLabel
{
    case Issued = 'emitido';
    case Rectified = 'rectificado';
    case Exchanged = 'canjeado';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitido',
            self::Rectified => 'Rectificado',
            self::Exchanged => 'Canjeado por factura',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
