<?php

namespace App\Models;

use App\Enums\CashMovementType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrada o salida de efectivo del cajón que NO es venta (fondo de cambio del
 * repartidor). amount: + entra, − sale. Cuenta en el efectivo esperado del
 * cierre, pero no en ventas ni en IVA.
 */
#[Unguarded]
class CashMovement extends Model
{
    protected function casts(): array
    {
        return ['type' => CashMovementType::class, 'amount' => 'integer'];
    }

    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class, 'courier_settlement_id');
    }
}
