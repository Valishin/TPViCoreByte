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
            'postal_code_cities' => 'array',   // {"17220": "Sant Feliu de Guíxols"}
            'is_active' => 'boolean',
        ];
    }

    /** Municipio que se ha puesto en /admin para ese código postal de la zona. */
    public function cityFor(?string $postalCode): ?string
    {
        $city = trim((string) (($this->postal_code_cities ?? [])[trim((string) $postalCode)] ?? ''));

        return $city !== '' ? $city : null;
    }

    /** Municipio de un código postal según las zonas de reparto (las activas primero). */
    public static function cityForPostalCode(?string $postalCode): ?string
    {
        $postalCode = trim((string) $postalCode);
        if ($postalCode === '') {
            return null;
        }

        return static::query()->whereNotNull('postal_code_cities')->orderByDesc('is_active')->orderBy('id')->get()
            ->map(fn (self $zone) => $zone->cityFor($postalCode))->filter()->first();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }
}
