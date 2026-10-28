<?php

namespace App\Support;

/**
 * Normaliza teléfonos a formato E.164 para buscar clientes:
 *  "612 34 56 78", "+34 612-345-678", "0034612345678", "34612345678" → "+34612345678".
 * Números con prefijo internacional distinto se conservan tal cual (+ y dígitos).
 */
final class PhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = trim($phone);
        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus) {
            return '+'.$digits;
        }

        // Número español de 9 cifras (fijos 8/9, móviles 6/7).
        if (strlen($digits) === 9 && preg_match('/^[6-9]/', $digits)) {
            return '+34'.$digits;
        }

        // 34 + número español sin el "+".
        if (strlen($digits) === 11 && preg_match('/^34[6-9]/', $digits)) {
            return '+'.$digits;
        }

        return $digits;
    }
}
