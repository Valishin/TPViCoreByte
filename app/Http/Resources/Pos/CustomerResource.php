<?php

namespace App\Http\Resources\Pos;

use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Ficha del cliente en caja: contacto, notas, direcciones con zona y últimos pedidos. */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'name' => $this->name,
            'notes' => $this->notes,
            'orders_count' => $this->orders_count,
            'last_order_at' => $this->last_order_at?->toIso8601String(),
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses
                ->sortByDesc('is_default')->values()
                ->map(fn (CustomerAddress $a) => self::address($a))),
            'recent_orders' => $this->when(isset($this->resource->recent_orders), fn () => $this->resource->recent_orders->map(fn ($o) => [
                'id' => $o->id,
                'type' => $o->type->value,
                'opened_at' => $o->opened_at->toIso8601String(),
                'total' => $o->total,
                'summary' => $o->lines->map(fn ($l) => "{$l->quantity}× {$l->name}".($l->variant_name ? " ({$l->variant_name})" : ''))->values(),
            ])->values()),
        ];
    }

    public static function address(CustomerAddress $a): array
    {
        return [
            'id' => $a->id,
            'label' => $a->label,
            'street' => $a->street,
            'number' => $a->number,
            'floor_door' => $a->floor_door,
            'postal_code' => $a->postal_code,
            'city' => $a->city,
            'instructions' => $a->instructions,
            'is_default' => $a->is_default,
            'zone' => $a->deliveryZone ? self::zone($a->deliveryZone) : null,
        ];
    }

    public static function zone(DeliveryZone $z): array
    {
        return ['id' => $z->id, 'name' => $z->name, 'delivery_fee' => $z->delivery_fee, 'minimum_order' => $z->minimum_order];
    }
}
