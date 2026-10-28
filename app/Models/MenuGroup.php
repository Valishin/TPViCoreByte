<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de elección de un menú ("elige 1 bebida"). */
#[Unguarded]
class MenuGroup extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['min_select' => 'integer', 'max_select' => 'integer'];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuGroupItem::class)->orderBy('sort_order');
    }
}
