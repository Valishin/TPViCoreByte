<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Tipo de documento fiscal (tabla tickets). Todos son "facturas" en el sentido
 * del RD 1619/2012: simplificadas (art. 4 y 7) o completas (art. 6), y sus
 * rectificativas (art. 15).
 *
 * Tipo de VERI*FACTU de cada uno (TipoFactura, para la fase de VERI*FACTU):
 *   simplificada            F2  factura simplificada (ticket)
 *   rectificativa           R5  rectificativa de facturas simplificadas
 *   completa                F1  factura completa (art. 6)
 *   completa_canje          F3  factura emitida en sustitución de simplificadas (canje, art. 15.6)
 *   rectificativa_completa  R1-R4 según el motivo (RectificationReason::verifactuType()):
 *                           R1 error fundado en derecho y art. 80 Uno, Dos y Seis LIVA
 *                           R2 art. 80.Tres LIVA (concurso)
 *                           R3 art. 80.Cuatro LIVA (créditos incobrables)
 *                           R4 resto
 */
enum TicketType: string implements HasLabel
{
    case Simplified = 'simplificada';
    case Rectifying = 'rectificativa';
    case Invoice = 'completa';
    case InvoiceExchange = 'completa_canje';
    case InvoiceRectifying = 'rectificativa_completa';

    public function label(): string
    {
        return match ($this) {
            self::Simplified => 'Factura simplificada',
            self::Rectifying => 'Rectificativa',
            self::Invoice => 'Factura',
            self::InvoiceExchange => 'Factura (canje de ticket)',
            self::InvoiceRectifying => 'Factura rectificativa',
        };
    }

    /** Factura completa (con datos del destinatario, art. 6), incluidas sus rectificativas. */
    public function isFullInvoice(): bool
    {
        return in_array($this, [self::Invoice, self::InvoiceExchange, self::InvoiceRectifying], true);
    }

    /** Documentos que emite un pedido al cobrarse o darle salida: ticket o factura directa. */
    public static function orderDocuments(): array
    {
        return [self::Simplified, self::Invoice];
    }

    public function isRectifying(): bool
    {
        return $this === self::Rectifying || $this === self::InvoiceRectifying;
    }

    /** El tipo rectificativo que corresponde a este documento (art. 15). */
    public function rectifyingType(): self
    {
        return $this->isFullInvoice() ? self::InvoiceRectifying : self::Rectifying;
    }

    /** TipoFactura de VERI*FACTU (para R1-R4 manda el motivo de la rectificación). */
    public function verifactuType(?RectificationReason $reason = null): string
    {
        return match ($this) {
            self::Simplified => 'F2',
            self::Rectifying => 'R5',
            self::Invoice => 'F1',
            self::InvoiceExchange => 'F3',
            self::InvoiceRectifying => ($reason ?? RectificationReason::Other)->verifactuType(),
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }
}
