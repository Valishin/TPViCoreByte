<?php

namespace App\Support;

use App\Enums\TaxIdType;

/**
 * Identificadores fiscales del destinatario de una factura (art. 6.1.d RD 1619/2012).
 *
 *  - NIF  (personas físicas): 8 cifras + letra de control (módulo 23).
 *  - NIE  (extranjeros): X, Y o Z (→ 0, 1, 2) + 7 cifras + letra (módulo 23).
 *    También K, L y M (NIF especiales) + 7 cifras + letra.
 *  - CIF  (entidades): letra de tipo + 7 cifras + control (dígito o letra).
 *  - NIF-IVA de la UE: prefijo de país + formato del país (sin consultar VIES).
 *  - Otro (pasaporte…): sin validar, solo longitud razonable.
 */
final class TaxId
{
    private const LETTERS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** Formato de NIF-IVA por país de la UE (sin el prefijo). */
    private const EU_VAT = [
        'AT' => 'U\d{8}', 'BE' => '[01]\d{9}', 'BG' => '\d{9,10}', 'CY' => '\d{8}[A-Z]', 'CZ' => '\d{8,10}',
        'DE' => '\d{9}', 'DK' => '\d{8}', 'EE' => '\d{9}', 'EL' => '\d{9}', 'ES' => '[A-Z0-9]\d{7}[A-Z0-9]',
        'FI' => '\d{8}', 'FR' => '[A-HJ-NP-Z0-9]{2}\d{9}', 'HR' => '\d{11}', 'HU' => '\d{8}', 'IE' => '\d{7}[A-W][A-I]?|\d[A-Z+*]\d{5}[A-W]',
        'IT' => '\d{11}', 'LT' => '\d{9}|\d{12}', 'LU' => '\d{8}', 'LV' => '\d{11}', 'MT' => '\d{8}', 'NL' => '\d{9}B\d{2}',
        'PL' => '\d{10}', 'PT' => '\d{9}', 'RO' => '\d{2,10}', 'SE' => '\d{12}', 'SI' => '\d{8}', 'SK' => '\d{10}',
        'XI' => '\d{9}|\d{12}|GD\d{3}|HA\d{3}', // Irlanda del Norte (mercancías)
    ];

    /** Mayúsculas y sin espacios, guiones ni puntos. */
    public static function normalize(?string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s.\-\/]+/', '', (string) $value));

        return $value === '' ? null : $value;
    }

    /** Tipo detectado de un identificador español (null si no lo parece). */
    public static function detect(?string $value): ?TaxIdType
    {
        $v = self::normalize($value) ?? '';

        return match (true) {
            (bool) preg_match('/^\d{8}[A-Z]$/', $v) => TaxIdType::Nif,
            (bool) preg_match('/^[XYZKLM]\d{7}[A-Z]$/', $v) => TaxIdType::Nie,
            (bool) preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $v) => TaxIdType::Cif,
            (bool) preg_match('/^[A-Z]{2}[A-Z0-9+*]{2,13}$/', $v) && isset(self::EU_VAT[substr($v, 0, 2)]) => TaxIdType::VatEu,
            default => null,
        };
    }

    public static function isValid(?string $value, ?TaxIdType $type = null): bool
    {
        $v = self::normalize($value);
        if ($v === null) {
            return false;
        }

        return match ($type ?? self::detect($v)) {
            TaxIdType::Nif => self::validNif($v),
            TaxIdType::Nie => self::validNie($v),
            TaxIdType::Cif => self::validCif($v),
            TaxIdType::VatEu => self::validEuVat($v),
            TaxIdType::Other => strlen($v) >= 4 && strlen($v) <= 20,
            null => false,
        };
    }

    /** Mensaje de error legible, o null si es válido. */
    /** NIF español (NIF, NIE o CIF) válido: el que exige la AEAT al emisor (VERI*FACTU). */
    public static function isValidSpanish(?string $value): bool
    {
        return in_array(self::detect($value), [TaxIdType::Nif, TaxIdType::Nie, TaxIdType::Cif], true) && self::isValid($value);
    }

    public static function error(?string $value, ?TaxIdType $type = null): ?string
    {
        if (self::isValid($value, $type)) {
            return null;
        }
        $type ??= self::detect($value);

        return match ($type) {
            TaxIdType::Nif => 'El NIF no es válido: revisa las cifras y la letra.',
            TaxIdType::Nie => 'El NIE no es válido: revisa las cifras y la letra.',
            TaxIdType::Cif => 'El CIF no es válido: revisa el dígito o la letra de control.',
            TaxIdType::VatEu => 'El NIF-IVA no tiene el formato del país.',
            TaxIdType::Other => 'El documento debe tener entre 4 y 20 caracteres.',
            null => 'No es un NIF, NIE, CIF ni NIF-IVA de la UE válido.',
        };
    }

    public static function validNif(string $v): bool
    {
        return (bool) preg_match('/^(\d{8})([A-Z])$/', $v, $m) && self::LETTERS[(int) $m[1] % 23] === $m[2];
    }

    public static function validNie(string $v): bool
    {
        if (! preg_match('/^([XYZKLM])(\d{7})([A-Z])$/', $v, $m)) {
            return false;
        }
        $prefix = ['X' => '0', 'Y' => '1', 'Z' => '2', 'K' => '', 'L' => '', 'M' => ''][$m[1]];

        return self::LETTERS[(int) ($prefix.$m[2]) % 23] === $m[3];
    }

    /**
     * CIF: suma de pares + suma de dígitos de (impares × 2) → control = (10 − suma % 10) % 10.
     * Letra de control (JABCDEFGHI) para P, Q, R, S, N, W (y K); dígito para A, B, E, H;
     * cualquiera de los dos para el resto.
     */
    public static function validCif(string $v): bool
    {
        if (! preg_match('/^([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])$/', $v, $m)) {
            return false;
        }
        [$type, $digits, $control] = [$m[1], $m[2], $m[3]];
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $n = (int) $digits[$i];
            $sum += $i % 2 === 0 ? array_sum(str_split((string) ($n * 2))) : $n;
        }
        $check = (10 - $sum % 10) % 10;
        $letter = 'JABCDEFGHI'[$check];

        return match (true) {
            str_contains('PQRSNW', $type) => $control === $letter,
            str_contains('ABEH', $type) => $control === (string) $check,
            default => $control === (string) $check || $control === $letter,
        };
    }

    public static function validEuVat(string $v): bool
    {
        $country = substr($v, 0, 2);
        if (! isset(self::EU_VAT[$country])) {
            return false;
        }
        if (! preg_match('/^(?:'.self::EU_VAT[$country].')$/', substr($v, 2))) {
            return false;
        }

        // ES: además, el NIF/NIE/CIF de dentro debe ser válido.
        return $country !== 'ES' || self::isValid(substr($v, 2));
    }
}
