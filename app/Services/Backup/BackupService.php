<?php

namespace App\Services\Backup;

use App\Models\BackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Copias de seguridad (todo lo necesario para levantar el TPV en otro equipo):
 *   pg_dump -Fc → cifrado con age (clave pública) → /backups/tpv-AAAAMMDD-HHMMSS-motivo.dump.age
 *   + tpv-claves.env.age (APP_KEY y demás claves de la instalación: sin ellas no se
 *     leen los datos cifrados de la base de datos) y tpv-archivos.tar.age (storage/app:
 *     logo…), los dos con la última versión
 *   → rotación (7 diarias, 4 semanales, 6 mensuales) → copia en el disco aparte (USB) si
 *   se ha elegido uno → subida con rclone si hay destino remoto.
 * La clave privada no está en el equipo: si lo roban, las copias no se pueden leer.
 */
class BackupService
{
    public const PATTERN = '/^tpv-(\d{8})-(\d{6})-[a-z_]+\.dump\.age$/';

    public const KEYS_FILE = 'tpv-claves.env.age';

    public const FILES_FILE = 'tpv-archivos.tar.age';

    /** Lo que va al disco aparte y al destino remoto: copias de la base de datos, claves y archivos. */
    public const ALL_PATTERN = '/^tpv-.+\.age$/';

