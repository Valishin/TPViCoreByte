<?php

namespace App\Jobs;

use App\Enums\PrinterStatus;
use App\Enums\PrintJobStatus;
use App\Models\PrintJob;
use App\Services\Printing\PrinterManager;
use App\Services\Printing\PrintWriteException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Imprime un PrintJob en su impresora (cola `printing`). Reintenta 2 veces; si
 * falla, queda "fallido" y la impresora en error. Cuando una impresora vuelve
 * a imprimir, se marca OK, pero los fallidos NO se reimprimen solos.
 */
class ProcessPrintJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 20;

    /** @var list<int> segundos entre intentos */
    public array $backoff = [2, 5];

    public function __construct(public int $printJobId) {}

    public function handle(PrinterManager $manager): void
    {
        $job = PrintJob::with('printer')->find($this->printJobId);
        if (! $job || $job->status !== PrintJobStatus::Pending) {
            return; // ya impreso o anulado mientras esperaba
        }

        $job->increment('attempts');
        $printer = $job->printer ?? throw new RuntimeException('La impresora ya no existe.');

        try {
            $manager->forPrinter($printer)->print($job->printable());
        } catch (PrintWriteException $e) {
            // Si algo llegó a la impresora, el ticket puede haber salido a medias: reintentarlo
            // solo lo duplicaría. Queda fallido para «Reintentar» a mano (nunca se reimprime solo).
            if ($e->bytesWritten > 0) {
                $this->job ? $this->fail($e) : $this->failed($e);   // fail() no reintenta y llama a failed()

                return;
            }
            throw $e;   // no llegó nada (desconectada, sin permiso…): se reintenta
        }

        $job->update(['status' => PrintJobStatus::Printed, 'printed_at' => now(), 'error' => null]);

        if ($printer->status !== PrinterStatus::Ok) {
            $printer->update(['status' => PrinterStatus::Ok, 'last_error' => null, 'last_status_at' => now()]);
        }
    }

    public function failed(?Throwable $e): void
    {
        $job = PrintJob::with('printer')->find($this->printJobId);
        if (! $job) {
            return;
        }

        $message = $e?->getMessage() ?: 'Error desconocido';
        $job->update(['status' => PrintJobStatus::Failed, 'error' => $message]);
        $job->printer?->update(['status' => PrinterStatus::Error, 'last_error' => $message, 'last_status_at' => now()]);
    }
}
