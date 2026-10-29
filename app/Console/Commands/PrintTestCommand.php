<?php

namespace App\Console\Commands;

use App\Jobs\PrintTestPage;
use App\Services\Printing\PrinterManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tpv:print-test {--queue : Encolar el trabajo en lugar de imprimir directamente}')]
#[Description('Imprime una página de prueba (acentos, ñ, €, fecha y corte)')]
class PrintTestCommand extends Command
{
    public function handle(PrinterManager $printer): int
    {
        if ($this->option('queue')) {
            PrintTestPage::dispatch()->onQueue('printing');
            $this->info('Trabajo encolado. Lo procesará el worker de colas.');

            return self::SUCCESS;
        }

        $this->line("Modo de impresión: <comment>{$printer->mode()}</comment>");

        try {
            $result = (new PrintTestPage)->handle($printer);
        } catch (Throwable $e) {
            $this->error('Error al imprimir: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Impreso ({$result['bytes']} bytes ESC/POS).");
        if (isset($result['file'])) {
            $this->line("Salida simulada: {$result['file']}");
        }

        return self::SUCCESS;
    }
}
