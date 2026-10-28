<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Unguarded]
class CustomerAddress extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /** Copia que se guarda en el pedido / ticket. */
    public function snapshot(): array
    {
        return $this->only(['label', 'street', 'number', 'floor_door', 'postal_code', 'city', 'instructions']);
    }
}
