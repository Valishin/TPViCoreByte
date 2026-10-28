<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Producto elegible dentro de un grupo de menú, con suplemento opcional. */
#[Unguarded]
class MenuGroupItem extends Model
{
    protected function casts(): array
    {
        return ['supplement' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MenuGroup::class, 'menu_group_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
