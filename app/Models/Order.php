<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
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
            'prepaid_method' => PaymentMethod::class,
            'asap' => 'boolean',
            'kitchen_at' => 'datetime',
            'ready_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
            'send_count' => 'integer',
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

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function courierSettlement(): BelongsTo
    {
        return $this->belongsTo(CourierSettlement::class);
    }

    /**
     * Hora a la que cocina debe tenerlo listo: para llevar, la prometida; a
     * domicilio, la prometida menos el reparto (tiempo de domicilio − tiempo de
     * preparación de Ajustes).
     */
    public function readyBy(?Setting $settings = null): ?CarbonInterface
    {
        if (! $this->promised_at) {
            return null;
        }
        if ($this->type !== OrderType::Delivery) {
            return $this->promised_at;
        }
        $settings ??= Setting::current();

        return $this->promised_at->copy()->subMinutes(max(0, $settings->delivery_minutes - $settings->takeaway_prep_minutes));
    }

    /** "21:30" o "Lo antes posible (21:30)". */
    public function promisedLabel(): ?string
    {
        if (! $this->promised_at) {
            return null;
        }

        return $this->asap ? "Lo antes posible ({$this->promised_at->format('H:i')})" : $this->promised_at->format('H:i');
    }

    /** Para llevar o domicilio (los que van al tablero "Pedidos"). */
    public function isTakeawayOrDelivery(): bool
    {
        return $this->type === OrderType::Takeaway || $this->type === OrderType::Delivery;
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
        // Orden de árbol: cada línea padre seguida de sus hijas.
        return $this->hasMany(OrderLine::class)->orderByRaw(
            '(select p.sort_order from order_lines p where p.id = coalesce(order_lines.parent_line_id, order_lines.id)), '
            .'coalesce(order_lines.parent_line_id, order_lines.id), order_lines.parent_line_id nulls first, order_lines.sort_order, order_lines.id'
        );
    }

    /** Solo líneas de primer nivel. */
    public function rootLines(): HasMany
    {
        return $this->lines()->whereNull('parent_line_id');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class)->latest('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
