<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;

/** Local con un cliente de facturación (empresa) listo. */
abstract class InvoiceTestCase extends TakeawayTestCase
{
    protected Customer $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Customer::create([
            'legal_name' => 'Restaurants del Port SL', 'tax_id' => 'B12345674',
            'fiscal_address' => 'Passeig dels Guíxols, 5', 'fiscal_postal_code' => '17220',
            'fiscal_city' => 'Sant Feliu de Guíxols', 'fiscal_province' => 'Girona',
            'billing_email' => 'admin@port.cat', 'email_invoices_accepted_at' => now(),
        ]);
    }

    protected function invoiceCheckout(int $orderId, int $amount, array $extra = [])
    {
        return $this->checkout($orderId, [['method' => 'tarjeta', 'amount' => $amount]], null, ['invoice_customer_id' => $this->company->id] + $extra);
    }
}
