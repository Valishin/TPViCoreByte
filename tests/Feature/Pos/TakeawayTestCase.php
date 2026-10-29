<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Product;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** Local con reparto: zonas, un cliente con dirección, repartidores y una carta de pizzería. */
abstract class TakeawayTestCase extends PosTestCase
{
    protected DeliveryZone $centro;

    protected DeliveryZone $ensanche;

    protected Customer $lucia;

    protected CustomerAddress $luciaHome;

    protected User $javi;

    protected User $sara;

    protected Product $pizza;

    protected Product $beer;

    protected Product $water;

    protected ?User $manager = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->centro = DeliveryZone::create(['name' => 'Centro', 'delivery_fee' => 0, 'minimum_order' => 1200, 'postal_codes' => ['28001']]);
        $this->ensanche = DeliveryZone::create(['name' => 'Ensanche', 'delivery_fee' => 150, 'minimum_order' => 1500, 'postal_codes' => ['28010']]);

        $this->lucia = Customer::create(['phone' => '612345678', 'name' => 'Lucía Pérez', 'notes' => 'Timbre roto']);
        $this->luciaHome = $this->lucia->addresses()->create([
            'street' => 'Calle de Atocha', 'number' => '12', 'floor_door' => '3º B', 'postal_code' => '28010',
            'city' => 'Madrid', 'instructions' => 'Portal verde', 'delivery_zone_id' => $this->ensanche->id, 'is_default' => true,
        ]);

        $this->javi = User::factory()->role(UserRole::Courier)->withPin('4444')->create(['name' => 'Javi']);
        $this->sara = User::factory()->role(UserRole::Courier)->withPin('5555')->create(['name' => 'Sara']);

        $this->pizza = $this->product('Margarita', 1000);
        $this->beer = $this->product('Cerveza', 250, $this->drinks, ['vat_rate_takeaway' => 2100]);
        $this->water = $this->product('Agua', 150, $this->drinks);
    }

    protected function newTakeaway(array $data = []): TestResponse
    {
        return $this->asWaiter()->postJson('/api/takeaway-orders', ['type' => 'para_llevar'] + $data)->assertCreated();
    }

    protected function newDelivery(array $data = []): TestResponse
    {
        return $this->asWaiter()->postJson('/api/takeaway-orders', [
            'type' => 'domicilio', 'customer_id' => $this->lucia->id, 'address_id' => $this->luciaHome->id, 'payment' => 'efectivo',
        ] + $data)->assertCreated();
    }

    /** Pedido a domicilio con líneas (por defecto 2 pizzas: supera el mínimo del Ensanche). */
    protected function deliveryWith(?array $products = null, array $data = []): int
    {
        $id = $this->newDelivery($data)->json('data.id');
        foreach ($products ?? [[$this->pizza, 2]] as [$product, $quantity]) {
            $this->addLine($id, ['product_id' => $product->id, 'quantity' => $quantity])->assertCreated();
        }

        return $id;
    }

    protected function managerToken(string $action): string
    {
        $this->manager ??= User::factory()->role(UserRole::Manager)->withPin('9999')->create(['name' => 'Encargada']);

        return $this->asWaiter()->postJson('/api/authorize', ['manager_id' => $this->manager->id, 'pin' => '9999', 'action' => $action])
            ->assertOk()->json('token');
    }

    protected function send(int $orderId, array $extra = []): TestResponse
    {
        return $this->asWaiter()->postJson("/api/orders/{$orderId}/send", $extra);
    }
}
