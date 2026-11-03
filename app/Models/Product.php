<?php

namespace App\Models;

use App\Enums\Allergen;
use App\Enums\OrderType;
use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Producto de la carta. Precios en céntimos con IVA incluido; IVA en puntos
 * básicos (1000 = 10 %).
 */
#[Unguarded]
class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'allergens' => AsEnumCollection::of(Allergen::class),
            'price' => 'integer',
            'vat_rate' => 'integer',
            'vat_rate_takeaway' => 'integer',
            'allows_half_and_half' => 'boolean',
            'half_and_half_surcharge' => 'integer',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')->withPivot('sort_order')->orderByPivot('sort_order');
    }

    /** Grupos de elección (solo productos tipo menú). */
    public function menuGroups(): HasMany
    {
        return $this->hasMany(MenuGroup::class)->orderBy('sort_order');
    }

    /** Tipo de IVA a aplicar según el tipo de pedido. */
    public function vatRateFor(OrderType $orderType): int
    {
        return $orderType->isTakeaway() && $this->vat_rate_takeaway !== null
            ? $this->vat_rate_takeaway
            : $this->vat_rate;
    }

    /** Impresora de destino: la del producto o, si no tiene, la de su categoría. */
    public function resolvePrinterId(): ?int
    {
        return $this->printer_id ?? $this->category?->printer_id;
    }

    /** Precio suelto en una variante (o el del producto si no hay variante). */
    public function priceFor(?ProductVariant $variant): int
    {
        return $variant?->price ?? $this->price;
    }
}
