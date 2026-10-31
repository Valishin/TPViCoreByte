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
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

/**
 * Mesa de una sala.
 *
 * IMPORTANTE: `status` solo lo cambian los servicios de pedidos
 * (App\Services\Orders\OrderService). No lo modifiques desde controladores,
 * Filament ni la SPA: así el estado de la mesa siempre es coherente con sus
 * pedidos abiertos.
 *
 * `is_active` (interruptor "Activa" del backoffice) es lo que pone una mesa
 * fuera de servicio: no se pueden abrir pedidos en ella. No se puede
 * desactivar una mesa con un pedido abierto.
 */
#[Unguarded]
class Table extends Model
{
    use HasFactory;

    public const OPEN_ORDER_MESSAGE = 'La mesa :name tiene un pedido abierto. Ciérralo o cóbralo antes de desactivarla.';

    protected static function booted(): void
    {
        static::saving(function (Table $table) {
            if ($table->exists && $table->isDirty('is_active') && ! $table->is_active && $table->isInUse()) {
                throw ValidationException::withMessages([
                    'is_active' => str_replace(':name', $table->name, self::OPEN_ORDER_MESSAGE),
                ]);
            }
        });
    }

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

    /** Fila activa de esta mesa unida al pedido de otra (no principal). */
    public function groupLink(): HasOne
    {
        return $this->hasOne(OrderTable::class)->whereNull('left_at')->where('is_main', false);
    }

    /** ¿Tiene algún pedido abierto, propio o de un grupo al que está unida? */
    public function isInUse(): bool
    {
        return $this->openOrders()->exists() || $this->groupLink()->exists();
    }
}
