<?php

namespace App\Models;

use App\Enums\PrintJobKind;
use App\Enums\PrintJobStatus;
use App\Services\Printing\Ticket as PrintTicket;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un envío a una impresora: comanda, anulación o cuenta. Guarda el contenido
 * ya formateado para que reintentar o enviar a otra impresora imprima
 * exactamente lo mismo. Un fallido nunca se reimprime solo.
 */
#[Unguarded]
class PrintJob extends Model
{
    protected function casts(): array
    {
        return [
            'kind' => PrintJobKind::class,
            'status' => PrintJobStatus::class,
            'line_ids' => 'array',
            'content' => 'array',
            'attempts' => 'integer',
            'send_number' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    /** Contenido listo para imprimir. */
    public function printable(): PrintTicket
    {
        return PrintTicket::fromOperations($this->content);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function originalPrinter(): BelongsTo
    {
        return $this->belongsTo(Printer::class, 'original_printer_id');
    }
}
