<?php

namespace App\Models;

use App\Exceptions\ImmutableTicketException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Log de auditoría: quién (user_id), cuándo (created_at), qué (action y
 * subject), valor anterior (old_values) y nuevo (new_values). Solo inserción:
 * el modelo y el trigger audit_logs_append_only impiden cambiarlo o borrarlo.
 */
#[Unguarded]
class AuditLog extends Model
{
    public const SERIES_FIRST_NUMBER = 'serie.primer_numero';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableTicketException('El log de auditoría solo admite inserciones.'));
        static::deleting(fn () => throw new ImmutableTicketException('El log de auditoría solo admite inserciones.'));
    }

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Apunta una acción. */
    public static function record(string $action, ?User $by, ?Model $subject, ?array $old, ?array $new): self
    {
        return static::create([
            'user_id' => $by?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
