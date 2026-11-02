<?php

namespace App\Services\Customers;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Support\PhoneNormalizer;
use App\Support\PostalCode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Clientes de llevar y domicilio en caja: búsqueda por teléfono normalizado,
 * alta rápida y direcciones con su zona de reparto (sugerida por código postal).
 */
class CustomerDirectory
{
    public function findByPhone(string $phone): ?Customer
    {
        $customer = Customer::findByPhone($phone);

        return $customer?->isAnonymized() ? null : $customer;
    }

    /** Últimos pedidos cerrados (para "Repetir último pedido"). */
    public function recentOrders(Customer $customer, int $limit = 5): Collection
    {
        return $customer->orders()
            ->where('status', OrderStatus::Closed)
            ->with(['lines' => fn ($q) => $q->whereNull('parent_line_id')->whereNull('voided_at')])
            ->latest('opened_at')->limit($limit)->get();
    }

    /**
     * @param  array{name?: string|null, notes?: string|null}  $data
     * @param  array<string, mixed>|null  $address
     */
    public function create(string $phone, array $data, ?array $address = null): Customer
    {
        $normalized = PhoneNormalizer::normalize($phone) ?? throw new InvalidArgumentException('El teléfono no es válido.');
        if (Customer::query()->where('phone', $normalized)->exists()) {
            throw new InvalidArgumentException('Ya hay un cliente con ese teléfono.');
        }

        return DB::transaction(function () use ($normalized, $data, $address) {
            $customer = Customer::create([
                'phone' => $normalized,
                'name' => $data['name'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            if ($address) {
                $this->addAddress($customer, $address);
            }

            return $customer;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function addAddress(Customer $customer, array $data): CustomerAddress
    {
        $zoneId = $data['delivery_zone_id'] ?? $this->zoneForPostalCode($data['postal_code'] ?? null)?->id;

        return DB::transaction(function () use ($customer, $data, $zoneId) {
            $first = $customer->addresses()->doesntExist();
            if (! empty($data['is_default'])) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $customer->addresses()->create([
                'label' => $data['label'] ?? null,
                'street' => $data['street'],
                'number' => $data['number'] ?? null,
                'floor_door' => $data['floor_door'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'city' => trim((string) ($data['city'] ?? '')) ?: PostalCode::city($data['postal_code'] ?? null),
                'instructions' => $data['instructions'] ?? null,
                'delivery_zone_id' => $zoneId,
                'is_default' => $first || ! empty($data['is_default']),
            ]);
        });
    }

    /** Zona activa que incluye ese código postal (null si ninguna). */
    public function zoneForPostalCode(?string $postalCode): ?DeliveryZone
    {
        $postalCode = trim((string) $postalCode);
        if ($postalCode === '') {
            return null;
        }

        return DeliveryZone::query()->where('is_active', true)->orderBy('name')->get()
            ->first(fn (DeliveryZone $zone) => in_array($postalCode, array_map('strval', $zone->postal_codes ?? []), true));
    }
}
