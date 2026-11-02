<?php

namespace App\Support;

/**
 * Tipo de instalación (TPV_INSTALL_MODE, lo pone install.sh):
 *  - cliente: documentos reales; sin datos fiscales no se emite y se aplican
 *    las fechas de VERI*FACTU (EmissionGuard).
 *  - demo: datos de ejemplo; VERI*FACTU apagado, sin avisos ni bloqueos.
 */
final class InstallMode
{
    public static function isDemo(): bool
    {
        return config('tpv.install_mode') === 'demo';
    }

    public static function isClient(): bool
    {
        return ! self::isDemo();
    }
}
