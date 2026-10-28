<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente identificado por teléfono. El teléfono se guarda normalizado
 * (E.164, p. ej. +34612345678); null tras anonimizar.
 */
#[Unguarded]
class Customer extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'orders_count' => 'integer',
            'last_order_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => PhoneNormalizer::normalize($value));
    }

    public function scopeWherePhone(Builder $query, string $phone): void
    {
        $query->where('phone', PhoneNormalizer::normalize($phone));
    }

    public static function findByPhone(string $phone): ?self
    {
        $normalized = PhoneNormalizer::normalize($phone);

        return $normalized === null ? null : static::query()->where('phone', $normalized)->first();
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
