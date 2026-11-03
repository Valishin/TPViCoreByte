<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una fila del grupo: nombre, código de tamaño y precio opcional (céntimos). */
#[Unguarded]
class VariantGroupItem extends Model
{
    protected function casts(): array
    {
        return ['price' => 'integer', 'sort_order' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(VariantGroup::class, 'variant_group_id');
    }
}
