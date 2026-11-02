<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAnnulment;
use App\Models\User;
use App\Services\Cash\CashReportService;

class CourierSettlementTest extends TakeawayTestCase
{
    private function outForDelivery(User $courier, ?array $products = null, array $data = []): int
    {
        $id = $this->deliveryWith($products, $data);
        $this->send($id)->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $courier->id])->assertOk();

        return $id;
    }

    private function ticketOf(int $orderId): Ticket
    {
        return Ticket::query()->where('order_id', $orderId)->where('type', 'simplificada')->sole();
    }

    public function test_overview_shows_orders_float_and_expected_cash(): void
    {
        $this->openCash();
        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/float", ['amount' => 2000])->assertCreated()->assertJsonPath('float', 2000);
        $cash = $this->outForDelivery($this->javi);                                     // 21,50 € en efectivo
        $card = $this->outForDelivery($this->javi, null, ['payment' => 'tarjeta']);     // 21,50 € con tarjeta
        $this->outForDelivery($this->javi, null, ['payment' => 'pagado:online']);       // ya pagado

        $javi = collect($this->asWaiter()->getJson('/api/couriers/settlement')->assertOk()->json('data'))->firstWhere('courier.name', 'Javi');
        $this->assertSame(2000, $javi['float']);
        $this->assertSame(2150 + 2000, $javi['expected_cash']);
        $this->assertSame(2150, $javi['expected_other']);
        $this->assertCount(3, $javi['orders']);
        $this->assertSame([2150, 2150, 0], collect($javi['orders'])->pluck('pending_amount')->all());
        $this->assertEqualsCanonicalizing([$cash, $card], collect($javi['orders'])->where('pending_amount', '>', 0)->pluck('id')->all());
    }

    public function test_settle_charges_each_ticket_with_its_real_method_into_the_open_cash(): void
    {
        $session = $this->openCash(5000);
        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/float", ['amount' => 2000]);
        $a = $this->outForDelivery($this->javi);
        $b = $this->outForDelivery($this->javi);
        $c = $this->outForDelivery($this->javi, null, ['payment' => 'tarjeta']);
        $saraOrder = $this->outForDelivery($this->sara);

        // A en efectivo como estaba previsto; B al final con tarjeta; C mixto.
        $response = $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/settle", [
            'collected' => [
                $b => [['method' => 'tarjeta', 'amount' => 2150]],
                $c => [['method' => 'efectivo', 'amount' => 1000], ['method' => 'tarjeta', 'amount' => 1150]],
            ],
            'handed_cash' => 2150 + 1000 + 2000 - 50, // faltan 50 céntimos
        ])->assertCreated();

        $response->assertJsonPath('settlement.cash', 3150)
            ->assertJsonPath('settlement.card', 3300)
            ->assertJsonPath('settlement.float', 2000)
            ->assertJsonPath('settlement.expected_cash', 5150)
            ->assertJsonPath('settlement.difference', -50);

        foreach ([$a, $b, $c] as $id) {
            $this->assertSame('pagado', $this->ticketOf($id)->payment_status->value);
            $order = Order::find($id);
            $this->assertSame('entregado', $order->delivery_status->value);
            $this->assertSame($this->waiter->id, $order->delivered_by); // lo marcó la caja al liquidar
            $this->assertNotNull($order->courier_settlement_id);
        }
        $this->assertSame('pendiente', $this->ticketOf($saraOrder)->payment_status->value);
        $this->assertSame(['tarjeta'], $this->ticketOf($b)->payments()->pluck('method')->map->value->all());
        $this->assertTrue($this->ticketOf($a)->payments()->sole()->cash_session_id === $session->id);

        // Caja: el fondo sale y vuelve (no es venta); el efectivo cobrado entra.
        $report = app(CashReportService::class)->build($session->refresh());
        $this->assertSame(5000 + 3150, $report['cash']['expected']);
        $this->assertSame(0, $report['cash']['movements']);
        $this->assertSame(['fondo_repartidor', 'devolucion_fondo'], array_column($report['cash_movements'], 'type'));
        $this->assertSame([-2000, 2000], array_column($report['cash_movements'], 'amount'));
        $this->assertSame(1, $report['delivery_pending']['count']); // el de Sara
        $this->assertSame('Sara', $report['delivery_pending']['tickets'][0]['courier']);
        $this->assertSame(2150, $report['delivery_pending']['total']);
        $this->assertSame(4 * 2150, $report['sales_total']); // el fondo no suma a ventas

        $this->assertSame(1, PrintJob::query()->where('kind', 'liquidacion')->count());
        $this->assertSame([], collect($this->asWaiter()->getJson('/api/couriers/settlement')->json('data'))->firstWhere('courier.name', 'Javi')['orders']);
    }

    public function test_float_is_a_cash_movement_in_the_x_report_until_returned(): void
    {
        $session = $this->openCash(10000);
        $this->asWaiter()->postJson("/api/couriers/{$this->sara->id}/float", ['amount' => 3000])->assertCreated();

        $report = app(CashReportService::class)->build($session);
        $this->assertSame(7000, $report['cash']['expected']);
        $this->assertSame(-3000, $report['cash']['movements']);
        $this->assertSame(0, $report['sales_total']);
        $this->assertSame(1, CashMovement::count());
    }

    public function test_settlement_receipt_can_be_turned_off_in_admin(): void
    {
        Setting::current()->update(['print_courier_settlement' => false]);
        $this->openCash();
        $a = $this->outForDelivery($this->javi);

        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/settle", ['collected' => [$a => [['method' => 'efectivo', 'amount' => 2150]]]])
            ->assertCreated();

        $this->assertSame('pagado', $this->ticketOf($a)->payment_status->value, 'Se liquida igual…');
        $this->assertSame(0, PrintJob::query()->where('kind', 'liquidacion')->count(), '…pero sin imprimir el comprobante.');
    }

    public function test_settlement_must_match_ticket_amount(): void
    {
        $this->openCash();
        $a = $this->outForDelivery($this->javi);

        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/settle", ['collected' => [$a => [['method' => 'efectivo', 'amount' => 2000]]]])
            ->assertStatus(422);
        $this->assertSame('pendiente', $this->ticketOf($a)->payment_status->value);
    }

    /** Sin cobrar: no hubo venta → anulación (NotDeliveredPolicy; RRSIF 11.1). */
    public function test_not_delivered_unpaid_is_annulled_and_leaves_pending(): void
    {
        $session = $this->openCash();
        $id = $this->outForDelivery($this->javi);

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/not-delivered", ['reason' => 'No está en casa'])
            ->assertStatus(403)->assertJsonPath('action', 'no_entregado');

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/not-delivered", ['reason' => 'No está en casa', 'authorization_token' => $this->managerToken('no_entregado')])
            ->assertOk()
            ->assertJsonPath('takeaway.delivery_status', 'no_entregado')
            ->assertJsonPath('outcome', 'anulacion')
            ->assertJsonPath('rectifying', null);

        $original = $this->ticketOf($id);
        $this->assertSame('anulado', $original->status->value);
        $this->assertSame(0, Ticket::query()->where('rectified_ticket_id', $original->id)->count());
        $this->assertSame('No entregado: No está en casa', TicketAnnulment::query()->where('ticket_id', $original->id)->value('reason'));
        $this->assertSame(0, $original->payments()->count()); // no se cobró: nada que devolver

        $report = app(CashReportService::class)->build($session->refresh());
        $this->assertSame(0, $report['delivery_pending']['count']);
        $this->assertSame(0, $report['sales_total']);
        $this->assertSame(10000, $report['cash']['expected']);
        $this->assertSame([], collect($this->asWaiter()->getJson('/api/couriers/settlement')->json('data'))->firstWhere('courier.name', 'Javi')['orders']);
    }

    public function test_closing_z_with_pending_delivery_warns_like_open_tables(): void
    {
        $this->openCash();
        $this->outForDelivery($this->javi);
        $manager = $this->manager ?? User::factory()->role(UserRole::Manager)->withPin('9999')->create();

        $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 10000])
            ->assertStatus(409)->assertJsonPath('code', 'open_orders')
            ->assertJsonPath('orders.0', 'Domicilio '.Ticket::query()->sole()->fullNumber().' sin cobrar (Javi) · 21,50 €');

        $this->asPos($manager)->postJson('/api/cash-session/close', ['counted_cash' => 10000, 'force' => true])->assertOk()
            ->assertJsonPath('data.report.delivery_pending.count', 1);
    }
}
