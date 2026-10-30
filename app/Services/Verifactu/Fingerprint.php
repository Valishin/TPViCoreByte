<?php

namespace App\Services\Verifactu;

/**
 * Huella o «hash» de los registros de facturación (Orden HAC/1177/2024 art. 13;
 * «Especificaciones técnicas para la generación de la huella», v0.1.2).
 *
 * - Algoritmo SHA-256 (lista L12, valor 01).
 * - Entrada: «nombreCampo=valor&…» con los campos y el orden del apartado 3,
 *   valores sin espacios al principio ni al final; si un campo no tiene valor,
 *   solo «nombre=» (primer registro: «Huella=»). Codificación UTF-8.
 * - Salida: hexadecimal en mayúsculas, 64 caracteres (apartado 5).
 */
final class Fingerprint
{
    public const ALTA_FIELDS = ['IDEmisorFactura', 'NumSerieFactura', 'FechaExpedicionFactura', 'TipoFactura', 'CuotaTotal', 'ImporteTotal', 'Huella', 'FechaHoraHusoGenRegistro'];

    public const ANULACION_FIELDS = ['IDEmisorFacturaAnulada', 'NumSerieFacturaAnulada', 'FechaExpedicionFacturaAnulada', 'Huella', 'FechaHoraHusoGenRegistro'];

    /** @param  array<string, string|null>  $values  campo => valor tal como va en el XML */
    public static function alta(array $values): string
    {
        return self::hash(self::input(self::ALTA_FIELDS, $values));
    }

    /** @param  array<string, string|null>  $values */
    public static function anulacion(array $values): string
    {
        return self::hash(self::input(self::ANULACION_FIELDS, $values));
    }

    /** Cadena de entrada del algoritmo (apartado 3). */
    public static function input(array $fields, array $values): string
    {
        return implode('&', array_map(fn (string $field) => $field.'='.trim((string) ($values[$field] ?? '')), $fields));
    }

    public static function hash(string $input): string
    {
        return strtoupper(hash('sha256', $input));
    }
}
