<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Variante con precio propio (mediana, familiar...). `code` casa tamaños entre productos. */
#[Unguarded]
class ProductVariant extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price' => 'integer', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
