<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Modificador elegido en una línea (snapshot de nombre y precio aplicado). */
#[Unguarded]
class OrderLineModifier extends Model
{
    protected function casts(): array
    {
        return ['price_delta' => 'integer', 'quantity' => 'integer'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }
}
