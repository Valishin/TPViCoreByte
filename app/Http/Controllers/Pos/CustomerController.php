<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Http\Resources\Pos\CustomerResource;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Services\Customers\CustomerDirectory;
use App\Support\PhoneNormalizer;
use App\Support\PostalCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Clientes y zonas para los pedidos de llevar y domicilio en caja. */
class CustomerController extends Controller
{
    public function __construct(private CustomerDirectory $customers) {}

    /** Busca por teléfono (en cualquier formato). customer = null si no existe. */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $normalized = PhoneNormalizer::normalize($data['phone']);
        $customer = $normalized ? $this->customers->findByPhone($normalized) : null;

        return response()->json([
            'phone' => $normalized,
            'customer' => $customer ? $this->present($customer, $request) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'address' => ['nullable', 'array'],
            ...$this->addressRules('address.'),
        ]);

        $customer = $this->customers->create($data['phone'], $data, $data['address'] ?? null);

        return response()->json(['customer' => $this->present($customer, $request)], 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $customer->update($data);

        return response()->json(['customer' => $this->present($customer, $request)]);
    }

    public function addAddress(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['street' => ['required'], ...$this->addressRules()]);
        $address = $this->customers->addAddress($customer, $data);

        return response()->json(['address' => CustomerResource::address($address->load('deliveryZone')), 'customer' => $this->present($customer, $request)], 201);
    }

    /** Zonas activas y la sugerida para un código postal. */
    public function zones(Request $request): JsonResponse
    {
        $postalCode = $request->string('postal_code')->trim()->toString();

        return response()->json([
            'data' => DeliveryZone::query()->where('is_active', true)->orderBy('name')->get()->map(fn ($z) => CustomerResource::zone($z)),
            'suggested_id' => $this->customers->zoneForPostalCode($postalCode)?->id,
            // Municipio y provincia del CP, para rellenar la dirección.
            'city' => PostalCode::city($postalCode),
            'province' => PostalCode::province($postalCode),
        ]);
    }

    private function present(Customer $customer, Request $request): array
    {
        $customer->load('addresses.deliveryZone');
        $customer->setAttribute('recent_orders', $this->customers->recentOrders($customer));

        return (new CustomerResource($customer))->resolve($request);
    }

    private function addressRules(string $prefix = ''): array
    {
        return [
            "{$prefix}street" => ["required_with:{$prefix}number", 'string', 'max:255'],
            "{$prefix}number" => ['nullable', 'string', 'max:20'],
            "{$prefix}floor_door" => ['nullable', 'string', 'max:50'],
            "{$prefix}postal_code" => ['nullable', 'string', 'max:10'],
            "{$prefix}city" => ['nullable', 'string', 'max:255'],
            "{$prefix}label" => ['nullable', 'string', 'max:50'],
            "{$prefix}instructions" => ['nullable', 'string', 'max:500'],
            "{$prefix}delivery_zone_id" => ['nullable', 'integer', 'exists:delivery_zones,id'],
            "{$prefix}is_default" => ['boolean'],
        ];
    }
}
