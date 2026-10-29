<?php

namespace Tests\Feature\Pos;

use App\Events\Pos\TakeawayOrderChanged;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

class TakeawayOrderTest extends TakeawayTestCase
{
    public function test_takeaway_asap_uses_preparation_time_and_optional_contact(): void
    {
        Carbon::setTestNow('2026-11-04 21:10:30');
        Setting::current()->update(['takeaway_prep_minutes' => 20]);

        $this->newTakeaway(['customer_name' => 'Pedro', 'customer_phone' => '600 11 22 33', 'payment' => 'tarjeta'])
            ->assertJsonPath('takeaway.delivery_status', 'pendiente')
            ->assertJsonPath('takeaway.asap', true)
            ->assertJsonPath('takeaway.promised_at', Carbon::parse('2026-11-04 21:30:00')->toIso8601String())
            ->assertJsonPath('takeaway.customer_name', 'Pedro')
            ->assertJsonPath('takeaway.customer_phone', '+34600112233')
            ->assertJsonPath('takeaway.planned_payment_method', 'tarjeta');

        // Sin datos de contacto también vale.
        $this->newTakeaway()->assertJsonPath('takeaway.customer_name', null);
    }

    public function test_takeaway_with_known_phone_attaches_the_customer(): void
    {
        $this->newTakeaway(['customer_phone' => '+34 612 345 678'])
            ->assertJsonPath('takeaway.customer_id', $this->lucia->id)
            ->assertJsonPath('takeaway.customer_name', 'Lucía Pérez')
            ->assertJsonPath('takeaway.customer_notes', 'Timbre roto');
    }

    public function test_quick_times_set_a_fixed_promise_and_clear_asap(): void
    {
        Carbon::setTestNow('2026-11-04 20:00:00');
        $id = $this->newTakeaway()->json('data.id');

        $this->asWaiter()->patchJson("/api/takeaway-orders/{$id}", ['promised_in' => 45])->assertOk()
            ->assertJsonPath('takeaway.asap', false)
            ->assertJsonPath('takeaway.promised_at', Carbon::parse('2026-11-04 20:45:00')->toIso8601String());
    }

    public function test_delivery_snapshots_customer_and_applies_zone_fee_with_its_vat(): void
    {
        Carbon::setTestNow('2026-11-04 21:00:00');
        Setting::current()->update(['delivery_minutes' => 40, 'delivery_fee_vat_rate' => 2100]);
        $id = $this->deliveryWith([[$this->pizza, 1]]);

        $order = Order::findOrFail($id);
        $this->assertSame('Calle de Atocha', $order->delivery_address['street']);
        $this->assertSame('Lucía Pérez', $order->customer_name);
        $this->assertSame(150, $order->delivery_fee);
        $this->assertSame(1150, $order->total);
        $this->assertTrue($order->promised_at->eq(Carbon::parse('2026-11-04 21:40:00')));

        $vat = collect($this->asWaiter()->getJson("/api/orders/{$id}")->json('data.vat_breakdown'))->keyBy('rate');
        $this->assertSame(1000, $vat[1000]['total']); // pizza al 10 %
        $this->assertSame(150, $vat[2100]['total']);  // gastos de envío al IVA configurado
    }

    public function test_delivery_needs_customer_and_address(): void
    {
        $this->asWaiter()->postJson('/api/takeaway-orders', ['type' => 'domicilio'])
            ->assertStatus(422)->assertJsonPath('message', 'Un pedido a domicilio necesita cliente y dirección.');
    }

    public function test_cash_change_only_with_cash_and_prepaid_method(): void
    {
        $id = $this->newDelivery(['payment' => 'efectivo', 'cash_change_for' => 5000])
            ->assertJsonPath('takeaway.cash_change_for', 5000)->json('data.id');

        $this->asWaiter()->patchJson("/api/takeaway-orders/{$id}", ['payment' => 'tarjeta'])
            ->assertJsonPath('takeaway.cash_change_for', null);
        $this->asWaiter()->patchJson("/api/takeaway-orders/{$id}", ['payment' => 'pagado:online'])
            ->assertJsonPath('takeaway.prepaid_method', 'online')
            ->assertJsonPath('takeaway.planned_payment_method', null);
        $this->asWaiter()->patchJson("/api/takeaway-orders/{$id}", ['payment' => 'pagado:efectivo'])->assertStatus(422);
    }

