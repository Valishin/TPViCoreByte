<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mesa de un pedido (mesas unidas). La principal coincide con orders.table_id.
 * Las filas no se borran: left_at marca cuándo la mesa dejó el pedido.
 * Solo las crea y cierra OrderService (y Order al cambiar su mesa principal).
 */
#[Unguarded]
class OrderTable extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'is_main' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** La mesa (no se llama «table»: choca con Model::$table). */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id');
    }

    public function joinedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'joined_by');
    }

    public function leftBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'left_by');
    }
}
