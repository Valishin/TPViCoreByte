<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Envío de una factura por email: pendiente → enviado (o error, con reintentos). */
#[Unguarded]
class InvoiceEmail extends Model
{
    public const PENDING = 'pendiente';

    public const SENT = 'enviado';

    public const ERROR = 'error';

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'sent_at' => 'datetime'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
