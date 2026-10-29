<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\DeliveryZone;

class CustomerApiTest extends PosTestCase
{
    private DeliveryZone $centro;

    private DeliveryZone $ensanche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centro = DeliveryZone::create(['name' => 'Centro', 'delivery_fee' => 0, 'minimum_order' => 1200, 'postal_codes' => ['28001', '28004']]);
        $this->ensanche = DeliveryZone::create(['name' => 'Ensanche', 'delivery_fee' => 150, 'minimum_order' => 1500, 'postal_codes' => ['28010']]);
        DeliveryZone::create(['name' => 'Cerrada', 'delivery_fee' => 0, 'postal_codes' => ['28099'], 'is_active' => false]);
    }

    public function test_lookup_by_phone_in_any_format(): void
    {
        $customer = Customer::create(['phone' => '612345678', 'name' => 'Lucía Pérez', 'notes' => 'Timbre roto: llamar al llegar']);
        $customer->addresses()->create(['street' => 'Calle Mayor', 'number' => '3', 'postal_code' => '28001', 'delivery_zone_id' => $this->centro->id, 'is_default' => true]);

        foreach (['612 34 56 78', '+34 612-345-678', '0034612345678'] as $phone) {
            $this->asWaiter()->getJson('/api/customers/lookup?phone='.urlencode($phone))
                ->assertOk()
                ->assertJsonPath('phone', '+34612345678')
                ->assertJsonPath('customer.name', 'Lucía Pérez')
                ->assertJsonPath('customer.notes', 'Timbre roto: llamar al llegar')
                ->assertJsonPath('customer.addresses.0.zone.name', 'Centro')
                ->assertJsonPath('customer.recent_orders', []);
        }
    }

    public function test_lookup_unknown_or_anonymized_returns_no_customer(): void
    {
        Customer::create(['phone' => '699999999', 'name' => 'Borrado', 'anonymized_at' => now()]);

        $this->asWaiter()->getJson('/api/customers/lookup?phone=600000000')->assertOk()
            ->assertJsonPath('phone', '+34600000000')->assertJsonPath('customer', null);
        $this->asWaiter()->getJson('/api/customers/lookup?phone=699999999')->assertJsonPath('customer', null);
    }

    public function test_quick_signup_with_address_suggests_zone_by_postal_code(): void
    {
        $response = $this->asWaiter()->postJson('/api/customers', [
            'phone' => '611 22 33 44',
            'name' => 'Íñigo Muñoz',
            'address' => ['street' => 'Calle de Atocha', 'number' => '12', 'floor_door' => '3º B', 'postal_code' => '28010', 'instructions' => 'Portal verde'],
        ])->assertCreated();

        $response->assertJsonPath('customer.phone', '+34611223344')
            ->assertJsonPath('customer.addresses.0.floor_door', '3º B')
            ->assertJsonPath('customer.addresses.0.is_default', true)
            ->assertJsonPath('customer.addresses.0.zone.id', $this->ensanche->id)
            ->assertJsonPath('customer.addresses.0.zone.delivery_fee', 150);
    }

    public function test_signup_rejects_duplicate_phone(): void
    {
        Customer::create(['phone' => '611223344', 'name' => 'Ya existe']);

        $this->asWaiter()->postJson('/api/customers', ['phone' => '+34611223344', 'name' => 'Otro'])
            ->assertStatus(422)->assertJsonPath('message', 'Ya hay un cliente con ese teléfono.');
    }

    public function test_add_address_and_zone_endpoint(): void
    {
        $customer = Customer::create(['phone' => '611223344', 'name' => 'Ana']);

        $this->asWaiter()->postJson("/api/customers/{$customer->id}/addresses", ['street' => 'Gran Vía', 'number' => '1', 'postal_code' => '28099'])
            ->assertCreated()->assertJsonPath('address.zone', null); // zona inactiva: no se sugiere

        $this->asWaiter()->getJson('/api/delivery-zones?postal_code=28004')->assertOk()
            ->assertJsonPath('suggested_id', $this->centro->id)
            ->assertJsonCount(2, 'data');
    }
}
