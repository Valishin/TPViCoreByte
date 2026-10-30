<?php

namespace App\Support;

use App\Exceptions\InvoiceRequiredException;
use App\Models\Customer;
use App\Models\Setting;
use InvalidArgumentException;

/** Reglas de facturación del RD 1619/2012 que se comprueban al emitir. */
final class InvoiceRules
{
    /**
     * Factura simplificada (ticket) hasta 3.000 € IVA incluido en hostelería y
     * restauración (art. 4.2.e) y en ventas o servicios a domicilio del
     * consumidor (art. 4.2.c). Por encima, factura completa con datos.
     */
    public const SIMPLIFIED_LIMIT = 300000;

    /** @throws InvoiceRequiredException si el total no admite ticket */
    public static function assertSimplifiedAllowed(int $total): void
    {
        if ($total > self::SIMPLIFIED_LIMIT) {
            throw new InvoiceRequiredException($total);
        }
    }

    /** El destinatario tiene los datos del art. 6.1.c, d, e (domicilio según el ajuste). */
    public static function assertRecipient(Customer $customer): void
    {
        if ($customer->isAnonymized() || ! $customer->hasFiscalData(Setting::current()->invoice_require_address)) {
            throw new InvalidArgumentException('Faltan datos fiscales del destinatario (nombre o razón social, NIF y domicilio).');
        }
    }

    /**
     * Precio unitario SIN IVA en diezmilésimas de euro (art. 6.1.f): 4 decimales.
     *
     * @param  float|int  $grossCents  precio unitario con IVA, en céntimos
     */
    public static function netUnitE4(float|int $grossCents, int $vatRateBp): int
    {
        return (int) round($grossCents * 100 * 10000 / (10000 + $vatRateBp));
    }
}
