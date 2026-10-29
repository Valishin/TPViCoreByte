<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Liquidación de un repartidor en caja: cobros de sus repartos y devolución del fondo. */
#[Unguarded]
class CourierSettlement extends Model
{
    protected function casts(): array
    {
        return [
            'float_amount' => 'integer',
            'cash_collected' => 'integer',
            'card_collected' => 'integer',
            'other_collected' => 'integer',
            'expected_cash' => 'integer',
            'handed_cash' => 'integer',
            'difference' => 'integer',
        ];
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
