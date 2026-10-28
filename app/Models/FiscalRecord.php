<?php

namespace App\Models;

use App\Enums\FiscalRecordType;
use App\Enums\FiscalSubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de facturación para VeriFactu. Tabla reservada: sin lógica todavía. */
#[Unguarded]
class FiscalRecord extends Model
{
    protected function casts(): array
    {
        return [
            'record_type' => FiscalRecordType::class,
            'submission_status' => FiscalSubmissionStatus::class,
            'payload' => 'array',
            'aeat_response' => 'array',
            'generated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(FiscalRecord::class, 'previous_record_id');
    }
}
