<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;

class MyDeliveriesTest extends TakeawayTestCase
{
    private function outFor($courier, array $data = []): int
    {
        $id = $this->deliveryWith(null, $data);
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $courier->id])->assertOk();

        return $id;
    }

    public function test_courier_sees_only_own_deliveries_with_maps_and_phone_links(): void
    {
        $this->openCash();
        $mine = $this->outFor($this->javi, ['cash_change_for' => 5000]);
        $this->outFor($this->sara);

        $data = $this->asPos($this->javi)->getJson('/api/my-deliveries')->assertOk()->json('data');
        $this->assertSame([$mine], array_column($data, 'id'));
        $this->assertSame('https://www.google.com/maps/search/?api=1&query='.rawurlencode('Calle de Atocha 12, 28010 Madrid'), $data[0]['maps_url']);
        $this->assertSame('tel:+34612345678', $data[0]['phone_url']);
        $this->assertSame(2150, $data[0]['pending_amount']);
        $this->assertSame(5000, $data[0]['cash_change_for']);
        $this->assertSame('Portal verde', $data[0]['address']['instructions']);
    }

    public function test_courier_marks_delivered_with_real_payment_and_caja_settles_with_it(): void
    {
        $session = $this->openCash();
        $id = $this->outFor($this->javi); // previsto en efectivo

        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$id}/delivered", ['payments' => [['method' => 'efectivo', 'amount' => 1000]]])
            ->assertStatus(422); // no cuadra
        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$id}/delivered", ['payments' => [['method' => 'tarjeta', 'amount' => 2150]]])
            ->assertOk()->assertJsonPath('data.delivered', true);

        $order = Order::find($id);
        $this->assertSame('entregado', $order->delivery_status->value);
        $this->assertSame($this->javi->id, $order->delivered_by); // lo marcó el repartidor
        $ticket = Ticket::query()->where('order_id', $id)->sole();
        $this->assertSame('pendiente', $ticket->payment_status->value); // el dinero se liquida en caja

        // En caja, sin indicar nada, se liquida con lo que dijo el repartidor.
        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/settle", [])->assertCreated()
            ->assertJsonPath('settlement.card', 2150)->assertJsonPath('settlement.cash', 0);
        $this->assertSame('tarjeta', $ticket->payments()->sole()->method->value);
        $this->assertSame($session->id, $ticket->payments()->sole()->cash_session_id);
    }

    public function test_courier_cannot_touch_other_deliveries(): void
    {
        $this->openCash();
        $saras = $this->outFor($this->sara);

        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$saras}/delivered", ['payments' => [['method' => 'efectivo', 'amount' => 2150]]])
            ->assertStatus(422);
        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$saras}/not-delivered", ['reason' => 'No está'])->assertForbidden();
    }

    public function test_courier_not_delivered_needs_manager_pin(): void
    {
        $this->openCash();
        $id = $this->outFor($this->javi);

        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$id}/not-delivered", ['reason' => 'Dirección errónea'])
            ->assertStatus(403)->assertJsonPath('code', 'authorization_required');

        $this->manager ??= User::factory()->role(UserRole::Manager)->withPin('9999')->create();
        $token = $this->asPos($this->javi)->postJson('/api/authorize', ['manager_id' => $this->manager->id, 'pin' => '9999', 'action' => 'no_entregado'])->json('token');
        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$id}/not-delivered", ['reason' => 'Dirección errónea', 'authorization_token' => $token])
            ->assertOk()->assertJsonPath('data.delivery_status', 'no_entregado');
    }

    public function test_overview_expects_what_the_courier_reported(): void
    {
        $this->openCash();
        $id = $this->outFor($this->javi); // previsto en efectivo
        $this->asPos($this->javi)->postJson("/api/my-deliveries/{$id}/delivered", ['payments' => [['method' => 'efectivo', 'amount' => 1000], ['method' => 'tarjeta', 'amount' => 1150]]])->assertOk();

        $javi = collect($this->asWaiter()->getJson('/api/couriers/settlement')->json('data'))->firstWhere('courier.name', 'Javi');
        $this->assertSame(1000, $javi['expected_cash']);
        $this->assertSame(1150, $javi['expected_other']);
        $this->assertSame([['method' => 'efectivo', 'amount' => 1000], ['method' => 'tarjeta', 'amount' => 1150]], $javi['orders'][0]['expected_rows']);
        $this->assertMatchesRegularExpression('/^[A-Z]+-\d{6}$/', $javi['orders'][0]['ticket']['number']); // con su formato (T-000001)
    }
}
