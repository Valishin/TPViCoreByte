<?php

namespace App\Services\Tickets;

use App\Enums\PaymentStatus;
use App\Models\Ticket;

/**
 * Qué se hace con el documento de un domicilio no entregado (respuesta a la
 * duda Q3 del plan; pendiente de confirmar con el asesor: docs/verifactu/dudas-asesor.md).
 *
 * - Sin cobrar: no hubo venta ni entrega → anulación (RRSIF 11.1; FAQ 17.2.d:
 *   el ticket no llegó al cliente).
 * - Cobrado antes (prepagado): el pago anticipado devengó el IVA (LIVA 75.Dos)
 *   → rectificativo R5 con devolución.
 *
 * Si el asesor indica otro criterio, se cambia solo esta función.
 */
final class NotDeliveredPolicy
{
    public const ANNUL = 'anulacion';

    public const RECTIFY = 'rectificativo';

    public static function decide(Ticket $ticket): string
    {
        return $ticket->payment_status === PaymentStatus::Paid ? self::RECTIFY : self::ANNUL;
    }
}
