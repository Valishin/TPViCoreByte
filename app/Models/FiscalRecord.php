<?php

namespace App\Models;

use App\Exceptions\ImmutableTicketException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Registro de facturación VERI*FACTU (alta o anulación) tal como se generó:
 * XML exacto, huella y encadenamiento. Inmutable (modelo + trigger): el estado
 * de envío va en FiscalRecordState. Ver App\Services\Verifactu.
 */
#[Unguarded]
class FiscalRecord extends Model
{
    public const ALTA = 'alta';

    public const ANULACION = 'anulacion';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableTicketException('Los registros de facturación son inmutables.'));
        static::deleting(fn () => throw new ImmutableTicketException('Los registros de facturación son inmutables.'));
    }

    protected function casts(): array
    {
        return [
            'tax_total' => 'integer',
            'amount_total' => 'integer',
            'is_first' => 'boolean',
            'is_correction' => 'boolean',
            'without_previous_record' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_record_id');
    }

    public function next(): HasOne
    {
        return $this->hasOne(self::class, 'previous_record_id');
    }

    public function state(): HasOne
    {
        return $this->hasOne(FiscalRecordState::class);
    }

    public function isAlta(): bool
    {
        return $this->record_type === self::ALTA;
    }
}
