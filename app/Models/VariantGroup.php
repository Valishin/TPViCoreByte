<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Grupo de variantes reutilizable («Tamaños de pizza»): se copia a cada producto que lo use. */
#[Unguarded]
class VariantGroup extends Model
{
    public function items(): HasMany
    {
        return $this->hasMany(VariantGroupItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
