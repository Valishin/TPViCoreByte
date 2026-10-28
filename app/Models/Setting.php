<?php

namespace App\Models;

use App\Enums\HalfAndHalfModifierPricing;
use App\Enums\HalfAndHalfPricing;
use App\Enums\OrderType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajustes del local (una sola fila). Usa Setting::current().
 */
#[Unguarded]
class Setting extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled_order_types' => AsEnumCollection::of(OrderType::class),
            'half_and_half_pricing' => HalfAndHalfPricing::class,
            'half_and_half_modifier_pricing' => HalfAndHalfModifierPricing::class,
            'delivery_fee_vat_rate' => 'integer',
            'last_z_number' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrFail();
    }

    public function isOrderTypeEnabled(OrderType $type): bool
    {
        return $this->enabled_order_types->contains($type);
    }

    /** Datos fiscales que se copian en cada ticket. */
    public function issuerSnapshot(): array
    {
        return $this->only(['trade_name', 'legal_name', 'tax_id', 'address', 'postal_code', 'city', 'province', 'phone']);
    }

    public function defaultTicketSeries(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'default_ticket_series_id');
    }

    public function rectifyingTicketSeries(): BelongsTo
    {
        return $this->belongsTo(TicketSeries::class, 'rectifying_ticket_series_id');
    }
}
