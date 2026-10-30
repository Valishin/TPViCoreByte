<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\Customers\CustomerPrivacyService;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

class CustomerResourceTest extends FilamentTestCase
{
    public function test_search_by_phone_in_any_format(): void
    {
        $lucia = Customer::factory()->create(['name' => 'Lucía', 'phone' => '612345678']);
        $other = Customer::factory()->create(['name' => 'Otro', 'phone' => '699000000']);

        Livewire::test(ListCustomers::class)
            ->searchTable('612 34')
            ->assertCanSeeTableRecords([$lucia])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('lucía')
            ->assertCanSeeTableRecords([$lucia]);
    }

    public function test_duplicate_phone_in_other_format_is_rejected(): void
    {
        Customer::factory()->create(['phone' => '612345678']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Nuevo', 'phone' => '+34 612 34 56 78'])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }

    public function test_anonymize_requires_typed_confirmation(): void
    {
        $customer = Customer::factory()->create(['name' => 'Lucía']);
        CustomerAddress::factory()->for($customer)->create();

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('anonymize')->table($customer), data: ['confirmation' => 'si'])
            ->assertHasActionErrors(['confirmation']);
        $this->assertFalse($customer->fresh()->isAnonymized());

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('anonymize')->table($customer), data: ['confirmation' => 'ANONIMIZAR'])
            ->assertHasNoActionErrors()
            ->assertNotified('Cliente anonimizado');

        $customer->refresh();
        $this->assertTrue($customer->isAnonymized());
        $this->assertSame(CustomerPrivacyService::ANONYMIZED_NAME, $customer->name);
        $this->assertSame(0, $customer->addresses()->count());
    }

    public function test_export_downloads_json(): void
    {
        $customer = Customer::factory()->create();

        Livewire::test(ListCustomers::class)
            ->callAction(TestAction::make('exportData')->table($customer))
            ->assertFileDownloaded("cliente-{$customer->id}-datos.json");
    }

    public function test_company_without_phone_with_valid_fiscal_data(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['legal_name' => 'Restaurants del Port SL', 'tax_id' => 'B12345678', 'fiscal_address' => 'Passeig, 5',
                'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu de Guíxols'])
            ->call('create')
            ->assertHasFormErrors(['tax_id']);

        Livewire::test(CreateCustomer::class)
            ->fillForm(['legal_name' => 'Restaurants del Port SL', 'tax_id' => 'B12345674', 'fiscal_address' => 'Passeig, 5',
                'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu de Guíxols', 'email_invoices_accepted_at' => true,
                'billing_email' => 'a@port.cat'])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->where('tax_id', 'B12345674')->sole();
        $this->assertNull($customer->phone);
        $this->assertSame('Restaurants del Port SL', $customer->name);
        $this->assertTrue($customer->acceptsEmailInvoices());
    }

    public function test_phone_or_tax_id_is_required(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm(['name' => 'Nadie'])
            ->call('create')
            ->assertHasFormErrors(['phone']);
    }
}
