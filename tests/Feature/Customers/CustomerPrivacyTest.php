<?php

namespace Tests\Feature\Customers;

use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\User;
use App\Services\Customers\CustomerPrivacyService;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        $user = User::factory()->create();
        $this->customer = Customer::factory()->create(['name' => 'Lucía Pérez', 'phone' => '612345678', 'notes' => 'Timbre roto']);
        $address = CustomerAddress::factory()->for($this->customer)->create([
            'street' => 'Calle Pez', 'number' => '7', 'delivery_zone_id' => DeliveryZone::factory(),
        ]);

        $orders = app(OrderService::class);
        $order = $orders->open(OrderType::Delivery, $user);
        $orders->attachCustomer($order, $this->customer, $address);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(1500)->create());
        app(TicketIssuer::class)->issue($order, $user);
    }

    public function test_order_keeps_address_snapshot_when_customer_address_changes(): void
    {
        $this->customer->addresses()->first()->update(['street' => 'Calle Nueva']);

        $this->assertSame('Calle Pez', $this->customer->orders()->first()->delivery_address['street']);
    }

    public function test_export_contains_customer_data(): void
    {
        $export = app(CustomerPrivacyService::class)->export($this->customer);

        $this->assertSame('Lucía Pérez', $export['customer']['name']);
        $this->assertSame('+34612345678', $export['customer']['phone']);
        $this->assertCount(1, $export['addresses']);
        $this->assertCount(1, $export['orders']);
        $this->assertCount(1, $export['tickets']);
        $this->assertJson(json_encode($export));
    }

    public function test_anonymize_removes_personal_data_but_keeps_ticket_snapshot(): void
    {
        $ticket = $this->customer->tickets()->first();

        app(CustomerPrivacyService::class)->anonymize($this->customer);

        $customer = $this->customer->fresh();
        $this->assertSame(CustomerPrivacyService::ANONYMIZED_NAME, $customer->name);
        $this->assertNull($customer->phone);
        $this->assertNull($customer->notes);
        $this->assertTrue($customer->isAnonymized());
        $this->assertSame(0, $customer->addresses()->count());

        $order = $customer->orders()->first();
        $this->assertNull($order->customer_phone);
        $this->assertNull($order->delivery_address);
        $this->assertNull($order->customer_address_id);

        // El ticket es un documento fiscal: conserva su copia.
        $ticket = $ticket->fresh();
        $this->assertSame('Lucía Pérez', $ticket->customer['name']);
        $this->assertSame('+34612345678', $ticket->customer['phone']);
        $this->assertSame('Calle Pez', $ticket->delivery_address['street']);
    }

    public function test_anonymized_phone_can_be_reused_by_a_new_customer(): void
    {
        app(CustomerPrivacyService::class)->anonymize($this->customer);

        $new = Customer::factory()->create(['phone' => '612 345 678']);

        $this->assertTrue($new->is(Customer::findByPhone('612345678')));
    }
}
