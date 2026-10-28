<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Pedido. Editable mientras está abierto; al cobrarse se emite un Ticket
 * inmutable. Los totales (subtotal, discount_amount, total) son una caché que
 * recalcula App\Services\Orders\OrderTotalsService.
 */
#[Unguarded]
class Order extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Order $order) {
            if ($order->type !== OrderType::Table && $order->table_id !== null) {
                throw new LogicException('Solo los pedidos de tipo mesa pueden tener mesa.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'status' => OrderStatus::class,
            'delivery_status' => DeliveryStatus::class,
            'planned_payment_method' => PaymentMethod::class,
            'discount_type' => DiscountType::class,
            'delivery_address' => 'array',
            'promised_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'guests' => 'integer',
            'cash_change_for' => 'integer',
            'delivery_fee' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'subtotal' => 'integer',
            'total' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === OrderStatus::Open;
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    public function deliveryZone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class);
    }

    /** Todas las líneas (padres e hijas). */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Solo líneas de primer nivel. */
    public function rootLines(): HasMany
    {
        return $this->lines()->whereNull('parent_line_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
