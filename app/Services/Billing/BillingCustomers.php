<?php

namespace App\Services\Billing;

use App\Enums\TaxIdType;
use App\Models\Customer;
use App\Models\Setting;
use App\Rules\TaxIdRule;
use App\Support\TaxId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

/**
 * Destinatarios de facturas completas: clientes con datos fiscales (art. 6.1.c,
 * d, e RD 1619/2012). Puede ser un cliente de reparto con sus datos fiscales o
 * un cliente solo de facturación (empresa, sin teléfono).
 */
class BillingCustomers
{
    /** Búsqueda rápida por NIF (exacto o el principio) o por nombre / razón social. */
    public function search(string $query, int $limit = 10): Collection
    {
        $query = trim($query);
        $taxId = TaxId::normalize($query);

        return Customer::query()
            ->whereNull('anonymized_at')
            ->where(function ($q) use ($query, $taxId) {
                if ($taxId && strlen($taxId) >= 3) {
                    $q->orWhere('tax_id', 'like', $taxId.'%');
                }
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%';
                $q->orWhere('legal_name', 'ilike', $like)->orWhere('name', 'ilike', $like);
            })
            ->orderByRaw('tax_id IS NULL')   // primero los que ya tienen datos fiscales
            ->orderBy('legal_name')->orderBy('name')
            ->limit($limit)->get();
    }

    /** Reglas de los datos fiscales (también para completar un cliente de reparto). */
    public static function rules(?Customer $customer = null, array $input = []): array
    {
        $type = TaxIdType::tryFrom((string) ($input['tax_id_type'] ?? '')) ?? TaxId::detect($input['tax_id'] ?? null);
        $address = Setting::query()->value('invoice_require_address') ?? true ? 'required' : 'nullable';

        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'tax_id_type' => ['nullable', Rule::enum(TaxIdType::class)],
            'tax_id' => ['required', 'string', 'max:30', new TaxIdRule($type),
                function (string $attr, $value, \Closure $fail) use ($customer) {
                    $existing = Customer::query()->where('tax_id', TaxId::normalize($value))->first();
                    if ($existing && ! $existing->is($customer)) {
                        $fail("Ese NIF ya es de {$existing->legal_name}.");
                    }
                }],
            'fiscal_address' => [$address, 'string', 'max:255'],
            'fiscal_postal_code' => [$address, 'string', 'max:10'],
            'fiscal_city' => [$address, 'string', 'max:255'],
            'fiscal_province' => ['nullable', 'string', 'max:255'],
            'fiscal_country' => ['nullable', 'string', 'size:2'],
            'billing_email' => ['nullable', 'email', 'max:255'],
            'accepts_email_invoices' => ['boolean'],
        ];
    }

    /** Alta o actualización de los datos fiscales. */
    public function save(array $data, ?Customer $customer = null): Customer
    {
        $customer ??= new Customer;
        $accepts = $data['accepts_email_invoices'] ?? null;
        unset($data['accepts_email_invoices']);
        if (isset($data['fiscal_country'])) {
            $data['fiscal_country'] = strtoupper($data['fiscal_country']);
        }
        $customer->fill($data);
        if ($accepts !== null) {
            // Consentimiento del envío electrónico (art. 9.2): se guarda la fecha en que se dio.
            $customer->email_invoices_accepted_at = $accepts ? ($customer->email_invoices_accepted_at ?? now()) : null;
        }
        $customer->save();

        return $customer;
    }
}
