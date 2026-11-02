<?php

namespace App\Services\Backup;

use App\Models\Setting;
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
            ], fn ($v) => $v !== null && $v !== ''),
            default => [],
        };
    }

    public static function configured(?Setting $s = null): bool
    {
        return str_starts_with((string) self::recipient($s), 'age1') && self::remote($s) !== null;
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
