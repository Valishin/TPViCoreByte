<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class Modifier extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['price_delta' => 'integer', 'is_active' => 'boolean'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    public function variantPrices(): HasMany
    {
        return $this->hasMany(ModifierVariantPrice::class);
    }

    /** Incremento de precio para un tamaño (si tiene precio específico) o el general. */
    public function priceDeltaFor(?string $variantCode): int
    {
        if ($variantCode !== null) {
            $specific = $this->variantPrices->firstWhere('variant_code', $variantCode);
            if ($specific) {
                return $specific->price_delta;
            }
        }

        return $this->price_delta;
    }
}
