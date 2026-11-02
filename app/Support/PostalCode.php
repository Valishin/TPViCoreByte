<?php

namespace App\Support;

use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Setting;

/**
 * Código postal español → provincia (las dos primeras cifras) y municipio.
 * El municipio sale, por este orden, de la zona de reparto (/admin: cada CP
 * con su municipio), de las direcciones ya guardadas con ese CP (el más usado)
 * o, si es el CP del local, de su población. Sin conexión a internet.
 */
final class PostalCode
{
    private const PROVINCES = [
        '01' => 'Araba/Álava', '02' => 'Albacete', '03' => 'Alicante', '04' => 'Almería', '05' => 'Ávila',
        '06' => 'Badajoz', '07' => 'Illes Balears', '08' => 'Barcelona', '09' => 'Burgos', '10' => 'Cáceres',
        '11' => 'Cádiz', '12' => 'Castellón', '13' => 'Ciudad Real', '14' => 'Córdoba', '15' => 'A Coruña',
        '16' => 'Cuenca', '17' => 'Girona', '18' => 'Granada', '19' => 'Guadalajara', '20' => 'Gipuzkoa',
        '21' => 'Huelva', '22' => 'Huesca', '23' => 'Jaén', '24' => 'León', '25' => 'Lleida',
        '26' => 'La Rioja', '27' => 'Lugo', '28' => 'Madrid', '29' => 'Málaga', '30' => 'Murcia',
        '31' => 'Navarra', '32' => 'Ourense', '33' => 'Asturias', '34' => 'Palencia', '35' => 'Las Palmas',
        '36' => 'Pontevedra', '37' => 'Salamanca', '38' => 'Santa Cruz de Tenerife', '39' => 'Cantabria', '40' => 'Segovia',
        '41' => 'Sevilla', '42' => 'Soria', '43' => 'Tarragona', '44' => 'Teruel', '45' => 'Toledo',
        '46' => 'Valencia', '47' => 'Valladolid', '48' => 'Bizkaia', '49' => 'Zamora', '50' => 'Zaragoza',
        '51' => 'Ceuta', '52' => 'Melilla',
    ];

    public static function valid(?string $postalCode): bool
    {
        return (bool) preg_match('/^\d{5}$/', trim((string) $postalCode)) && isset(self::PROVINCES[substr(trim($postalCode), 0, 2)]);
    }

    public static function province(?string $postalCode): ?string
    {
        return self::valid($postalCode) ? self::PROVINCES[substr(trim($postalCode), 0, 2)] : null;
    }

    /** Municipio conocido para ese CP (zona de reparto → direcciones guardadas → el local) o null. */
    public static function city(?string $postalCode): ?string
    {
        if (! self::valid($postalCode)) {
            return null;
        }
        $postalCode = trim($postalCode);
        if ($zone = DeliveryZone::cityForPostalCode($postalCode)) {
            return $zone;
        }
        $known = CustomerAddress::query()->where('postal_code', $postalCode)->whereNotNull('city')->where('city', '<>', '')
            ->selectRaw('city, count(*) as uses')->groupBy('city')->orderByDesc('uses')->orderBy('city')->value('city');
        if ($known) {
            return $known;
        }
        $settings = Setting::query()->first();

        return $settings && trim((string) $settings->postal_code) === $postalCode && filled($settings->city) ? $settings->city : null;
    }

    /** «17220 Sant Feliu de Guíxols (Girona)»: lo que va en el ticket de domicilio. */
    public static function place(?string $postalCode, ?string $city): string
    {
        $city = trim((string) $city) ?: (string) self::city($postalCode);
        $province = self::province($postalCode);
        $text = trim(trim((string) $postalCode).' '.$city);
        if ($province && mb_strtolower($province) !== mb_strtolower($city)) {
            $text .= " ({$province})";
        }

        return trim($text);
    }
}
