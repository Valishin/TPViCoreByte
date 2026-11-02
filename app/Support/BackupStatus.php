<?php

namespace App\Support;

use App\Models\BackupRun;
use App\Services\Backup\BackupDestination;
use App\Services\Backup\BackupDisk;

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
        if ($disk = self::diskWarning($lastLocal)) {
            return $disk;
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

    /** Disco aparte elegido pero sin conectar, con error o sin copias recientes. */
    private static function diskWarning(?BackupRun $lastLocal): ?string
    {
        if (! BackupDisk::chosen()) {
            return null;
        }
        $info = BackupDisk::chosenInfo();
        $name = $info ? BackupDisk::describe($info + ['free' => null, 'connected' => true]) : 'elegido';
        if (! BackupDisk::target()) {
            return "El disco de copias ({$name}) no está conectado: conéctalo para que se guarden en él las copias pendientes.";
        }
        if ($lastLocal && $lastLocal->disk_status === 'error') {
            return 'No se ha podido copiar al disco de copias: '.$lastLocal->error;
        }
        $lastDisk = BackupRun::query()->where('disk_status', 'copiada')->latest('disk_at')->first();
        $max = config('backup.disk_max_age_hours');
        if ($lastLocal && (! $lastDisk || $lastDisk->disk_at->lt(now()->subHours($max)))) {
            return 'Hace más de '.$max.' h que no se copia nada al disco de copias: revisa que esté bien conectado.';
        }

        return null;
    }
}
