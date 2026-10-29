<?php

namespace Tests\Feature\Pos;

use App\Enums\PrinterType;
use App\Enums\ProductType;
use App\Enums\UserRole;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Printer;
use App\Models\Product;
use App\Models\Table;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Local de prueba: impresoras de cocina, barra y tickets; carta con tapas
 * (cocina) y bebidas (barra), pizzas con tamaños y un menú.
 */
abstract class PosTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $waiter;

    protected Printer $kitchen;

    protected Printer $bar;

    protected Printer $tickets;

    protected Category $food;

    protected Category $drinks;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['printing.mode' => 'simulated']);

        $this->createSettings();
        $this->kitchen = Printer::factory()->create(['name' => 'Cocina', 'type' => PrinterType::Kitchen]);
        $this->bar = Printer::factory()->create(['name' => 'Barra', 'type' => PrinterType::Bar]);
        $this->tickets = Printer::factory()->create(['name' => 'Caja', 'type' => PrinterType::Ticket]);
        $this->food = Category::factory()->create(['name' => 'Comida', 'printer_id' => $this->kitchen->id]);
        $this->drinks = Category::factory()->create(['name' => 'Bebida', 'printer_id' => $this->bar->id]);

        $this->waiter = User::factory()->role(UserRole::Waiter)->withPin('1234')->create(['name' => 'Ana López']);
    }

    protected function asWaiter(?User $user = null): static
    {
        return $this->asPos($user ?? $this->waiter);
    }

    /** Cambia de usuario del TPV (el guard de Sanctum cachea el usuario entre peticiones del test). */
    protected function asPos(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user, 'pos');
    }

    /** Texto de un contenido de impresión (líneas y columnas). */
    protected function printedText(array $content): string
    {
        return collect($content)->map(fn ($op) => $op['type'] === 'columns' ? $op['left'].' '.$op['right'] : ($op['text'] ?? ''))->filter()->implode("\n");
    }

    protected function product(string $name, int $price, ?Category $category = null, array $attributes = []): Product
    {
        return Product::factory()->price($price)->create(['name' => $name, 'category_id' => ($category ?? $this->food)->id] + $attributes);
    }

    protected function pizza(string $name, int $medium, int $family): Product
    {
        return Product::factory()->pizza($medium, $family)->create(['name' => $name, 'category_id' => $this->food->id]);
    }

    protected function openTable(?Table $table = null, int $guests = 2): array
    {
        $table ??= Table::factory()->create(['name' => '5']);

        return $this->asWaiter()->postJson("/api/tables/{$table->id}/open", ['guests' => $guests])->assertCreated()->json('data');
    }

    protected function addLine(int $orderId, array $payload): TestResponse
    {
        return $this->asWaiter()->postJson("/api/orders/{$orderId}/lines", $payload);
    }

    protected function openCash(int $float = 10000): CashSession
    {
        $manager = User::factory()->role(UserRole::Manager)->withPin('9999')->create();

        return app(CashSessionService::class)->open($manager, $float);
    }

    /** Mesa abierta con líneas ya calculadas; devuelve el id del pedido. */
    protected function orderWith(array $products): int
    {
        $order = $this->openTable(Table::factory()->create());
        foreach ($products as [$product, $quantity]) {
            $this->addLine($order['id'], ['product_id' => $product->id, 'quantity' => $quantity])->assertCreated();
        }

        return $order['id'];
    }

    protected function checkout(int $orderId, array $payments, ?string $key = null, array $extra = []): TestResponse
    {
        return $this->asWaiter()->postJson("/api/orders/{$orderId}/checkout", ['idempotency_key' => $key ?? (string) Str::uuid(), 'payments' => $payments] + $extra);
    }

    protected function menu(Product ...$components): Product
    {
        $menu = Product::factory()->menu(1200)->create(['name' => 'Menú del día', 'category_id' => $this->food->id]);
        foreach ($components as $i => $component) {
            $group = $menu->menuGroups()->create(['name' => "Grupo {$i}", 'min_select' => 1, 'max_select' => 1, 'sort_order' => $i]);
            $group->items()->create(['product_id' => $component->id, 'product_variant_id' => $component->type === ProductType::Variants ? $component->variants()->first()->id : null]);
        }

        return $menu->load('menuGroups.items');
    }
}
