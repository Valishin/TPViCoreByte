<?php

namespace App\Models;

use App\Enums\AuthorizationAction;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Registro de una autorización de encargado (quién la pidió, quién la dio y sobre qué). */
#[Unguarded]
class Authorization extends Model
{
    protected function casts(): array
    {
        return ['action' => AuthorizationAction::class, 'details' => 'array'];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
