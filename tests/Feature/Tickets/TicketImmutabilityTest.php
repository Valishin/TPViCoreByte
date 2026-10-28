<?php

namespace Tests\Feature\Tickets;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ImmutableTicketException;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\PaymentService;
use App\Services\Tickets\TicketIssuer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings();
        $this->user = User::factory()->create();

        $order = app(OrderService::class)->open(OrderType::Bar, $this->user);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(1200)->create(['name' => 'Croquetas']), quantity: 2);
        $this->ticket = app(TicketIssuer::class)->issue($order, $this->user);
    }

    public function test_ticket_cannot_be_modified(): void
    {
        $this->expectException(ImmutableTicketException::class);
        $this->ticket->update(['total' => 1]);
    }

    public function test_ticket_cannot_be_deleted(): void
    {
        $this->expectException(ImmutableTicketException::class);
        $this->ticket->delete();
    }

    public function test_ticket_lines_cannot_be_modified_or_deleted(): void
    {
        $line = $this->ticket->lines()->first();

        try {
            $line->update(['unit_price' => 1]);
            $this->fail('Se ha podido modificar una línea de ticket');
        } catch (ImmutableTicketException) {
        }

        $this->expectException(ImmutableTicketException::class);
        $line->delete();
    }

    public function test_database_trigger_blocks_mass_updates_and_deletes(): void
    {
        foreach ([
            fn () => Ticket::query()->whereKey($this->ticket->id)->update(['total' => 1]),
            fn () => Ticket::query()->whereKey($this->ticket->id)->delete(),
            fn () => TicketLine::query()->where('ticket_id', $this->ticket->id)->update(['name' => 'X']),
            fn () => TicketLine::query()->where('ticket_id', $this->ticket->id)->delete(),
        ] as $i => $attempt) {
            try {
                \DB::transaction($attempt);
                $this->fail("La operación {$i} no ha sido bloqueada por el trigger");
            } catch (QueryException $e) {
                $this->assertStringContainsString('inmutable', $e->getMessage());
            }
        }

        $this->assertSame(2400, $this->ticket->fresh()->total);
    }

    public function test_only_issued_to_rectified_status_transition_is_allowed(): void
    {
        $this->ticket->update(['status' => TicketStatus::Rectified]);
        $this->assertSame(TicketStatus::Rectified, $this->ticket->fresh()->status);

        $this->expectException(ImmutableTicketException::class);
        $this->ticket->update(['status' => TicketStatus::Issued]);
    }

    public function test_ticket_snapshot_survives_catalog_changes(): void
    {
        Product::query()->update(['name' => 'Croquetas caseras', 'price' => 9999]);

        $line = $this->ticket->lines()->first();
        $this->assertSame('Croquetas', $line->name);
        $this->assertSame(1200, $line->unit_price);
    }

    public function test_delivery_ticket_can_be_paid_after_issue_with_mixed_payments(): void
    {
        $zone = DeliveryZone::factory()->create(['delivery_fee' => 250]);
        $customer = Customer::factory()->create();
        $address = CustomerAddress::factory()->for($customer)->create(['delivery_zone_id' => $zone->id]);
        $orders = app(OrderService::class);

        $order = $orders->open(OrderType::Delivery, $this->user);
        $orders->attachCustomer($order, $customer, $address);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(1750)->create());
        $ticket = app(TicketIssuer::class)->issue($order, $this->user);

        $this->assertSame(2000, $ticket->total);
        $this->assertSame(PaymentStatus::Pending, $ticket->payment_status);
        $this->assertSame(OrderType::Delivery, $ticket->order_type);

        $payments = app(PaymentService::class);
        $payments->register($ticket, PaymentMethod::Card, 1000, $this->user);
        $this->assertSame(PaymentStatus::Pending, $ticket->fresh()->payment_status);

        $cash = $payments->register($ticket, PaymentMethod::Cash, 1000, $this->user, tendered: 2000);
        $this->assertSame(1000, $cash->change);
        $this->assertSame(PaymentStatus::Paid, $ticket->fresh()->payment_status);
        $this->assertSame(1, $customer->fresh()->orders_count);
    }
}
