<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Table;
use App\Models\User;
use App\Services\Orders\OrderLineBuilder;
use Database\Seeders\DemoBarSeeder;
use Database\Seeders\DemoPizzeriaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_bar_dataset(): void
    {
        $this->seed(DemoBarSeeder::class);

        $this->assertSame(2, Room::count());
        $this->assertSame(12, Table::count());
        $this->assertGreaterThanOrEqual(38, Product::count());
        $this->assertSame(3, User::where('role', UserRole::Waiter)->count());
        $this->assertTrue(User::where('name', 'Ana')->first()->checkPin('1111'));
        $this->assertEqualsCanonicalizing(['barra', 'cocina', 'ticket'], Printer::pluck('type')->map->value->all());
        $this->assertTrue(Setting::current()->isOrderTypeEnabled(OrderType::Table));
        $this->assertTrue(Product::where('name', 'Calamares a la romana')->first()->allergens->isNotEmpty());
    }

    public function test_pizzeria_dataset(): void
    {
        $this->seed(DemoPizzeriaSeeder::class);

        $pizzas = Product::where('allows_half_and_half', true)->with('variants')->get();
        $this->assertGreaterThanOrEqual(10, $pizzas->count());
        $this->assertTrue($pizzas->every(fn ($p) => $p->variants->pluck('code')->sort()->values()->all() === ['familiar', 'mediana']));
        $this->assertSame(2, Product::where('type', 'menu')->count());
        $this->assertSame(3, DeliveryZone::count());
        $this->assertSame(15, Customer::count());
        $this->assertTrue(Customer::withCount('addresses')->get()->every(fn ($c) => in_array($c->addresses_count, [1, 2], true)));
        $this->assertSame(2, User::where('role', UserRole::Courier)->count());
        $this->assertFalse(Setting::current()->isOrderTypeEnabled(OrderType::Table));
    }

    /** Agua 10 % siempre; refresco azucarado y cerveza 10 % en local y 21 % para llevar. */
    public function test_pizzeria_drinks_vat_in_house_and_takeaway(): void
    {
        $this->seed(DemoPizzeriaSeeder::class);
        $by = User::where('role', UserRole::Courier)->first();
        $drinks = ['Agua 50 cl', 'Coca-Cola 33 cl', 'Cerveza 33 cl'];

        $rates = function (OrderType $type) use ($drinks, $by): array {
            $order = Order::create(['type' => $type, 'status' => OrderStatus::Open, 'opened_by' => $by->id, 'opened_at' => now()]);
            foreach ($drinks as $name) {
                app(OrderLineBuilder::class)->addProduct($order, Product::where('name', $name)->firstOrFail());
            }

            return $order->lines()->pluck('vat_rate', 'name')->map(fn ($r) => (int) $r)->all();
        };

        $this->assertSame(['Agua 50 cl' => 1000, 'Coca-Cola 33 cl' => 1000, 'Cerveza 33 cl' => 1000], $rates(OrderType::Bar));
        $this->assertSame(['Agua 50 cl' => 1000, 'Coca-Cola 33 cl' => 2100, 'Cerveza 33 cl' => 2100], $rates(OrderType::Takeaway));
        $this->assertSame(['Agua 50 cl' => 1000, 'Coca-Cola 33 cl' => 2100, 'Cerveza 33 cl' => 2100], $rates(OrderType::Delivery));
    }

    public function test_takeaway_settings_defaults(): void
    {
        $this->seed(DemoPizzeriaSeeder::class);
        $settings = Setting::current();

        $this->assertSame(15, $settings->takeaway_prep_minutes);
        $this->assertSame(35, $settings->delivery_minutes);
        $this->assertFalse($settings->print_courier_copy);
    }
}
