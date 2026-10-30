<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cabeza de la cadena de registros (una fila): último registro generado y
 * control de flujo de los envíos (OM 16.2). Su fila se bloquea al generar.
 */
#[Unguarded]
class VerifactuChain extends Model
{
    protected $table = 'verifactu_chain';

    protected function casts(): array
    {
        return [
            'wait_seconds' => 'integer',
            'last_sent_at' => 'datetime',
            'last_success_at' => 'datetime',
            'incident_since' => 'datetime',
        ];
    }

    public static function head(): self
    {
        return self::query()->firstOrCreate(['id' => 1]);
    }

    public function lastRecord(): BelongsTo
    {
        return $this->belongsTo(FiscalRecord::class, 'last_record_id');
    }
}
