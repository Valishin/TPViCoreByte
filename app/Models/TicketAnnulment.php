<?php

namespace App\Models;

use App\Exceptions\ImmutableTicketException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Anulación de un documento emitido por error, sin operación real (RRSIF 11.1; FAQ 17.2.d). Inmutable. */
#[Unguarded]
class TicketAnnulment extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableTicketException('Las anulaciones no se modifican.'));
        static::deleting(fn () => throw new ImmutableTicketException('Las anulaciones no se borran.'));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function annulledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulled_by');
    }

    public function fiscalRecord(): BelongsTo
    {
        return $this->belongsTo(FiscalRecord::class);
    }
}
