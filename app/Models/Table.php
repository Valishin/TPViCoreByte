<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\TableShape;
use App\Enums\TableStatus;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Mesa de una sala.
 *
 * IMPORTANTE: `status` solo lo cambian los servicios de pedidos
 * (App\Services\Orders\OrderService). No lo modifiques desde controladores,
 * Filament ni la SPA: así el estado de la mesa siempre es coherente con sus
 * pedidos abiertos.
 */
#[Unguarded]
class Table extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TableStatus::class,
            'shape' => TableShape::class,
            'is_active' => 'boolean',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function openOrders(): HasMany
    {
        return $this->orders()->where('status', OrderStatus::Open);
    }
}
