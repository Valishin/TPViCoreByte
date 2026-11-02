<?php

namespace App\Services\Verifactu;

use App\Exceptions\VerifactuRequiredException;
use App\Models\Setting;
use App\Support\FiscalSetup;
use App\Support\InstallMode;

/**
 * Cuándo se pueden emitir tickets y facturas (solo en instalaciones «cliente»;
 * en «demo» nunca se bloquea ni se avisa):
 *
 *  1. Sin los datos fiscales obligatorios (puesta en marcha, pasos 1 y 2).
 *  2. Desde la fecha de obligación (RDL 15/2025: 1/1/2027 con Sociedades,
 *     1/7/2027 en el resto) sin certificado válido.
 *  3. Desde esa fecha sin VERI*FACTU activo.
 *
 * Avisos previos: sin certificado, siempre en /admin y en la caja desde 30
 * días antes de la fecha (VerifactuStatus).
 */
final class EmissionGuard
{
    public static function enforced(): bool
    {
        return InstallMode::isClient();
    }

    public static function assertCanIssue(?Setting $settings = null): void
    {
        if ($reason = self::blocker($settings)) {
            throw new VerifactuRequiredException($reason['message'], $reason['code']);
        }
    }

    /** @return array{code: string, message: string}|null por qué no se puede emitir */
    public static function blocker(?Setting $settings = null): ?array
    {
        if (! self::enforced()) {
            return null;
        }
        $settings ??= Setting::current();

        if ($missing = FiscalSetup::missing($settings)) {
            return ['code' => 'fiscal_data_required', 'message' => 'No se pueden emitir tickets ni facturas: faltan los datos fiscales del local ('
                .implode(', ', $missing).'). Avisa al encargado: /admin → Puesta en marcha.'];
        }
        $date = Verifactu::obligationDate($settings);
        if (now()->lessThan($date)) {
            return null;
        }
        $since = 'desde el '.$date->format('d/m/Y').' es obligatorio VERI*FACTU (RD 1007/2023)';
        if (! self::certificateValid($settings)) {
            return ['code' => 'verifactu_required', 'message' => "No se pueden emitir tickets ni facturas: {$since} y no hay un certificado electrónico válido. Avisa al encargado: /admin → VERI*FACTU."];
        }
        if (! Verifactu::active($settings)) {
            return ['code' => 'verifactu_required', 'message' => "No se pueden emitir tickets ni facturas: {$since} y no está activo. Avisa al encargado: /admin → VERI*FACTU."];
        }

        return null;
    }

    public static function certificateValid(Setting $settings): bool
    {
        return CertificateStore::installed($settings) && (CertificateStore::daysToExpiry($settings) ?? -1) >= 0;
    }

    /** Días que faltan para la fecha de obligación si aún no hay certificado o VERI*FACTU activo; null si no hay que avisar. */
    public static function warningDaysLeft(?Setting $settings = null): ?int
    {
        $settings ??= Setting::current();
        if (! self::enforced() || (Verifactu::active($settings) && self::certificateValid($settings))) {
            return null;
        }
        $days = (int) now()->startOfDay()->diffInDays(Verifactu::obligationDate($settings), false);

        return $days <= (int) config('verifactu.obligation_warning_days') ? max(0, $days) : null;
    }
}
