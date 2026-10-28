<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\TableStatus;
use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Tickets\PaymentService;
use App\Services\Tickets\TicketIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_is_occupied_while_order_is_open_and_freed_on_ticket(): void
    {
        $this->createSettings();
        $user = User::factory()->create();
        $table = Table::factory()->create();

        $order = app(OrderService::class)->open(OrderType::Table, $user, $table, guests: 4);
        $this->assertSame(TableStatus::Occupied, $table->fresh()->status);

        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(300)->create());
        app(TicketIssuer::class)->issue($order, $user);

        $this->assertSame(TableStatus::Free, $table->fresh()->status);
    }

    public function test_disabled_order_type_cannot_be_opened(): void
    {
        $this->createSettings(['enabled_order_types' => [OrderType::Takeaway, OrderType::Delivery]]);

        $this->expectException(InvalidArgumentException::class);
        app(OrderService::class)->open(OrderType::Bar, User::factory()->create());
    }

    public function test_line_snapshot_survives_catalog_changes(): void
    {
        $this->createSettings();
        $product = Product::factory()->price(450)->create(['name' => 'Caña']);
        $order = app(OrderService::class)->open(OrderType::Bar, User::factory()->create());
        $line = app(OrderLineBuilder::class)->addProduct($order, $product);

        $product->update(['name' => 'Caña grande', 'price' => 600]);

        $this->assertSame('Caña', $line->fresh()->name);
        $this->assertSame(450, $line->fresh()->unit_price);
    }

    public function test_cash_session_close_assigns_consecutive_z_and_counts_cash(): void
    {
        $this->createSettings();
        $user = User::factory()->create();
        $cash = app(CashSessionService::class);

        $session = $cash->open($user, 10000);
        $order = app(OrderService::class)->open(OrderType::Bar, $user);
        app(OrderLineBuilder::class)->addProduct($order, Product::factory()->price(1250)->create());
        $ticket = app(TicketIssuer::class)->issue($order, $user, $session);
        app(PaymentService::class)->register($ticket, PaymentMethod::Cash, 1250, $user, tendered: 2000, session: $session);

        $closed = $cash->close($session, $user, countedCash: 11200);
        $this->assertSame(1, $closed->z_number);
        $this->assertSame(11250, $closed->expected_cash);
        $this->assertSame(-50, $closed->difference);

        $this->assertSame(2, $cash->close($cash->open($user, 0), $user, 0)->z_number);
    }
}