    public function run(string $reason): BackupRun
    {
        $recipient = BackupDestination::recipient();
        $dir = config('backup.path');
        $file = 'tpv-'.now()->format('Ymd-His')."-{$reason}.dump.age";
        $run = BackupRun::create(['reason' => $reason, 'file' => $file, 'status' => 'error', 'remote_status' => 'pendiente',
            'disk_status' => BackupDisk::chosen() ? 'pendiente' : 'sin_disco']);

        try {
            if (! $recipient || ! str_starts_with($recipient, 'age1')) {
                throw new RuntimeException('Falta la clave pública age (/admin → Copias de seguridad): las copias no se guardan sin cifrar.');
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

            $this->saveKeys($recipient, $dir);
            $this->saveFiles($recipient, $dir);

            $run->update(['status' => 'ok', 'size' => File::size($target), 'remote_status' => BackupDestination::remote() ? 'pendiente' : 'sin_destino']);
            $this->rotate();
        } catch (Throwable $e) {
            $run->update(['error' => $e->getMessage()]);

            return $run;
        }

        $this->copyToDisk();
        $this->upload();

        return $run->refresh();
    }

    /** Claves de la instalación (las del .env), cifradas: sin la APP_KEY no se leen los datos cifrados de la copia. */
    private function saveKeys(string $recipient, string $dir): void
    {
        $values = [
            'APP_KEY' => config('app.key'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'REVERB_APP_ID' => config('broadcasting.connections.reverb.app_id'),
            'REVERB_APP_KEY' => config('broadcasting.connections.reverb.key'),
            'REVERB_APP_SECRET' => config('broadcasting.connections.reverb.secret'),
            'VERIFACTU_INSTALLATION_NUMBER' => config('verifactu.installation_number'),
            'TPV_INSTALL_MODE' => config('tpv.install_mode'),
        ];
        $text = '# Claves del TPV ('.now()->format('d/m/Y H:i')."): van en /opt/tpv/.env al restaurar en otro equipo.\n";
        foreach ($values as $key => $value) {
            $text .= "{$key}={$value}\n";
        }
        $this->encrypt(['age', '-r', $recipient, '-o', "{$dir}/".self::KEYS_FILE.'.tmp'], $text, 'las claves');
        File::move("{$dir}/".self::KEYS_FILE.'.tmp', "{$dir}/".self::KEYS_FILE);
    }

    /** Archivos de la app (logo…), sin impresiones simuladas, exportaciones ni temporales. */
    private function saveFiles(string $recipient, string $dir): void
    {
        $out = "{$dir}/".self::FILES_FILE.'.tmp';
        $this->encrypt(['bash', '-c', sprintf(
            'set -o pipefail; tar -C %s -cf - --exclude=./private/prints --exclude=./private/exports --exclude=./private/livewire-tmp . | age -r %s -o %s',
            escapeshellarg(storage_path('app')), escapeshellarg($recipient), escapeshellarg($out),
        )], null, 'los archivos');
        File::move($out, "{$dir}/".self::FILES_FILE);
    }

    private function encrypt(array $command, ?string $input, string $what): void
    {
        $process = Process::timeout(300);
        $result = ($input === null ? $process : $process->input($input))->run($command);
        if (! $result->successful()) {
            throw new RuntimeException("No se han podido cifrar {$what}: ".(trim($result->errorOutput()) ?: 'error desconocido'));
        }
    }

    /**
     * Disco aparte (USB): espejo de las copias (lo que falte o haya cambiado se copia;
     * lo que la rotación ya quitó, se borra). Sin disco conectado, las copias quedan
     * «no_conectado» y se copian en cuanto se conecte (tpv:backup --disk-only cada 15 min).
     *
     * @return bool|null null si no hay disco elegido
     */
    public function copyToDisk(): ?bool
    {
        if (! BackupDisk::chosen()) {
            return null;
        }
        $pending = BackupRun::query()->where('status', 'ok')->whereIn('disk_status', ['pendiente', 'error', 'no_conectado', 'sin_disco']);
        $target = BackupDisk::target();
        if (! $target) {
            (clone $pending)->update(['disk_status' => 'no_conectado']);

            return false;
        }

        try {
            File::ensureDirectoryExists($target);
            $source = config('backup.path');
            $local = collect(File::files($source))->filter(fn ($f) => preg_match(self::ALL_PATTERN, $f->getFilename()))->keyBy(fn ($f) => $f->getFilename());
            $needed = $local->filter(fn ($f) => ! is_file("{$target}/{$f->getFilename()}") || filesize("{$target}/{$f->getFilename()}") !== $f->getSize()
                || filemtime("{$target}/{$f->getFilename()}") < $f->getMTime());
            $free = @disk_free_space($target);
            if ($free !== false && $needed->sum(fn ($f) => $f->getSize()) > $free) {
                throw new RuntimeException('El disco de copias está lleno: libera espacio o cambia de disco.');
            }
            foreach ($needed as $name => $file) {
                // Primero a un temporal y luego se renombra: nunca queda una copia a medias con su nombre.
                File::copy($file->getPathname(), "{$target}/.{$name}.tmp");
                File::move("{$target}/.{$name}.tmp", "{$target}/{$name}");
            }
            foreach (File::files($target) as $file) {
                if (preg_match(self::ALL_PATTERN, $file->getFilename()) && ! $local->has($file->getFilename())) {
                    File::delete($file->getPathname());   // ya rotada en el equipo
                }
            }
            File::put("{$target}/LEEME.txt", self::readme());
            (clone $pending)->update(['disk_status' => 'copiada', 'disk_at' => now(), 'error' => null]);

            return true;
        } catch (Throwable $e) {
            (clone $pending)->update(['disk_status' => 'error', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            return false;
        }
    }

    private static function readme(): string
    {
        return <<<'TXT'
            Copias de seguridad del TPV (iCOREBYTE). Todo va cifrado con age: sin la
            clave privada (la que se guardó fuera del equipo) no se pueden leer.

              tpv-AAAAMMDD-HHMMSS-motivo.dump.age  base de datos (una por cierre Z, diaria…)
              tpv-claves.env.age                   claves de la instalación (APP_KEY…)
              tpv-archivos.tar.age                 archivos (logo…)

            Restaurar en un equipo nuevo: instalar el TPV, poner las claves de
            tpv-claves.env.age en /opt/tpv/.env y, con la clave privada:
              sudo tpv-restore tpv-…dump.age --identity clave-privada-age.txt
            Guía completa: docs/instalacion-minipc.md (Copias de seguridad).
            Soporte: iCOREBYTE · 678 758 210 · info@icorebyte.com
            TXT;
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
        $remote = BackupDestination::remote();
        if (! $remote) {
            return false;
        }
        $pending = BackupRun::query()->where('status', 'ok')->whereIn('remote_status', ['pendiente', 'error']);
        if (! (clone $pending)->exists()) {
            return true;
        }

        $result = Process::timeout(1800)->env(BackupDestination::env())->run([
            'rclone', 'sync', config('backup.path'), $remote,
            '--include', 'tpv-*.age', '--retries', '3', '--low-level-retries', '5', '--contimeout', '20s',
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