    public function test_takeaway_vat_alcohol_at_21_and_water_at_10(): void
    {
        $id = $this->newTakeaway()->json('data.id');
        $this->addLine($id, ['product_id' => $this->beer->id, 'quantity' => 2]);
        $this->addLine($id, ['product_id' => $this->water->id]);

        $vat = collect($this->asWaiter()->getJson("/api/orders/{$id}")->json('data.vat_breakdown'))->keyBy('rate');
        $this->assertSame(500, $vat[2100]['total']);
        $this->assertSame(150, $vat[1000]['total']);

        // La misma cerveza en mesa va al 10 %.
        $tableOrder = $this->orderWith([[$this->beer, 1]]);
        $this->assertSame([1000], collect($this->asWaiter()->getJson("/api/orders/{$tableOrder}")->json('data.vat_breakdown'))->pluck('rate')->all());
    }

    public function test_sending_moves_pending_to_kitchen_then_ready_manually(): void
    {
        Event::fake([TakeawayOrderChanged::class]);
        $id = $this->deliveryWith();

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo'])
            ->assertStatus(422)->assertJsonPath('message', 'Solo se marca listo un pedido que está en cocina.');

        $this->send($id)->assertOk();
        $this->assertSame('en_cocina', Order::find($id)->delivery_status->value);
        $this->assertNotNull(Order::find($id)->kitchen_at);

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo'])->assertOk()
            ->assertJsonPath('takeaway.delivery_status', 'listo');

        Event::assertDispatched(TakeawayOrderChanged::class, fn ($e) => $e->orderId === $id && $e->deliveryStatus === 'listo');
        Event::assertDispatched(TakeawayOrderChanged::class, fn ($e) => $e->orderId === $id && $e->deliveryStatus === 'en_cocina');
    }

    public function test_takeaway_checked_out_when_ready_is_delivered(): void
    {
        $this->openCash();
        $id = $this->newTakeaway()->json('data.id');
        $this->addLine($id, ['product_id' => $this->pizza->id]);
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo']);

        $this->checkout($id, [['method' => 'efectivo', 'amount' => 1000, 'tendered' => 2000]])->assertCreated();

        $order = Order::find($id);
        $this->assertSame('entregado', $order->delivery_status->value);
        $this->assertSame($this->waiter->id, $order->delivered_by);
    }

    public function test_takeaway_paid_in_advance_is_handed_over_later(): void
    {
        $this->openCash();
        $id = $this->newTakeaway()->json('data.id');
        $this->addLine($id, ['product_id' => $this->pizza->id]);
        $this->send($id);
        $this->checkout($id, [['method' => 'tarjeta', 'amount' => 1000]])->assertCreated();
        $this->assertSame('en_cocina', Order::find($id)->delivery_status->value);

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo'])->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'entregado'])->assertOk()
            ->assertJsonPath('takeaway.delivery_status', 'entregado');
    }

    public function test_delivery_orders_cannot_use_normal_checkout(): void
    {
        $this->openCash();
        $id = $this->deliveryWith();

        $this->checkout($id, [['method' => 'efectivo', 'amount' => 2150]])->assertStatus(422);
    }

    public function test_board_lists_active_and_todays_finished_orders(): void
    {
        $active = $this->deliveryWith();
        $old = Order::findOrFail($this->deliveryWith());
        $old->forceFill(['status' => 'cerrado', 'delivery_status' => 'entregado', 'delivered_at' => now()->subDay()])->save();
        $tableOrder = $this->orderWith([[$this->pizza, 1]]);

        $ids = collect($this->asWaiter()->getJson('/api/takeaway-orders')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active));
        $this->assertFalse($ids->contains($old->id));
        $this->assertFalse($ids->contains($tableOrder));
    }

    public function test_repeat_last_order_with_current_prices_and_skips_retired_products(): void
    {
        $this->openCash();
        $last = $this->newTakeaway(['customer_phone' => '612345678'])->json('data.id');
        $this->addLine($last, ['product_id' => $this->pizza->id, 'quantity' => 2, 'notes' => 'Bien hecha']);
        $this->addLine($last, ['product_id' => $this->beer->id]);
        $this->checkout($last, [['method' => 'efectivo', 'amount' => 2250, 'tendered' => 2250]])->assertCreated();

        $this->pizza->update(['price' => 1100]);
        $this->beer->update(['is_active' => false]);

        $lookup = $this->asWaiter()->getJson('/api/customers/lookup?phone=612345678')->json('customer.recent_orders.0');
        $this->assertSame($last, $lookup['id']);

        $id = $this->deliveryWith([]);
        $response = $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/repeat/{$last}")->assertOk()
            ->assertJsonPath('skipped', ['Cerveza']);

        $lines = collect($response->json('data.lines'));
        $this->assertSame(2, $lines->first()['quantity']);
        $this->assertSame('Bien hecha', $lines->first()['notes']);
        $this->assertSame(2200 + 150, $response->json('data.total')); // precio de hoy + envío
    }
}
