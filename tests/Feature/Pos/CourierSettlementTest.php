<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Filament\Resources\CashSessions\Pages\ViewCashSession;
use App\Filament\Resources\Tickets\Pages\ListTickets;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketAnnulment;
use App\Models\User;
use App\Services\Cash\CashReportService;
use App\Support\Format;
use Filament\Facades\Filament;
use Livewire\Livewire;

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

    /** «Cambio para 50 €»: el cambio sale de la caja con el repartidor y al liquidar entrega los 50 €. */
    public function test_change_taken_from_the_till_is_handed_back_at_settlement(): void
    {
        $session = $this->openCash(10000);
        $id = $this->deliveryWith(null, ['payment' => 'efectivo', 'cash_change_for' => 5000]);   // 21,50 €
        $this->send($id)->assertOk();
        $this->assertSame(2850, $this->asWaiter()->getJson("/api/orders/{$id}")->json('data.takeaway.change_needed'));

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id, 'change_from_till' => true])->assertOk();

        $movement = CashMovement::query()->where('order_id', $id)->sole();
        $this->assertSame(-2850, $movement->amount);
        $this->assertSame($this->javi->id, $movement->courier_id);
        $javi = collect($this->asWaiter()->getJson('/api/couriers/settlement')->json('data'))->firstWhere('courier.name', 'Javi');
        $this->assertSame(5000, $javi['expected_cash'], 'Al liquidar entrega los 50 € (21,50 + 28,50 de cambio).');
        $this->assertSame(2850, $javi['orders'][0]['change_from_till']);

        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/settle", ['collected' => [], 'handed_cash' => 5000])
            ->assertCreated()->assertJsonPath('settlement.difference', 0);
        // En la caja: entran los 21,50 de la venta (el cambio sale y vuelve).
        $this->assertSame(10000 + 2150, app(CashReportService::class)->build($session->refresh())['cash']['expected']);
    }

    /** Al dar salida se ve quién lleva fondo: con fondo, el cambio se da de él (casilla desmarcada). */
    public function test_couriers_list_says_who_already_carries_a_float(): void
    {
        $this->openCash();
        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/float", ['amount' => 3000])->assertCreated();

        $couriers = collect($this->asWaiter()->getJson('/api/couriers')->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(3000, $couriers['Javi']['float']);
        $this->assertSame(0, $couriers['Sara']['float']);
    }

    /** Entregar fondo abre el cajón (casilla marcada por defecto); desmarcada, no. */
    public function test_giving_a_float_opens_the_drawer_unless_unchecked(): void
    {
        $this->openCash();
        $drawers = fn () => PrintJob::query()->where('kind', 'cajon')->count();

        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/float", ['amount' => 2000])->assertCreated();
        $this->assertSame(1, $drawers());
        $this->asWaiter()->postJson("/api/couriers/{$this->sara->id}/float", ['amount' => 2000, 'open_drawer' => false])->assertCreated();
        $this->assertSame(1, $drawers());
    }

    public function test_without_change_from_the_till_nothing_changes(): void
    {
        $this->openCash();
        $id = $this->deliveryWith(null, ['payment' => 'efectivo', 'cash_change_for' => 5000]);
        $this->send($id)->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id])->assertOk();
        $card = $this->deliveryWith(null, ['payment' => 'tarjeta']);
        $this->send($card)->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$card}/dispatch", ['courier_id' => $this->javi->id, 'change_from_till' => true])->assertOk();

        $this->assertSame(0, CashMovement::query()->count(), 'Sin la casilla, o con tarjeta, no sale nada del cajón.');
        $javi = collect($this->asWaiter()->getJson('/api/couriers/settlement')->json('data'))->firstWhere('courier.name', 'Javi');
        $this->assertSame(2150, $javi['expected_cash']);
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

    /** /admin decía «Pendiente de cobro» de un domicilio no entregado (anulado): ni se cobra ni es venta. */
    public function test_admin_shows_the_not_delivered_ticket_as_annulled_not_pending(): void
    {
        $session = $this->openCash();
        $failed = $this->outForDelivery($this->javi);
        $pending = $this->outForDelivery($this->sara);   // este sí sigue pendiente
        $this->asWaiter()->postJson("/api/takeaway-orders/{$failed}/not-delivered", ['reason' => 'No está en casa', 'authorization_token' => $this->managerToken('no_entregado')])->assertOk();
        $annulled = $this->ticketOf($failed);
        $open = $this->ticketOf($pending);

        $this->assertSame('Anulado: no se cobra', $annulled->collectionLabel());
        $this->assertSame([$open->id], Ticket::query()->pendingCollection()->pluck('id')->all());
        $this->assertSame([$open->id], Ticket::query()->countsAsSale()->pluck('id')->all());

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
        Livewire::test(ListTickets::class)
            ->assertSee('Anulado: no se cobra')
            ->filterTable('payment_status', 'pendiente')
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$annulled])
            ->filterTable('payment_status', 'anulado')
            ->assertCanSeeTableRecords([$annulled])->assertCanNotSeeTableRecords([$open]);
        Livewire::test(ViewTicket::class, ['record' => $annulled->getRouteKey()])
            ->assertSee('Anulado: no se cobra')->assertDontSee('Pendiente de cobro');
        Livewire::test(ViewCashSession::class, ['record' => $session->getRouteKey()])
            ->assertSee(Format::money($open->total))   // pendiente y vendido: solo el que no se anuló
            ->assertDontSee(Format::money($open->total + $annulled->total));
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
