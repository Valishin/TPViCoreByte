<?php

namespace Tests\Feature\Pos;

use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Printing\PrinterManager;
use App\Services\Printing\Ticket as Printable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class DispatchTest extends TakeawayTestCase
{
    private function readyDelivery(array $data = []): int
    {
        $id = $this->deliveryWith(null, $data);
        $this->send($id)->assertOk();
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo'])->assertOk();

        return $id;
    }

    private function dispatchTo(int $id, int $courierId)
    {
        return $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $courierId]);
    }

    public function test_kitchen_ticket_shows_delivery_customer_and_ready_time(): void
    {
        Carbon::setTestNow('2026-11-04 21:00:00');
        Setting::current()->update(['delivery_minutes' => 40, 'takeaway_prep_minutes' => 15]);
        $id = $this->deliveryWith();
        $this->send($id);

        $text = $this->printedText(PrintJob::query()->where('order_id', $id)->where('kind', 'comanda')->sole()->content);
        $this->assertStringContainsString('DOMICILIO', $text);
        $this->assertStringContainsString('Lucía Pérez', $text);
        $this->assertStringContainsString('LISTO A LAS 21:15', $text); // 21:40 prometido − 25 min de reparto
        $this->assertStringContainsString('Lo antes posible', $text);

        $takeaway = $this->newTakeaway(['customer_name' => 'Pedro', 'promised_in' => 30])->json('data.id');
        $this->addLine($takeaway, ['product_id' => $this->pizza->id]);
        $this->send($takeaway);
        $text = $this->printedText(PrintJob::query()->where('order_id', $takeaway)->where('kind', 'comanda')->sole()->content);
        $this->assertStringContainsString('LLEVAR', $text);
        $this->assertStringContainsString('LISTO A LAS 21:30', $text);
    }

    public function test_dispatch_issues_ticket_pending_payment_and_prints_delivery_ticket(): void
    {
        $session = $this->openCash();
        $id = $this->readyDelivery(['payment' => 'efectivo', 'cash_change_for' => 5000]);

        $this->dispatchTo($id, $this->javi->id)->assertOk()
            ->assertJsonPath('takeaway.delivery_status', 'en_reparto')
            ->assertJsonPath('takeaway.courier.name', 'Javi')
            ->assertJsonPath('ticket.payment_status', 'pendiente');

        $ticket = Ticket::query()->where('order_id', $id)->sole();
        $this->assertSame(2150, $ticket->total);
        $this->assertSame($session->id, $ticket->cash_session_id);
        $this->assertSame([], $ticket->payments()->get()->all());
        $this->assertSame('cerrado', Order::find($id)->status->value);

        $job = PrintJob::query()->where('ticket_id', $ticket->id)->where('kind', 'ticket')->sole();
        $text = $this->printedText($job->content);
        foreach (['DOMICILIO', 'Calle de Atocha 12', '3º B', 'Tel. +34612345678', 'Indicaciones: Portal verde', 'Repartidor Javi',
            'CAMBIO PARA 50,00 €', 'Devolver 28,50 €', 'PENDIENTE DE COBRO', 'TOTAL A COBRAR 21,50 €', 'FACTURA SIMPLIFICADA'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
        $this->assertSame(0, PrintJob::query()->where('kind', 'copia_reparto')->count());
    }

    public function test_delivery_part_has_more_line_spacing_on_paper(): void
    {
        Storage::fake('local');
        $this->openCash();
        $id = $this->readyDelivery();
        $this->dispatchTo($id, $this->javi->id)->assertOk();

        $ticket = Ticket::query()->where('order_id', $id)->sole();
        $ops = PrintJob::query()->where('ticket_id', $ticket->id)->where('kind', 'ticket')->sole()->content;
        $spacing = array_values(array_filter($ops, fn ($op) => $op['type'] === 'spacing'));
        $this->assertSame([60, null], array_column($spacing, 'dots'), 'Más aire en la parte de reparto y luego el normal.');
        $this->assertSame('DOMICILIO', $ops[array_search($spacing[0], $ops, true) + 1]['text'] ?? null);

        // En papel: ESC 3 n (interlineado) y ESC 2 (normal).
        $result = (new PrinterManager(['mode' => 'simulated'] + config('printing')))->print((new Printable)->lineSpacing(60)->line('Calle Mayor 1')->lineSpacing());
        $bin = Storage::disk('local')->get(str_replace('.txt', '.bin', str_replace(Storage::disk('local')->path(''), '', $result['file'])));
        $this->assertStringContainsString("\x1b3".chr(60), $bin);
        $this->assertStringContainsString("\x1b2", $bin);
    }

    public function test_courier_copy_with_maps_qr_when_enabled(): void
    {
        $this->openCash();
        Setting::current()->update(['print_courier_copy' => true]);
        $id = $this->readyDelivery();
        $this->dispatchTo($id, $this->sara->id)->assertOk();

        $copy = PrintJob::query()->where('kind', 'copia_reparto')->sole()->content;
        $qr = collect($copy)->firstWhere('type', 'qr');
        $this->assertSame('https://www.google.com/maps/search/?api=1&query='.rawurlencode('Calle de Atocha 12, 28010 Madrid'), $qr['data']);
        $this->assertStringContainsString('COPIA REPARTIDOR', $this->printedText($copy));
    }

    public function test_prepaid_delivery_registers_payment_on_dispatch(): void
    {
        $session = $this->openCash();
        $id = $this->readyDelivery(['payment' => 'pagado:online']);

        $this->dispatchTo($id, $this->javi->id)->assertOk()->assertJsonPath('ticket.payment_status', 'pagado');

        $payment = Ticket::query()->where('order_id', $id)->sole()->payments()->sole();
        $this->assertSame('online', $payment->method->value);
        $this->assertSame(2150, $payment->amount);
        $this->assertSame($session->id, $payment->cash_session_id);
        $this->assertStringContainsString('PAGADO (Online)', $this->printedText(PrintJob::query()->where('kind', 'ticket')->sole()->content));
    }

    public function test_dispatch_rules(): void
    {
        $id = $this->deliveryWith();
        $this->dispatchTo($id, $this->javi->id)->assertStatus(409)->assertJsonPath('code', 'cash_session_required');

        $this->openCash();
        $this->dispatchTo($id, $this->javi->id)->assertStatus(422)->assertJsonPath('message', 'Envía la comanda a cocina antes de darle salida.');
        $this->send($id);
        $this->addLine($id, ['product_id' => $this->water->id]);
        $this->dispatchTo($id, $this->javi->id)->assertStatus(422)->assertJsonPath('message', 'Hay líneas sin enviar a cocina: envíalas antes de darle salida.');
        $this->send($id);
        $this->dispatchTo($id, $this->waiter->id)->assertStatus(422)->assertJsonPath('message', 'Elige un repartidor activo.');
        $this->dispatchTo($id, $this->javi->id)->assertOk();
        $this->dispatchTo($id, $this->javi->id)->assertStatus(422)->assertJsonPath('message', 'Este pedido ya ha salido.');
    }

    public function test_prepaid_takeaway_gets_its_ticket_when_handed_over(): void
    {
        $this->openCash();
        $id = $this->newTakeaway(['payment' => 'pagado:bizum'])->json('data.id');
        $this->addLine($id, ['product_id' => $this->pizza->id]);
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo'])->assertOk();
        $this->assertSame(0, Ticket::query()->where('order_id', $id)->count()); // aún sin ticket

        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'entregado'])->assertOk()
            ->assertJsonPath('takeaway.delivery_status', 'entregado')
            ->assertJsonPath('takeaway.ticket.payment_status', 'pagado');
        $this->assertSame('bizum', Ticket::query()->where('order_id', $id)->sole()->payments()->sole()->method->value);
    }

    public function test_couriers_list(): void
    {
        $this->asWaiter()->getJson('/api/couriers')->assertOk()->assertJsonPath('data.*.name', ['Javi', 'Sara']);
    }
}
