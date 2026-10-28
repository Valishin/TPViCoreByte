<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Derechos de protección de datos (RGPD) de un cliente.
 *
 * - export(): todos sus datos personales en un array (derecho de acceso).
 * - anonymize(): borra sus datos personales de la ficha, las direcciones y
 *   los pedidos. Los TICKETS no se tocan: son documentos fiscales que hay que
 *   conservar y guardan su propia copia de los datos al emitirse.
 */
class CustomerPrivacyService
{
    public const ANONYMIZED_NAME = 'Cliente anonimizado';

    public function export(Customer $customer): array
    {
        $customer->load(['addresses', 'orders', 'tickets']);

        return [
            'exported_at' => now()->toIso8601String(),
            'customer' => $customer->only(['id', 'name', 'phone', 'notes', 'orders_count', 'last_order_at', 'created_at']),
            'addresses' => $customer->addresses->map->only([
                'label', 'street', 'number', 'floor_door', 'postal_code', 'city', 'instructions',
            ])->values()->all(),
            'orders' => $customer->orders->map(fn ($order) => [
                'id' => $order->id,
                'type' => $order->type->value,
                'status' => $order->status->value,
                'opened_at' => $order->opened_at?->toIso8601String(),
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'delivery_address' => $order->delivery_address,
                'total' => $order->total,
            ])->values()->all(),
            'tickets' => $customer->tickets->map(fn ($ticket) => [
                'number' => $ticket->fullNumber(),
                'issued_at' => $ticket->issued_at->toIso8601String(),
                'total' => $ticket->total,
                'customer' => $ticket->customer,
                'delivery_address' => $ticket->delivery_address,
            ])->values()->all(),
        ];
    }

    public function anonymize(Customer $customer): Customer
    {
        return DB::transaction(function () use ($customer) {
            // orders.customer_address_id es nullOnDelete: borrar direcciones no rompe pedidos.
            $customer->addresses()->delete();

            $customer->orders()->update([
                'customer_name' => null,
                'customer_phone' => null,
                'delivery_address' => null,
            ]);

            $customer->update([
                'name' => self::ANONYMIZED_NAME,
                'phone' => null,
                'notes' => null,
                'anonymized_at' => now(),
            ]);

            return $customer;
        });
    }
}
