<?php

namespace App\Services\Verifactu;

use App\Models\Setting;
use App\Support\InstallMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Estado de VERI*FACTU en esta instalación y datos fijos del sistema.
 *
 * Activo = ajuste «VERI*FACTU activo» con fecha de inicio ya alcanzada. Desde
 * ese momento cada documento genera su registro de facturación (RRSIF 9).
 */
final class Verifactu
{
    public const NS = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    public const NS_LR = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

    public const NS_RESPONSE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/RespuestaSuministro.xsd';

    public const NS_QUERY = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/ConsultaLR.xsd';

    public const NS_QUERY_RESPONSE = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/RespuestaConsultaLR.xsd';

    /** IDVersion (lista L15). */
    public const SCHEMA_VERSION = '1.0';

    public static function active(?Setting $settings = null, ?CarbonInterface $at = null): bool
    {
        $settings ??= Setting::current();

        $at ??= now();

        // En demo nunca, aunque la base de datos diga otra cosa (p. ej. una copia de un cliente).
        return InstallMode::isClient()
            && $settings->verifactu_enabled
            && $settings->verifactu_started_at !== null
            && $settings->verifactu_started_at->lessThanOrEqualTo($at)
            // Renuncia (OM 17.3): funciona como VERI*FACTU hasta el final de FechaFinVeriFactu.
            && ($settings->verifactu_end_date === null || $at->lessThanOrEqualTo($settings->verifactu_end_date->copy()->endOfDay()));
    }

    public static function environment(?Setting $settings = null): string
    {
        return ($settings ?? Setting::current())->verifactu_environment === 'produccion' ? 'produccion' : 'pruebas';
    }

    /** Fecha desde la que el cliente está obligado (RDL 15/2025): según declare o no Sociedades (paso 2). */
    public static function obligationDate(?Setting $settings = null): Carbon
    {
        $settings ??= Setting::query()->first();
        $date = match ($settings?->corporate_tax) {
            true => config('verifactu.obligation_dates.sociedades'),
            false => config('verifactu.obligation_dates.resto'),
            default => config('verifactu.obligation_date'),
        };

        return Carbon::parse((string) $date, 'Europe/Madrid')->startOfDay();
    }

    /** Bloque SistemaInformatico (Diseños de registro, hoja 5). */
    public static function system(): array
    {
        return [
            'producer_name' => (string) config('verifactu.producer.name'),
            'producer_nif' => strtoupper((string) config('verifactu.producer.nif')),
            'producer_address' => (string) config('verifactu.producer.address'),
            'name' => mb_substr((string) config('verifactu.system.name'), 0, 30),
            'id' => (string) config('verifactu.system.id'),
            'version' => mb_substr((string) config('verifactu.system.version'), 0, 50),
            'installation' => mb_substr((string) config('verifactu.installation_number'), 0, 100),
            'only_verifactu' => config('verifactu.system.only_verifactu') ? 'S' : 'N',
            'multi_taxpayer' => config('verifactu.system.multi_taxpayer') ? 'S' : 'N',
            'multiple_taxpayers' => 'N',
        ];
    }

    /** Importe en céntimos → «-5.00» (Descripción servicios web 6.8; misma forma en el XML y en la huella). */
    public static function amount(int $cents): string
    {
        return ($cents < 0 ? '-' : '').intdiv(abs($cents), 100).'.'.str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Fecha dd-mm-aaaa en hora peninsular. */
    public static function date(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone('Europe/Madrid')->format('d-m-Y');
    }

    /** FechaHoraHusoGenRegistro: ISO 8601 con el huso aplicado (OM 7.e y 7.g). */
    public static function timestamp(CarbonInterface $at): string
    {
        return $at->copy()->setTimezone('Europe/Madrid')->format('Y-m-d\TH:i:sP');
    }
}
