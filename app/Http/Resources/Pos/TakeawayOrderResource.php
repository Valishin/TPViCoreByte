<?php

namespace App\Http\Resources\Pos;

use App\Enums\TicketType;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Datos de llevar / domicilio de un pedido: tarjeta del tablero "Pedidos" y
 * cabecera de la comanda.
 *
 * @mixin Order
 */
class TakeawayOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing(['courier', 'deliveryZone', 'tickets']);
        $ticket = $this->tickets->whereIn('type', TicketType::orderDocuments())->sortByDesc('id')->first();
        $address = $this->delivery_address;
        $zone = $this->deliveryZone;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'delivery_status' => $this->delivery_status?->value,
            'customer_id' => $this->customer_id,
            'customer_address_id' => $this->customer_address_id,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_notes' => $this->customer?->notes,
            'address' => $address,
            'address_line' => $address ? self::addressLine($address) : null,
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name, 'delivery_fee' => $zone->delivery_fee, 'minimum_order' => $zone->minimum_order] : null,
            'below_minimum' => $zone && $this->isOpen() ? max(0, $zone->minimum_order - ($this->total - $this->delivery_fee)) : 0,
            'promised_at' => $this->promised_at?->toIso8601String(),
            'asap' => $this->asap,
            'planned_payment_method' => $this->planned_payment_method?->value,
            'prepaid_method' => $this->prepaid_method?->value,
            'cash_change_for' => $this->cash_change_for,
            'courier' => $this->courier ? ['id' => $this->courier->id, 'name' => $this->courier->name] : null,
            'total' => $this->total,
            'delivery_fee' => $this->delivery_fee,
            'pending_count' => $this->whenLoaded('lines', fn () => $this->lines->whereNull('parent_line_id')->whereNull('sent_at')->whereNull('voided_at')->count()),
            'ticket' => $ticket ? [
                'id' => $ticket->id, 'number' => $ticket->fullNumber(),
                'payment_status' => $ticket->payment_status->value, 'status' => $ticket->status->value,
            ] : null,
            'settled' => $this->courier_settlement_id !== null,
            'opened_at' => $this->opened_at->toIso8601String(),
            'kitchen_at' => $this->kitchen_at?->toIso8601String(),
            'ready_at' => $this->ready_at?->toIso8601String(),
            'dispatched_at' => $this->dispatched_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'delivery_failed_reason' => $this->delivery_failed_reason,
        ];
    }

    /** "Calle Mayor 3, 2º B · 28001 Madrid" */
    public static function addressLine(array $a): string
    {
        $street = trim(($a['street'] ?? '').' '.($a['number'] ?? ''));
        $parts = array_filter([$street, $a['floor_door'] ?? null]);
        $place = trim(($a['postal_code'] ?? '').' '.($a['city'] ?? ''));

        return implode(', ', $parts).($place !== '' ? " · {$place}" : '');
    }
}
