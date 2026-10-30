<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un envío a la AEAT: XML enviado y recibido, resultado y CSV (que no se puede recuperar después). */
#[Unguarded]
class VerifactuSubmission extends Model
{
    protected function casts(): array
    {
        return [
            'incident' => 'boolean',
            'record_count' => 'integer',
            'wait_seconds' => 'integer',
            'http_status' => 'integer',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(FiscalRecordState::class, 'last_submission_id');
    }
}
