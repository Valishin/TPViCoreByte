<?php

namespace Tests\Feature\Pos;

use App\Models\Authorization;
use App\Models\Order;

class MinimumOrderTest extends TakeawayTestCase
{
    public function test_delivery_below_zone_minimum_warns_on_send(): void
    {
        $id = $this->deliveryWith([[$this->pizza, 1]]); // 10,00 € + 1,50 € de envío; mínimo Ensanche 15,00 €

        $this->send($id)->assertStatus(409)
            ->assertJsonPath('code', 'minimum_order')
            ->assertJsonPath('zone', 'Ensanche')
            ->assertJsonPath('minimum', 1500)
            ->assertJsonPath('amount', 1000) // los gastos de envío no cuentan
            ->assertJsonPath('missing', 500)
            ->assertJsonPath('message', 'No llega al pedido mínimo de Ensanche (15,00 €): faltan 5,00 €.');

        $this->assertNull(Order::find($id)->lines()->first()->sent_at);
    }

    public function test_continue_below_minimum_needs_a_manager(): void
    {
        $id = $this->deliveryWith([[$this->pizza, 1]]);

        $this->send($id, ['accept_below_minimum' => true])->assertStatus(403)
            ->assertJsonPath('code', 'authorization_required')
            ->assertJsonPath('action', 'pedido_minimo');

        $this->send($id, ['accept_below_minimum' => true, 'authorization_token' => $this->managerToken('pedido_minimo')])->assertOk();

        $order = Order::find($id);
        $this->assertSame($this->manager->id, $order->minimum_order_authorized_by);
        $this->assertSame('en_cocina', $order->delivery_status->value);
        $auth = Authorization::query()->where('action', 'pedido_minimo')->sole();
        $this->assertSame(['zone' => 'Ensanche', 'minimum' => 1500, 'amount' => 1000], $auth->details);

        // Siguiente envío del mismo pedido: ya autorizado, no vuelve a preguntar.
        $this->addLine($id, ['product_id' => $this->water->id]);
        $this->send($id)->assertOk();
    }

    public function test_orders_over_the_minimum_and_takeaway_are_not_checked(): void
    {
        $this->send($this->deliveryWith([[$this->pizza, 2]]))->assertOk();

        $takeaway = $this->newTakeaway()->json('data.id');
        $this->addLine($takeaway, ['product_id' => $this->water->id]);
        $this->send($takeaway)->assertOk();
    }
}
