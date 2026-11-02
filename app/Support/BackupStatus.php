<?php

namespace App\Support;

use App\Models\BackupRun;
use App\Services\Backup\BackupDestination;

/** Estado de las copias para el aviso de /admin. */
final class BackupStatus
{
    /** Texto del aviso, o null si todo está bien. */
    public static function warning(): ?string
    {
        $lastLocal = BackupRun::query()->latest('id')->first();
        if ($lastLocal && $lastLocal->status !== 'ok') {
            return 'La última copia de seguridad ha fallado: '.$lastLocal->error;
        }
        if (! BackupDestination::remote()) {
            return app()->isProduction()
                ? 'Las copias de seguridad solo se guardan en este equipo: configura un destino remoto (/admin → Copias de seguridad).'
                : null;
        }

        $lastRemote = BackupRun::query()->where('remote_status', 'subida')->latest('remote_at')->first();
        $max = config('backup.remote_max_age_hours');
        if (! $lastRemote) {
            return 'Todavía no se ha subido ninguna copia de seguridad al destino remoto.';
        }
        if ($lastRemote->remote_at->lt(now()->subHours($max))) {
            return "La última copia en el destino remoto es de {$lastRemote->remote_at->format('d/m/Y H:i')} (más de {$max} h). Revisa la conexión a internet o el destino.";
        }

        return null;
    }
}
