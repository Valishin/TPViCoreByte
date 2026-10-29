<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Copias de seguridad de la base de datos:
 *   pg_dump -Fc → cifrado con age (clave pública) → /backups/tpv-AAAAMMDD-HHMMSS-motivo.dump.age
 *   → rotación (7 diarias, 4 semanales, 6 mensuales) → subida con rclone si hay destino.
 * La clave privada no está en el equipo: si lo roban, las copias no se pueden leer.
 */
class BackupService
{
    public const PATTERN = '/^tpv-(\d{8})-(\d{6})-[a-z_]+\.dump\.age$/';

    public function run(string $reason): BackupRun
    {
        $recipient = config('backup.age_recipient');
        $dir = config('backup.path');
        $file = 'tpv-'.now()->format('Ymd-His')."-{$reason}.dump.age";
        $run = BackupRun::create(['reason' => $reason, 'file' => $file, 'status' => 'error', 'remote_status' => 'pendiente']);

        try {
            if (! $recipient || ! str_starts_with($recipient, 'age1')) {
                throw new RuntimeException('Falta BACKUP_AGE_RECIPIENT (clave pública age): las copias no se guardan sin cifrar.');
            }
            File::ensureDirectoryExists($dir);
            $db = config('database.connections.pgsql');
            $target = "{$dir}/{$file}";

            // pg_dump | age: el volcado sin cifrar nunca toca el disco.
            // bash (no sh/dash): pipefail para que un fallo de pg_dump no deje una copia vacía.
            $result = Process::timeout(900)
                ->env(['PGPASSWORD' => $db['password']])
                ->run(['bash', '-c', sprintf(
                    'set -o pipefail; pg_dump -h %s -p %s -U %s -d %s -Fc --no-owner --no-acl | age -r %s -o %s',
                    escapeshellarg($db['host']), escapeshellarg((string) $db['port']), escapeshellarg($db['username']),
                    escapeshellarg($db['database']), escapeshellarg($recipient), escapeshellarg($target),
                )]);
            if (! $result->successful()) {
                File::delete($target);
                throw new RuntimeException(trim($result->errorOutput()) ?: 'pg_dump o age han fallado.');
            }

            $run->update(['status' => 'ok', 'size' => File::size($target), 'remote_status' => config('backup.remote') ? 'pendiente' : 'sin_destino']);
            $this->rotate();
        } catch (Throwable $e) {
            $run->update(['error' => $e->getMessage()]);

            return $run;
        }

        $this->upload();

        return $run->refresh();
    }

    /** ¿La última copia correcta tiene más de $hours horas (o no hay ninguna)? */
    public function isOlderThan(int $hours): bool
    {
        $last = BackupRun::query()->where('status', 'ok')->latest('id')->first();

        return ! $last || $last->created_at->lt(now()->subHours($hours));
    }

    /**
     * Sube el directorio de copias (ya rotado) al destino remoto. Sin internet
     * falla y se reintenta cada hora: las copias pendientes quedan marcadas.
     */
    public function upload(): bool
    {
        $remote = config('backup.remote');
        if (! $remote) {
            return false;
        }
        $pending = BackupRun::query()->where('status', 'ok')->whereIn('remote_status', ['pendiente', 'error']);
        if (! (clone $pending)->exists()) {
            return true;
        }

        $result = Process::timeout(1800)->run([
            'rclone', 'sync', config('backup.path'), $remote,
            '--include', 'tpv-*.dump.age', '--retries', '3', '--low-level-retries', '5', '--contimeout', '20s',
        ]);
        if ($result->successful()) {
            (clone $pending)->update(['remote_status' => 'subida', 'remote_at' => now(), 'error' => null]);

            return true;
        }
        (clone $pending)->update(['remote_status' => 'error', 'error' => mb_substr(trim($result->errorOutput()), 0, 1000)]);

        return false;
    }

    /**
     * Rotación GFS: se guardan la última copia de cada uno de los últimos N días,
     * N semanas y N meses; el resto se borra.
     *
     * @return list<string> ficheros borrados
     */
    public function rotate(): array
    {
        $dir = config('backup.path');
        $keep = config('backup.keep');
        $files = collect(File::files($dir))
            ->map(fn ($f) => $f->getFilename())
            ->filter(fn ($name) => preg_match(self::PATTERN, $name))
            ->map(function ($name) {
                preg_match(self::PATTERN, $name, $m);

                return ['name' => $name, 'at' => CarbonImmutable::createFromFormat('Ymd His', "{$m[1]} {$m[2]}")];
            })
            ->sortByDesc('at')->values();

        $kept = collect();
        foreach ([['daily', 'Y-m-d'], ['weekly', 'o-W'], ['monthly', 'Y-m']] as [$tier, $format]) {
            $files->groupBy(fn ($f) => $f['at']->format($format))
                ->take($keep[$tier])
                ->each(fn ($group) => $kept->push($group->first()['name'])); // la más reciente de cada periodo
        }

        $deleted = $files->pluck('name')->diff($kept)->values()->all();
        foreach ($deleted as $name) {
            File::delete("{$dir}/{$name}");
        }

        return $deleted;
    }
}
