<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A dónde van las copias: lo configurado en /admin (clave age y destino S3
 * compatible o SFTP) o, si no hay nada, lo del .env (BACKUP_AGE_RECIPIENT,
 * BACKUP_REMOTE y RCLONE_CONFIG_*). El destino de /admin se pasa a rclone por
 * variables de entorno (remoto «tpvcopias»), nunca en un fichero.
 */
final class BackupDestination
{
    public const REMOTE = 'tpvcopias';

    public static function recipient(?Setting $s = null): ?string
    {
        $s ??= Setting::query()->first();

        return $s?->backup_age_recipient ?: config('backup.age_recipient');
    }

    /** «tpvcopias:bucket/carpeta», «tpvcopias:carpeta» o el BACKUP_REMOTE del .env. */
    public static function remote(?Setting $s = null): ?string
    {
        $s ??= Setting::query()->first();
        $path = trim((string) $s?->backup_path, '/');

        return match ($s?->backup_remote_type) {
            's3' => self::REMOTE.':'.trim($s->backup_s3_bucket.'/'.$path, '/'),
            'sftp' => self::REMOTE.':'.($path !== '' ? $path : 'tpv-copias'),
            default => config('backup.remote') ?: null,
        };
    }

    /** Variables de entorno de rclone para el destino de /admin. */
    public static function env(?Setting $s = null): array
    {
        $s ??= Setting::query()->first();
        $p = 'RCLONE_CONFIG_'.strtoupper(self::REMOTE).'_';

        return match ($s?->backup_remote_type) {
            's3' => array_filter([
                "{$p}TYPE" => 's3', "{$p}PROVIDER" => 'Other', "{$p}ENDPOINT" => $s->backup_s3_endpoint,
                "{$p}REGION" => $s->backup_s3_region, "{$p}ACCESS_KEY_ID" => $s->backup_s3_access_key,
                "{$p}SECRET_ACCESS_KEY" => $s->backup_s3_secret_key, "{$p}NO_CHECK_BUCKET" => 'true',
            ], fn ($v) => $v !== null && $v !== ''),
            'sftp' => array_filter([
                "{$p}TYPE" => 'sftp', "{$p}HOST" => $s->backup_sftp_host, "{$p}PORT" => (string) ($s->backup_sftp_port ?: 22),
                "{$p}USER" => $s->backup_sftp_user,
                // rclone: PEM en una sola línea con «\n» (documentación de --sftp-key-pem).
                "{$p}KEY_PEM" => $s->backup_sftp_private_key ? str_replace("\n", '\n', trim($s->backup_sftp_private_key)) : null,
                // Solo el servidor cuya clave confirmó el admin (sin esto, rclone aceptaría cualquiera).
                "{$p}KNOWN_HOSTS_FILE" => self::knownHostsFile($s),
            ], fn ($v) => $v !== null && $v !== ''),
            default => [],
        };
    }

    public static function configured(?Setting $s = null): bool
    {
        $s ??= Setting::query()->first();

        return str_starts_with((string) self::recipient($s), 'age1') && self::remote($s) !== null
            && ($s?->backup_remote_type !== 'sftp' || filled($s->backup_sftp_known_hosts));
    }

    /** Fichero known_hosts para rclone con la clave confirmada del servidor SFTP. */
    public static function knownHostsFile(Setting $s): ?string
    {
        if (blank($s->backup_sftp_known_hosts)) {
            return null;
        }
        $file = storage_path('app/private/backup-known-hosts');
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, trim($s->backup_sftp_known_hosts)."\n");
        @chmod($file, 0600);

        return $file;
    }

    /**
     * Claves del servidor SFTP (ssh-keyscan) y sus huellas, para que el admin
     * las compare con las del servidor antes de confiar en él.
     *
     * @return array{known_hosts: string, fingerprints: list<string>}
     */
    public static function scanSftpHost(string $host, int $port = 22): array
    {
        $scan = Process::timeout(20)->run(['ssh-keyscan', '-T', '10', '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $host]);
        $lines = collect(preg_split('/\R/', $scan->output()))->map(fn ($l) => trim($l))->filter(fn ($l) => $l !== '' && ! str_starts_with($l, '#'))->values();
        if ($lines->isEmpty()) {
            throw new RuntimeException("No se ha podido leer la clave de {$host}:{$port}. ".trim($scan->errorOutput()));
        }
        $known = $lines->implode("\n");
        $prints = Process::timeout(10)->input($known."\n")->run(['ssh-keygen', '-lf', '-']);
        $fingerprints = collect(preg_split('/\R/', trim($prints->output())))->filter()->values()->all();

        return ['known_hosts' => $known, 'fingerprints' => $fingerprints];
    }

    /**
     * Par de claves SSH ed25519 para SFTP: la privada se queda (cifrada) en la
     * app; devuelve la pública en formato OpenSSH para añadirla al servidor.
     */
    public static function generateSftpKey(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519])
            ?: throw new RuntimeException('No se ha podido generar la clave SSH.');
        openssl_pkey_export($key, $pem);
        $raw = openssl_pkey_get_details($key)['ed25519']['pub_key'] ?? throw new RuntimeException('Clave ed25519 sin parte pública.');
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', strlen($raw)).$raw;
        $public = 'ssh-ed25519 '.base64_encode($blob).' tpv-copias@'.gethostname();
        Setting::current()->update(['backup_sftp_private_key' => $pem, 'backup_sftp_public_key' => $public]);

        return $public;
    }
}
