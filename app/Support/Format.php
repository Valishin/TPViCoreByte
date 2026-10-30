<?php

namespace App\Support;

/**
 * Conversión entre lo que ve el hostelero ("12,50 €", "10 %") y lo que se
 * guarda (céntimos y puntos básicos). Solo enteros y cadenas: nunca floats.
 */
final class Format
{
    /** 1250 → "12,50 €" */
    public static function money(?int $cents): string
    {
        return $cents === null ? '—' : self::decimal($cents, thousands: true).' €';
    }

    /** 1250 → "12,50" (para rellenar un campo) */
    public static function moneyInput(?int $cents): ?string
    {
        return $cents === null ? null : self::decimal($cents, thousands: false);
    }

    /**
     * Precio unitario sin IVA guardado en diezmilésimas de euro (art. 6.1.f):
     * con 2 decimales si no pierde precisión y con hasta 4 (sin ceros
     * sobrantes) si los necesita. 90000 → "9,00 €", 90909 → "9,0909 €",
     * 90950 → "9,095 €".
     */
    public static function unitNet(?int $e4): string
    {
        if ($e4 === null) {
            return '—';
        }
        $sign = $e4 < 0 ? '-' : '';
        $abs = abs($e4);
        $euros = intdiv($abs, 10000);
        $dec = str_pad((string) ($abs % 10000), 4, '0', STR_PAD_LEFT);
        $dec = substr($dec, 2) === '00' ? substr($dec, 0, 2) : rtrim($dec, '0');

        return $sign.number_format($euros, 0, ',', '.').','.$dec.' €';
    }

    /** 1000 → "10 %", 1250 → "12,5 %" */
    public static function percent(?int $basisPoints): string
    {
        return $basisPoints === null ? '—' : self::percentInput($basisPoints).' %';
    }

    /** 1000 → "10", 1250 → "12,5" */
    public static function percentInput(?int $basisPoints): ?string
    {
        if ($basisPoints === null) {
            return null;
        }

        $text = self::decimal($basisPoints, thousands: false); // "12,50"
        if (str_ends_with($text, ',00')) {
            return substr($text, 0, -3);
        }

        return str_ends_with($text, '0') ? substr($text, 0, -1) : $text;
    }

    /**
     * "12,50" | "12.50" | "12,5" | "12" | "1.234,56" | "12,50 €" → 1250.
     * null si el texto no es un importe válido (más de 2 decimales, letras...).
     */
    public static function parseMoney(mixed $value): ?int
    {
        return self::parseDecimal($value);
    }

    /** "10" | "10,5" | "10 %" → 1000 | 1050. */
    public static function parsePercent(mixed $value): ?int
    {
        return self::parseDecimal($value);
    }

    /** Número con 2 decimales implícitos → texto con coma decimal. */
    private static function decimal(int $value, bool $thousands): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        $units = (string) intdiv($value, 100);
        if ($thousands) {
            $units = strrev(implode('.', str_split(strrev($units), 3)));
        }

        return $sign.$units.','.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Texto con hasta 2 decimales → entero ×100. */
    private static function parseDecimal(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value * 100;
        }
        if (! is_string($value)) {
            return null;
        }

        $text = str_replace([' ', "\u{00A0}", '€', '%'], '', trim($value));
        if ($text === '') {
            return null;
        }

        if (str_contains($text, ',')) {
            // La coma es el decimal; los puntos, separador de miles.
            $text = str_replace('.', '', $text);
            $text = str_replace(',', '.', $text);
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $text)) {
            // "1.250" → miles
            $text = str_replace('.', '', $text);
        }

        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $text, $m)) {
            return null;
        }

        $cents = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$cents : $cents;
    }
}
