<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Unguarded]
class DeliveryZone extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'delivery_fee' => 'integer',
            'minimum_order' => 'integer',
            'postal_codes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
