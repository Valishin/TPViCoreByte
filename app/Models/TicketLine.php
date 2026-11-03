<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderLineKind;
use App\Exceptions\ImmutableTicketException;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Copia congelada de una línea de pedido. INMUTABLE (no se edita ni se borra). */
#[Unguarded]
class TicketLine extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableTicketException('Las líneas de ticket son inmutables.'));
        static::deleting(fn () => throw new ImmutableTicketException('Las líneas de ticket no se pueden borrar.'));
    }

    protected function casts(): array
    {
        return [
            'kind' => OrderLineKind::class,
            'discount_type' => DiscountType::class,
            'is_invitation' => 'boolean',
            'modifiers' => 'array',
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'unit_price_net' => 'integer', // sin IVA, en diezmilésimas de euro (art. 6.1.f)
            'vat_rate' => 'integer',
            'reference_price' => 'integer',
            'allocated_amount' => 'integer',
            'half_and_half_surcharge' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'order_discount_amount' => 'integer',
            'line_total' => 'integer',
            'taxable_amount' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(TicketLine::class, 'parent_line_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(TicketLine::class, 'parent_line_id')->orderBy('sort_order')->orderBy('id');
    }
}
