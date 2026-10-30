<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\Setting;
use App\Services\Customers\CustomerPrivacyService;

class BillingCustomerApiTest extends PosTestCase
{
    private array $company = [
        'legal_name' => 'Restaurants del Port SL', 'tax_id' => 'b-12345674',
        'fiscal_address' => 'Passeig dels Guíxols, 5', 'fiscal_postal_code' => '17220',
        'fiscal_city' => 'Sant Feliu de Guíxols', 'fiscal_province' => 'Girona',
        'billing_email' => 'admin@port.cat', 'accepts_email_invoices' => true,
    ];

    public function test_quick_signup_of_a_company_without_phone(): void
    {
        $this->asWaiter()->postJson('/api/billing-customers', $this->company)->assertCreated()
            ->assertJsonPath('data.tax_id', 'B12345674')
            ->assertJsonPath('data.tax_id_type', 'cif')
            ->assertJsonPath('data.fiscal_country', 'ES')
            ->assertJsonPath('data.name', 'Restaurants del Port SL')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.accepts_email_invoices', true)
            ->assertJsonPath('data.has_fiscal_data', true);
        $this->assertNotNull(Customer::sole()->email_invoices_accepted_at);
    }

    public function test_invalid_or_duplicate_tax_id_is_rejected(): void
    {
        $this->asWaiter()->postJson('/api/billing-customers', ['tax_id' => 'B12345678'] + $this->company)
            ->assertUnprocessable()->assertJsonPath('errors.tax_id.0', 'El CIF no es válido: revisa el dígito o la letra de control.');

        $this->asWaiter()->postJson('/api/billing-customers', $this->company)->assertCreated();
        $this->asWaiter()->postJson('/api/billing-customers', ['tax_id' => 'B 1234567-4'] + $this->company)
            ->assertUnprocessable()->assertJsonPath('errors.tax_id.0', 'Ese NIF ya es de Restaurants del Port SL.');
    }

    public function test_address_required_unless_setting_disabled(): void
    {
        $data = ['legal_name' => 'Joan Puig', 'tax_id' => '12345678Z'];
        $this->asWaiter()->postJson('/api/billing-customers', $data)->assertUnprocessable()
            ->assertJsonValidationErrors(['fiscal_address', 'fiscal_postal_code', 'fiscal_city']);

        Setting::current()->update(['invoice_require_address' => false]);
        $this->asWaiter()->postJson('/api/billing-customers', $data)->assertCreated()
            ->assertJsonPath('data.tax_id_type', 'nif');
    }

    public function test_eu_vat_and_passport(): void
    {
        $this->asWaiter()->postJson('/api/billing-customers', ['tax_id' => 'DE123456789'] + $this->company)->assertCreated()
            ->assertJsonPath('data.tax_id_type', 'vat_eu')->assertJsonPath('data.fiscal_country', 'DE');
        $this->asWaiter()->postJson('/api/billing-customers', ['tax_id' => 'AB1234567', 'tax_id_type' => 'otro', 'fiscal_country' => 'gb'] + $this->company)->assertCreated()
            ->assertJsonPath('data.tax_id_type', 'otro')->assertJsonPath('data.fiscal_country', 'GB');
    }

    public function test_search_by_tax_id_or_name_and_add_fiscal_data_to_delivery_customer(): void
    {
        $delivery = Customer::create(['phone' => '612345678', 'name' => 'Lucía Pérez']);
        $this->asWaiter()->postJson('/api/billing-customers', $this->company)->assertCreated();

        $this->asWaiter()->getJson('/api/billing-customers?q=b1234')->assertOk()->assertJsonPath('data.0.legal_name', 'Restaurants del Port SL');
        $this->asWaiter()->getJson('/api/billing-customers?q=port')->assertOk()->assertJsonCount(1, 'data');
        $this->asWaiter()->getJson('/api/billing-customers?q=lucía')->assertOk()->assertJsonPath('data.0.has_fiscal_data', false);

        $this->asWaiter()->putJson("/api/billing-customers/{$delivery->id}", [
            'legal_name' => 'Lucía Pérez Roig', 'tax_id' => '00000000T', 'fiscal_address' => 'Carrer Major, 3',
            'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu de Guíxols',
        ])->assertOk()->assertJsonPath('data.phone', '+34612345678')->assertJsonPath('data.has_fiscal_data', true);
    }

    public function test_anonymize_clears_fiscal_data_and_export_includes_it(): void
    {
        $id = $this->asWaiter()->postJson('/api/billing-customers', $this->company)->json('data.id');
        $customer = Customer::findOrFail($id);

        $export = app(CustomerPrivacyService::class)->export($customer);
        $this->assertSame('B12345674', $export['fiscal']['tax_id']);

        app(CustomerPrivacyService::class)->anonymize($customer);
        $customer->refresh();
        $this->assertNull($customer->tax_id);
        $this->assertNull($customer->legal_name);
        $this->assertNull($customer->email_invoices_accepted_at);
        $this->asWaiter()->getJson('/api/billing-customers?q=port')->assertJsonCount(0, 'data');
    }
}
