<?php

namespace App\Services\Verifactu;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;

/**
 * Certificado electrónico cualificado para remitir los registros (OM 4.b y 5;
 * Descripción servicios web 4.3). Se guarda el .p12 cifrado con la APP_KEY,
 * junto con su contraseña (también cifrada): sin la APP_KEY, una copia de
 * seguridad no permite recuperarlo (docs/instalacion-minipc.md).
 *
 * Modos (decisión D25): certificado del propio obligado tributario, o de un
 * representante (apoderado o colaborador social, OM 5). Tipo: personal
 * (persona física o representante de entidad) o de sello (endpoint propio).
 */
class CertificateStore
{
    public const MODE_OBLIGED = 'obligado';

    public const MODE_REPRESENTATIVE = 'representante';

    public const KIND_PERSONAL = 'personal';

    public const KIND_SEAL = 'sello';

    /**
     * Comprueba e instala un .p12/.pfx. Devuelve los datos del certificado.
     *
     * @return array{subject: string, nif: ?string, valid_to: Carbon}
     */
    public function import(string $p12, string $password, string $mode = self::MODE_OBLIGED, string $kind = self::KIND_PERSONAL, ?Setting $settings = null): array
    {
        $settings ??= Setting::current();
        if (! in_array($mode, [self::MODE_OBLIGED, self::MODE_REPRESENTATIVE], true) || ! in_array($kind, [self::KIND_PERSONAL, self::KIND_SEAL], true)) {
            throw new InvalidArgumentException('Modo o tipo de certificado no válido.');
        }
        [$p12, $parts] = self::readOrConvert($p12, $password);
        $info = self::describe($parts['cert']);
        if ($info['valid_to']->isPast()) {
            throw new InvalidArgumentException('El certificado está caducado ('.$info['valid_to']->format('d/m/Y').').');
        }
        if ($mode === self::MODE_OBLIGED && $info['nif'] && RecordBuilder::nif($settings->tax_id) !== $info['nif']) {
            throw new InvalidArgumentException("El certificado es de {$info['nif']} y el NIF del local es {$settings->tax_id}. Si es de un representante (apoderado o colaborador social), elige ese modo.");
        }

        $settings->update([
            'verifactu_certificate' => base64_encode($p12),
            'verifactu_certificate_password' => $password,
            'verifactu_certificate_mode' => $mode,
            'verifactu_certificate_kind' => $kind,
            'verifactu_certificate_nif' => $info['nif'],
            'verifactu_certificate_subject' => mb_substr($info['subject'], 0, 255),
            'verifactu_certificate_valid_to' => $info['valid_to'],
        ]);

        return $info;
    }

    public static function installed(?Setting $settings = null): bool
    {
        return filled(($settings ?? Setting::current())->verifactu_certificate);
    }

    /** Días hasta la caducidad (negativo si ya caducó), o null sin certificado. */
    public static function daysToExpiry(?Setting $settings = null): ?int
    {
        $validTo = ($settings ?? Setting::current())->verifactu_certificate_valid_to;

        return $validTo ? (int) floor(now()->diffInDays($validTo, false)) : null;
    }

    /**
     * Certificado y clave en PEM, en memoria, para el TLS mutuo.
     *
     * @return array{cert: string, key: string}
     */
    public static function pem(?Setting $settings = null): array
    {
        $settings ??= Setting::current();
        if (! self::installed($settings)) {
            throw new RuntimeException('No hay certificado de VERI*FACTU instalado (/admin → VERI*FACTU).');
        }
        if (! openssl_pkcs12_read(base64_decode($settings->verifactu_certificate), $parts, (string) $settings->verifactu_certificate_password)) {
            throw new RuntimeException('No se puede leer el certificado instalado: vuelve a subirlo.');
        }

        return ['cert' => $parts['cert'].implode('', $parts['extracerts'] ?? []), 'key' => $parts['pkey']];
    }

    /**
     * Sujeto, NIF y caducidad. NIF: organizationIdentifier (VATES-…) en
     * certificados de entidad; si no, serialNumber (IDCES-…) de la persona.
     *
     * @return array{subject: string, nif: ?string, valid_to: Carbon}
     */
    public static function describe(string $pemCert): array
    {
        $x509 = openssl_x509_parse($pemCert) ?: throw new InvalidArgumentException('El fichero no contiene un certificado válido.');
        $subject = $x509['subject'] ?? [];
        $raw = $subject['organizationIdentifier'] ?? $subject['2.5.4.97'] ?? $subject['serialNumber'] ?? null;
        $nif = null;
        if (is_string($raw) && preg_match('/([A-Z0-9]{9})$/', strtoupper($raw), $m)) {
            $nif = $m[1];
        }
        $name = collect(['CN', 'O'])->map(fn ($k) => is_array($subject[$k] ?? null) ? implode(' ', $subject[$k]) : ($subject[$k] ?? null))->filter()->implode(' · ');

        return ['subject' => $name, 'nif' => $nif, 'valid_to' => Carbon::createFromTimestamp($x509['validTo_time_t'])];
    }

    /**
     * Lee el .p12; si usa cifrado antiguo (RC2-40, frecuente en exportaciones
     * de la FNMT) que OpenSSL 3 ya no lee por defecto, lo convierte una vez con
     * «openssl pkcs12 -legacy» a uno moderno (AES-256). La contraseña se pasa
     * por variable de entorno, nunca en la línea de órdenes.
     *
     * @return array{0: string, 1: array}
     */
    private static function readOrConvert(string $p12, string $password): array
    {
        if (openssl_pkcs12_read($p12, $parts, $password)) {
            return [$p12, $parts];
        }
        $dir = sys_get_temp_dir().'/tpv-cert-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        try {
            file_put_contents("$dir/in.p12", $p12);
            $env = ['TPV_P12_PASS' => $password, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin'];
            $pem = self::run(['openssl', 'pkcs12', '-legacy', '-in', "$dir/in.p12", '-passin', 'env:TPV_P12_PASS', '-nodes'], $env);
            file_put_contents("$dir/all.pem", $pem);
            chmod("$dir/all.pem", 0600);
            $modern = self::run(['openssl', 'pkcs12', '-export', '-in', "$dir/all.pem", '-passout', 'env:TPV_P12_PASS', '-keypbe', 'AES-256-CBC', '-certpbe', 'AES-256-CBC', '-macalg', 'sha256'], $env);
        } catch (RuntimeException) {
            throw new InvalidArgumentException('No se puede abrir el certificado: revisa la contraseña (o que el fichero sea un .p12/.pfx con clave privada).');
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
        if (! openssl_pkcs12_read($modern, $parts, $password)) {
            throw new InvalidArgumentException('No se puede abrir el certificado convertido.');
        }

        return [$modern, $parts];
    }

    private static function run(array $command, array $env): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (! is_resource($process)) {
            throw new RuntimeException('No se puede ejecutar openssl.');
        }
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $out === '') {
            throw new RuntimeException('openssl falló.');
        }

        return $out;
    }
}
