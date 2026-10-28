<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de modificadores. Obligatorio si min_select > 0; max_select null = sin límite. */
#[Unguarded]
class ModifierGroup extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['min_select' => 'integer', 'max_select' => 'integer'];
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class)->orderBy('sort_order');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group');
    }

    public function isRequired(): bool
    {
        return $this->min_select > 0;
    }
}
