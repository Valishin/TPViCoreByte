<?php

namespace App\Services\Verifactu;

use App\Exceptions\VerifactuRequiredException;
use App\Models\Setting;

/**
 * Obligación de VERI*FACTU (decisión Q5 del plan): en producción, si no está
 * activo, se avisa desde 30 días antes de la fecha de obligación del cliente y,
 * desde ese día, no se emite ningún documento. En demos y desarrollo, nunca.
 */
final class EmissionGuard
{
    public static function enforced(): bool
    {
        return app()->isProduction();
    }

    public static function assertCanIssue(?Setting $settings = null): void
    {
        if (! self::enforced() || Verifactu::active($settings) || now()->lessThan(Verifactu::obligationDate())) {
            return;
        }

        throw new VerifactuRequiredException('No se pueden emitir tickets ni facturas: desde el '.Verifactu::obligationDate()->format('d/m/Y')
            .' es obligatorio VERI*FACTU (RD 1007/2023) y no está activo. Avisa al encargado: /admin → VERI*FACTU.');
    }

    /** Aviso previo: días que faltan para la obligación, o null si no hay que avisar. */
    public static function warningDaysLeft(?Setting $settings = null): ?int
    {
        if (! self::enforced() || Verifactu::active($settings)) {
            return null;
        }
        $days = (int) now()->startOfDay()->diffInDays(Verifactu::obligationDate(), false);

        return $days <= (int) config('verifactu.obligation_warning_days') ? max(0, $days) : null;
    }
}
