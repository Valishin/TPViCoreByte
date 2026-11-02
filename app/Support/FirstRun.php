<?php

namespace App\Support;

use App\Models\User;

/**
 * Primer acceso a /admin: mientras no hay ningún usuario se puede crear el
 * administrador desde el propio equipo, la red local o Tailscale
 * (FirstRunController). Sin código de configuración por ahora.
 */
final class FirstRun
{
    public static function available(): bool
    {
        return ! User::query()->exists();
    }
}
