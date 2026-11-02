<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Datos fiscales obligatorios para emitir (pasos 1 y 2 de la puesta en
 * marcha): los que salen en cada ticket (RD 1619/2012, art. 7) y los que
 * decide el régimen y la fecha de VERI*FACTU.
 */
final class FiscalSetup
{
    /** @return list<string> lo que falta, en palabras del encargado (vacío = completo) */
    public static function missingCompany(?Setting $s = null): array
    {
        $s ??= Setting::current();

        return array_keys(array_filter([
            'nombre comercial' => blank($s->trade_name),
            'razón social' => blank($s->legal_name),
            'NIF válido' => ! TaxId::isValidSpanish($s->tax_id),
            'dirección' => blank($s->address),
            'código postal' => blank($s->postal_code),
            'población' => blank($s->city),
        ]));
    }

    /** @return list<string> */
    public static function missingFiscal(?Setting $s = null): array
    {
        $s ??= Setting::current();

        return array_keys(array_filter([
            'régimen de IVA' => ! in_array($s->vat_regime_key, ['01', '20'], true),
            'si declara Sociedades' => $s->corporate_tax === null,
        ]));
    }

    /** @return list<string> */
    public static function missing(?Setting $s = null): array
    {
        $s ??= Setting::current();

        return [...self::missingCompany($s), ...self::missingFiscal($s)];
    }
}
