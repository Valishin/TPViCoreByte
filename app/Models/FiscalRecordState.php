<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estado de envío a la AEAT de un registro de facturación (lo único que cambia). */
#[Unguarded]
class FiscalRecordState extends Model
{
    public const PENDING = 'pendiente';

    public const SENT = 'enviado';               // enviado, a la espera de respuesta

    public const ACCEPTED = 'correcto';

    public const ACCEPTED_WITH_ERRORS = 'aceptado_con_errores';

    public const REJECTED = 'incorrecto';

    public const SEND_ERROR = 'error_envio';     // SoapFault de cliente: requiere revisión

    protected $primaryKey = 'fiscal_record_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'incident' => 'boolean',
            'needs_correction' => 'boolean',
            'reconciled_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(FiscalRecord::class, 'fiscal_record_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(VerifactuSubmission::class, 'last_submission_id');
    }

    public function isAccepted(): bool
    {
        return in_array($this->status, [self::ACCEPTED, self::ACCEPTED_WITH_ERRORS], true);
    }
}
