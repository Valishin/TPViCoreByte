<?php

namespace App\Services\Backup;

use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Disco aparte (USB) para las copias: si se estropea el disco del mini PC o el
 * equipo entero, las copias siguen en él.
 *
 * El equipo monta cada disco USB en /opt/tpv/host/discos/<UUID> (tpv-disco) y
 * deja su ficha en .info/<UUID>.json; la app los ve en config('backup.disks_path').
 * Un disco está conectado si existe su carpeta tpv-copias (solo existe con el
 * disco montado). En /admin se elige uno (settings.backup_disk_uuid).
 */
final class BackupDisk
{
    public const FOLDER = 'tpv-copias';

    /** @return list<array{uuid: string, label: string, fstype: string, size: int, free: ?int, connected: bool}> */
    public static function available(): array
    {
        $root = rtrim((string) config('backup.disks_path'), '/');
        $disks = [];
        foreach (File::glob("{$root}/.info/*.json") ?: [] as $file) {
            $info = json_decode((string) File::get($file), true);
            $uuid = preg_replace('/[^A-Za-z0-9-]/', '', (string) ($info['uuid'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $dir = "{$root}/{$uuid}/".self::FOLDER;
            $connected = is_dir($dir);
            $disks[] = [
                'uuid' => $uuid,
                'label' => trim((string) ($info['label'] ?? '')),
                'fstype' => (string) ($info['fstype'] ?? ''),
                'size' => (int) ($info['size'] ?? 0),
                'free' => $connected ? (int) (@disk_free_space($dir) ?: 0) : null,
                'connected' => $connected,
            ];
        }

        return $disks;
    }

    public static function chosen(?Setting $s = null): ?string
    {
        // Sin ajustes todavía (instalación recién hecha): no hay disco elegido.
        return ($s ?? Setting::query()->first())?->backup_disk_uuid ?: null;
    }

    /** Ficha del disco elegido (aunque no esté conectado ahora, si se conoce). */
    public static function chosenInfo(?Setting $s = null): ?array
    {
        $uuid = self::chosen($s);

        return $uuid ? collect(self::available())->firstWhere('uuid', $uuid) : null;
    }

    /** Carpeta de las copias de ESTE local en el disco elegido, o null si no está conectado. */
    public static function target(?Setting $s = null): ?string
    {
        $s ??= Setting::query()->first();
        $uuid = self::chosen($s);
        if (! $uuid) {
            return null;
        }
        $dir = rtrim((string) config('backup.disks_path'), '/')."/{$uuid}/".self::FOLDER;
        if (! is_dir($dir)) {
            return null;
        }

        // Un mismo disco puede guardar las copias de varios locales: una carpeta por instalación.
        return $dir.'/'.(Str::slug((string) $s?->trade_name) ?: 'tpv').'-'.substr(sha1((string) config('verifactu.installation_number')), 0, 6);
    }

    /** «COPIAS-TPV · 1 TB · libres 800 GB» */
    public static function describe(array $disk): string
    {
        $name = $disk['label'] !== '' ? $disk['label'] : 'Disco '.substr($disk['uuid'], 0, 8);
        $parts = [$name, strtoupper($disk['fstype']), self::bytes($disk['size'])];
        if ($disk['free'] !== null) {
            $parts[] = 'libres '.self::bytes($disk['free']);
        }

        return implode(' · ', array_filter($parts)).($disk['connected'] ? '' : ' (no conectado)');
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1000 && $i < count($units) - 1) {
            $value /= 1000;
            $i++;
        }

        return str_replace('.', ',', (string) round($value, $value < 10 && $i > 0 ? 1 : 0)).' '.$units[$i];
    }
}
