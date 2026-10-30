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
            'fiscal' => $customer->only(['legal_name', 'tax_id', 'fiscal_address', 'fiscal_postal_code', 'fiscal_city',
                'fiscal_province', 'fiscal_country', 'billing_email']) + [
                    'tax_id_type' => $customer->tax_id_type?->value,
                    'email_invoices_accepted_at' => $customer->email_invoices_accepted_at?->toIso8601String(),
                ],
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
                'type' => $ticket->type->value,
                'customer' => $ticket->customer,
                'recipient' => $ticket->recipient, // datos fiscales copiados en la factura
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

            // Las facturas emitidas NO se tocan: son documentos fiscales con su
            // propia copia de los datos (obligación de conservar, art. 19 RD 1619/2012).
            $customer->update([
                'name' => self::ANONYMIZED_NAME,
                'phone' => null,
                'notes' => null,
                'legal_name' => null,
                'tax_id' => null,
                'tax_id_type' => null,
                'fiscal_address' => null,
                'fiscal_postal_code' => null,
                'fiscal_city' => null,
                'fiscal_province' => null,
                'fiscal_country' => null,
                'billing_email' => null,
                'email_invoices_accepted_at' => null,
                'anonymized_at' => now(),
            ]);

            return $customer;
        });
    }
}
