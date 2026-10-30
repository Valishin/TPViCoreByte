<?php

namespace App\Console\Commands;

use App\Services\Verifactu\CertificateStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Instala el certificado de VERI*FACTU desde la línea de órdenes (lo usa el
 * instalador). La contraseña se lee de la entrada estándar, nunca de argumentos.
 */
#[Signature('tpv:verifactu:certificate
    {file : Ruta del .p12/.pfx}
    {--mode=obligado : obligado | representante}
    {--kind=personal : personal | sello}')]
#[Description('Instala el certificado electrónico para enviar los registros a la AEAT (VERI*FACTU)')]
class VerifactuCertificateCommand extends Command
{
    public function handle(CertificateStore $store): int
    {
        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("No se puede leer {$file}.");

            return self::FAILURE;
        }
        $password = rtrim((string) stream_get_contents(STDIN), "\r\n");
        try {
            $info = $store->import((string) file_get_contents($file), $password, (string) $this->option('mode'), (string) $this->option('kind'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Certificado instalado: {$info['subject']} (NIF {$info['nif']}), válido hasta el {$info['valid_to']->format('d/m/Y')}.");

        return self::SUCCESS;
    }
}
