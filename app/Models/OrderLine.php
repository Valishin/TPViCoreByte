<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderLineKind;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Línea de pedido con snapshot de nombre, precio e IVA.
 *
 * Jerarquía (parent_line_id):
 *  - Menú: padre con el precio del menú; hijas `menu_componente` con su
 *    suplemento en unit_price y su precio suelto en reference_price.
 *  - Mitad y mitad: padre con el precio según la regla; dos hijas `mitad`.
 * El precio del padre se reparte entre las hijas (allocated_amount) en
 * proporción a reference_price y cada parte tributa al IVA de su hija.
 * taxable_amount es lo que tributa a vat_rate en ESTA línea tras descuentos.
 */
#[Unguarded]
class OrderLine extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => OrderLineKind::class,
            'discount_type' => DiscountType::class,
            'is_invitation' => 'boolean',
            'unit_price' => 'integer',
            'vat_rate' => 'integer',
            'reference_price' => 'integer',
            'allocated_amount' => 'integer',
            'quantity' => 'integer',
            'discount_value' => 'integer',
            'discount_amount' => 'integer',
            'line_total' => 'integer',
            'order_discount_amount' => 'integer',
            'taxable_amount' => 'integer',
            'course' => 'integer',
            'sent_at' => 'datetime',
            'fired_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'parent_line_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(OrderLine::class, 'parent_line_id')->orderBy('sort_order')->orderBy('id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(OrderLineModifier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function discountedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discounted_by');
    }
}
