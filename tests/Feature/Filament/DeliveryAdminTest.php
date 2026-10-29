<?php

namespace Tests\Feature\Filament;

use App\Enums\CashMovementType;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\CashMovement;
use App\Models\CourierSettlement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashSessionService;

class DeliveryAdminTest extends FilamentTestCase
{
    public function test_order_and_cash_session_pages_show_delivery_data(): void
    {
        $courier = User::factory()->role(UserRole::Courier)->withPin('4444')->create(['name' => 'Javi']);
        $session = app(CashSessionService::class)->open($this->admin, 10000);
        $settlement = CourierSettlement::create([
            'courier_id' => $courier->id, 'cash_session_id' => $session->id, 'settled_by' => $this->admin->id,
            'float_amount' => 2000, 'cash_collected' => 2150, 'expected_cash' => 4150, 'handed_cash' => 4100, 'difference' => -50,
        ]);
        CashMovement::create(['cash_session_id' => $session->id, 'type' => CashMovementType::CourierFloat, 'amount' => -2000,
            'courier_id' => $courier->id, 'courier_settlement_id' => $settlement->id, 'user_id' => $this->admin->id]);

        $order = Order::create([
            'type' => OrderType::Delivery, 'status' => OrderStatus::Closed, 'opened_by' => $this->admin->id, 'opened_at' => now(),
            'customer_name' => 'Lucía', 'delivery_address' => ['street' => 'Calle de Atocha', 'number' => '12'],
            'promised_at' => now()->setTime(21, 30), 'asap' => true, 'courier_id' => $courier->id,
            'delivery_status' => DeliveryStatus::Delivered, 'delivered_at' => now(), 'delivered_by' => $courier->id,
            'planned_payment_method' => PaymentMethod::Cash, 'courier_settlement_id' => $settlement->id,
        ]);

        $this->get("/admin/pedidos/{$order->id}")->assertOk()
            ->assertSee('Lo antes posible (21:30)')->assertSee('Javi');
        $this->get("/admin/cierres-caja/{$session->id}")->assertOk()
            ->assertSee('Liquidaciones')->assertSee('Fondo entregado a repartidor');
    }
}
