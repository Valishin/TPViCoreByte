<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Acceso remoto con Tailscale desde /admin. La app no puede ejecutar
 * `tailscale up` en el equipo (va en Docker y sin privilegios), así que deja
 * la clave en una carpeta compartida con el equipo:
 *
 *   /opt/tpv/host/tailscale (equipo, 700, del usuario de la app, uid 1000)
 *     = /var/tpv/host/tailscale (contenedor)
 *
 * Un servicio de root del equipo (tpv-tailscale.path → tpv-tailscale-join,
 * lo instala install.sh) recoge `authkey`, lo BORRA antes de usarlo (también
 * si falla), une el equipo y deja el resultado en `status.json`.
 *
 * SSH por Tailscale: desactivado por defecto (`tailscale up --ssh=false`). Solo
 * si el admin lo permite a mano (settings.tailscale_ssh) se pide al equipo con
 * `ssh-request` y el servicio ejecuta `tailscale set --ssh=…`.
 */
final class TailscaleBridge
{
    public static function dir(): string
    {
        return rtrim((string) config('tpv.tailscale_bridge', '/var/tpv/host/tailscale'), '/');
    }

    public static function available(): bool
    {
        return is_dir(self::dir()) && is_writable(self::dir());
    }

    /** Deja la clave para el servicio del equipo (solo legible por el usuario de la app y root). */
    public static function submit(string $authKey, string $hostname, bool $ssh = false): void
    {
        $authKey = trim($authKey);
        if (! preg_match('/^tskey-auth-[A-Za-z0-9-]{10,}$/', $authKey)) {
            throw new InvalidArgumentException('La clave de Tailscale empieza por «tskey-auth-»: cópiala entera del panel de Tailscale (Settings → Keys).');
        }
        if (! self::available()) {
            throw new RuntimeException('Este equipo no tiene el servicio de Tailscale del instalador (carpeta '.self::dir().').');
        }
        $hostname = strtolower(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $hostname), '-')) ?: 'tpv';
        @unlink(self::dir().'/status.json');
        self::write('authkey', ['authkey' => $authKey, 'hostname' => $hostname, 'ssh' => $ssh]);
    }

    /** Pide al equipo permitir o quitar el acceso SSH por Tailscale (`tailscale set --ssh=…`). */
    public static function requestSsh(bool $on): void
    {
        if (! self::available()) {
            throw new RuntimeException('Este equipo no tiene el servicio de Tailscale del instalador (carpeta '.self::dir().').');
        }
        self::write('ssh-request', ['ssh' => $on]);
    }

    /** Fichero 0600 que aparece completo (rename atómico): el servicio nunca lee medio fichero. */
    private static function write(string $name, array $data): void
    {
        $tmp = self::dir().'/.'.$name.'-'.bin2hex(random_bytes(4));
        $old = umask(0077);
        try {
            file_put_contents($tmp, json_encode($data));
            chmod($tmp, 0600);
            rename($tmp, self::dir().'/'.$name);
        } finally {
            umask($old);
            @unlink($tmp);
        }
    }

    /** ¿Hay una clave esperando al servicio del equipo? */
    public static function pending(): bool
    {
        return is_file(self::dir().'/authkey');
    }

    /** @return array{state: string, message: ?string, hostname: ?string, ip: ?string, ssh: ?bool, at: ?Carbon} */
    public static function status(): array
    {
        $empty = ['state' => self::pending() ? 'pendiente' : 'sin_configurar', 'message' => null, 'hostname' => null, 'ip' => null, 'ssh' => null, 'at' => null];
        $file = self::dir().'/status.json';
        if (self::pending() || ! is_readable($file)) {
            return $empty;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (! is_array($data) || ! in_array($data['state'] ?? null, ['conectando', 'conectado', 'error', 'desconectado'], true)) {
            return $empty;
        }

        return [
            'state' => $data['state'], 'message' => $data['message'] ?? null, 'hostname' => $data['hostname'] ?? null,
            'ip' => $data['ip'] ?? null, 'ssh' => isset($data['ssh']) && $data['ssh'] !== '' ? $data['ssh'] === true || $data['ssh'] === 'true' : null,
            'at' => isset($data['at']) ? Carbon::parse($data['at']) : null,
        ];
    }

    public static function connected(): bool
    {
        return self::status()['state'] === 'conectado';
    }

    public static function describe(): string
    {
        $s = self::status();

        return match ($s['state']) {
            'conectado' => "Conectado como {$s['hostname']}".($s['ip'] ? " ({$s['ip']})" : '')
                .match ($s['ssh']) {
                    true => ' · SSH por Tailscale PERMITIDO', false => ' · SSH por Tailscale desactivado', default => ''
                }
            .($s['at'] ? ' · comprobado '.$s['at']->format('d/m H:i') : ''),
            'pendiente', 'conectando' => 'Conectando… (el equipo lo hace en unos segundos)',
            'error' => 'No se ha podido conectar: '.($s['message'] ?? 'error desconocido').'. Genera otra clave y vuelve a intentarlo.',
            'desconectado' => 'Desconectado de Tailscale.',
            default => self::available() ? 'Sin configurar.' : 'Este equipo no tiene el servicio de Tailscale (se instala con install.sh).',
        };
    }
}
