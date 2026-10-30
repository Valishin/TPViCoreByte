<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Estado de un documento fiscal. Solo admite emitido → rectificado (art. 15),
 * emitido → canjeado (ticket sustituido por una factura completa, art. 15.6) o
 * emitido → anulado (emitido por error, sin operación real: RRSIF 11.1).
 */
enum TicketStatus: string implements HasLabel
{
    case Issued = 'emitido';
    case Rectified = 'rectificado';
    case Exchanged = 'canjeado';
    case Annulled = 'anulado';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitido',
            self::Rectified => 'Rectificado',
            self::Exchanged => 'Canjeado por factura',
            self::Annulled => 'Anulado (emitido por error)',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
