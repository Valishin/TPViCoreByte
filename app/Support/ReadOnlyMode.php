<?php

namespace App\Support;

/**
 * "Modo solo consulta" (p. ej. licencia caducada). Todavía no se activa en
 * ningún caso; cuando exista, la app bloqueará las escrituras pero SIEMPRE
 * dejará consultar y exportar los datos fiscales (obligación legal de
 * conservarlos y entregarlos).
 *
 * Las funciones permitidas en ese modo se marcan con AllowedInReadOnly.
 */
final class ReadOnlyMode
{
    public static function active(): bool
    {
        return (bool) config('tpv.read_only', false);
    }
}
