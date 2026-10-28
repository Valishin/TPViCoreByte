<?php

namespace App\Services\Pos;

use App\Enums\PrintJobKind;
use App\Enums\PrintJobStatus;
use App\Jobs\ProcessPrintJob;
use App\Models\Order;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Printing\Ticket;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Registra cada envío a impresora (print_jobs) y lo encola en `printing`
 * cuando se confirma la transacción. Reintentar y reenviar reutilizan el
 * mismo contenido: nunca se reimprime nada automáticamente.
 */
class PrintJobDispatcher
{
    public function dispatch(Order $order, Printer $printer, PrintJobKind $kind, Ticket $ticket, array $lineIds = [], ?int $sendNumber = null, ?User $by = null): PrintJob
    {
        $job = PrintJob::create([
            'order_id' => $order->id,
            'printer_id' => $printer->id,
            'kind' => $kind,
            'send_number' => $sendNumber,
            'line_ids' => $lineIds,
            'content' => $ticket->operations(),
            'status' => PrintJobStatus::Pending,
            'created_by' => $by?->id,
        ]);

        $this->queue($job);

        return $job;
    }

    public function retry(PrintJob $job): PrintJob
    {
        if ($job->status !== PrintJobStatus::Failed) {
            throw new LogicException('Solo se pueden reintentar envíos fallidos.');
        }

        $job->update(['status' => PrintJobStatus::Pending, 'error' => null, 'attempts' => 0]);
        $this->queue($job);

        return $job;
    }

    /** Envía un envío fallido a otra impresora (p. ej. cocina caída → barra). */
    public function redirect(PrintJob $job, Printer $printer): PrintJob
    {
        if ($job->status !== PrintJobStatus::Failed) {
            throw new LogicException('Solo se pueden reenviar envíos fallidos.');
        }
        if (! $printer->is_active) {
            throw new LogicException("La impresora {$printer->name} está desactivada.");
        }

        return DB::transaction(function () use ($job, $printer) {
            $from = $job->printer;
            $content = $job->content;
            // Aviso destacado en la cabecera: "Reenviada desde Cocina".
            array_splice($content, 1, 0, [[
                'type' => 'line', 'text' => 'REENVIADA DESDE '.mb_strtoupper($from?->name ?? '?'),
                'align' => Ticket::ALIGN_CENTER, 'bold' => true, 'large' => false, 'reverse' => true,
            ]]);

            $job->update([
                'original_printer_id' => $job->original_printer_id ?? $job->printer_id,
                'printer_id' => $printer->id,
                'content' => $content,
                'status' => PrintJobStatus::Pending,
                'error' => null,
                'attempts' => 0,
            ]);
            $this->queue($job);

            return $job->refresh();
        });
    }

    private function queue(PrintJob $job): void
    {
        ProcessPrintJob::dispatch($job->id)->onQueue('printing')->afterCommit();
    }
}
