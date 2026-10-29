<?php

namespace Tests\Feature\Pos;

use App\Models\Authorization;
use App\Models\Order;
use App\Models\PrintJob;

class OrderCancellationTest extends TakeawayTestCase
{
    private function cancel(int $id, array $data)
    {
        return $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/cancel", $data);
    }

    public function test_cancel_before_sending_needs_only_a_reason(): void
    {
        $id = $this->deliveryWith();

        $this->cancel($id, [])->assertStatus(422);
        $this->cancel($id, ['reason' => 'El cliente llama para anularlo'])->assertOk()
            ->assertJsonPath('data.status', 'cancelado');

        $order = Order::find($id);
        $this->assertSame('El cliente llama para anularlo', $order->cancel_reason);
        $this->assertSame($this->waiter->id, $order->cancelled_by);
        $this->assertSame(0, PrintJob::query()->where('kind', 'anulacion')->count());
        $this->assertNotContains($id, collect($this->asWaiter()->getJson('/api/takeaway-orders')->json('data'))->pluck('id'));
    }

    public function test_cancel_after_kitchen_needs_manager_and_prints_void(): void
    {
        $id = $this->deliveryWith();
        $this->send($id)->assertOk();

        $this->cancel($id, ['reason' => 'No contesta'])->assertStatus(403)
            ->assertJsonPath('code', 'authorization_required')->assertJsonPath('action', 'cancelar_pedido');
        $this->assertSame('abierto', Order::find($id)->status->value);

        $this->cancel($id, ['reason' => 'No contesta', 'authorization_token' => $this->managerToken('cancelar_pedido')])->assertOk();

        $void = PrintJob::query()->where('kind', 'anulacion')->sole();
        $text = $this->printedText($void->content);
        $this->assertStringContainsString('ANULACIÓN', $text);
        $this->assertStringContainsString('Lucía Pérez', $text);
        $this->assertStringContainsString('Pedido cancelado: No contesta', $text);
        $this->assertSame(1, Authorization::query()->where('action', 'cancelar_pedido')->count());
        $this->assertSame(0, Order::find($id)->lines()->whereNull('voided_at')->count());
    }

    public function test_cannot_cancel_after_ticket(): void
    {
        $this->openCash();
        $id = $this->deliveryWith();
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id])->assertOk();

        $this->cancel($id, ['reason' => 'Tarde'])->assertStatus(422)
            ->assertJsonPath('message', 'El pedido ya tiene ticket: para anularlo, haz un rectificativo.');
    }
}
