<?php

namespace Tests\Feature\Pos;

use App\Enums\PrintJobKind;
use App\Enums\TableStatus;
use App\Enums\UserRole;
use App\Jobs\ProcessPrintJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PrintJob;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Printing\PrinterManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class CheckoutTest extends PosTestCase
{
    public function test_cannot_charge_without_open_cash_session(): void
    {
        $order = $this->orderWith([[$this->product('Caña', 250, $this->drinks), 2]]);

        $this->checkout($order, [['method' => 'efectivo', 'amount' => 500]])
            ->assertStatus(409)->assertJsonPath('code', 'cash_session_required');
    }

    public function test_simple_cash_checkout_with_change_prints_ticket_and_opens_drawer(): void
    {
        $session = $this->openCash();
        $order = $this->orderWith([[$this->product('Caña', 250, $this->drinks), 3], [$this->product('Bravas', 550), 1]]);
        $table = Order::find($order)->table;

        $response = $this->checkout($order, [['method' => 'efectivo', 'amount' => 1300, 'tendered' => 2000]])
            ->assertCreated()
            ->assertJsonPath('data.change', 700)
            ->assertJsonPath('data.ticket.total', 1300);

        $ticket = Ticket::find($response->json('data.ticket.id'));
        $this->assertSame('pagado', $ticket->payment_status->value);
        $this->assertSame($session->id, $ticket->cash_session_id);
        $this->assertSame('cerrado', Order::find($order)->status->value);
        $this->assertSame(TableStatus::Free, $table->fresh()->status);

        $kinds = PrintJob::query()->where('ticket_id', $ticket->id)->pluck('kind')->map->value->sort()->values()->all();
        $this->assertSame(['cajon', 'ticket'], $kinds);

        $text = $this->printedText(PrintJob::query()->where('kind', PrintJobKind::Receipt)->first()->content);
        $this->assertStringContainsString('FACTURA SIMPLIFICADA', $text);
        $this->assertStringContainsString($ticket->fullNumber(), $text);
        $this->assertStringContainsString('Mesa '.$table->name, $text);
        $this->assertStringContainsString('Atendió: Ana López', $text);
        $this->assertStringContainsString('TOTAL  13,00 €', $text);
    }

    public function test_card_only_does_not_open_drawer_and_tip_is_kept_apart(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->product('Menú', 1500), 1]]);

        $ticketId = $this->checkout($order, [['method' => 'tarjeta', 'amount' => 1500, 'tip' => 200]])->assertCreated()->json('data.ticket.id');

        $ticket = Ticket::find($ticketId);
        $this->assertSame(1500, $ticket->total); // la propina no entra en el ticket
        $this->assertSame(200, $ticket->payments()->first()->tip);
        $this->assertSame(1500, array_sum(array_column($ticket->vat_breakdown, 'total')));
        $this->assertFalse(PrintJob::query()->where('kind', PrintJobKind::Drawer)->exists());
    }

    public function test_tip_only_allowed_on_card(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->product('Menú', 1500), 1]]);

        $this->checkout($order, [['method' => 'efectivo', 'amount' => 1500, 'tip' => 100]])->assertUnprocessable();
    }

    public function test_mixed_payment_must_cover_the_total_exactly(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->product('Cena', 4550), 1]]);

        $this->checkout($order, [['method' => 'tarjeta', 'amount' => 3000]])
            ->assertUnprocessable()->assertJsonPath('message', 'Falta por cobrar 15,50 €.');

        $ticketId = $this->checkout($order, [
            ['method' => 'tarjeta', 'amount' => 3000],
            ['method' => 'efectivo', 'amount' => 1550, 'tendered' => 2000],
        ])->assertCreated()->assertJsonPath('data.change', 450)->json('data.ticket.id');

        $this->assertEqualsCanonicalizing(['tarjeta', 'efectivo'], Payment::where('ticket_id', $ticketId)->pluck('method')->map->value->all());
        $this->assertTrue(PrintJob::query()->where('kind', PrintJobKind::Drawer)->exists());
    }

    public function test_equal_split_is_one_ticket_with_several_payments_and_payment_receipts(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->product('Cena', 1000), 1]]);

        // 10,00 € entre 3: 3,33 + 3,33 + 3,34 (el resto a la última parte)
        $ticketId = $this->checkout($order, [
            ['method' => 'tarjeta', 'amount' => 333],
            ['method' => 'bizum', 'amount' => 333],
            ['method' => 'efectivo', 'amount' => 334, 'tendered' => 500],
        ], extra: ['payment_receipts' => true])->assertCreated()->json('data.ticket.id');

        $this->assertSame(1, Ticket::count());
        $receipts = PrintJob::query()->where('ticket_id', $ticketId)->where('kind', PrintJobKind::PaymentReceipt)->get();
        $this->assertCount(3, $receipts);
        $text = $this->printedText($receipts[2]->content);
        $this->assertStringContainsString('NO ES UNA FACTURA', $text);
        $this->assertStringContainsString('Corresponde al ticket '.Ticket::find($ticketId)->fullNumber(), $text);
        $this->assertStringContainsString('Parte 3 de 3', $text);
    }

    public function test_same_idempotency_key_returns_the_same_ticket(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->product('Caña', 250, $this->drinks), 2]]);
        $payments = [['method' => 'tarjeta', 'amount' => 500]];

        $first = $this->checkout($order, $payments, 'intento-cobro-0001')->assertCreated()->json('data.ticket.id');
        $second = $this->checkout($order, $payments, 'intento-cobro-0001')->assertOk()->assertJsonPath('data.replayed', true)->json('data.ticket.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Ticket::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_full_invitation_gives_a_zero_ticket_without_payments(): void
    {
        $this->openCash();
        $orderId = $this->orderWith([[$this->product('Chupito', 300, $this->drinks), 1]]);
        $line = Order::find($orderId)->lines()->first();
        $this->asPos(User::factory()->role(UserRole::Manager)->withPin('8888')->create())
            ->postJson("/api/order-lines/{$line->id}/invite")->assertOk();

        $ticketId = $this->checkout($orderId, [])->assertCreated()->assertJsonPath('data.ticket.total', 0)->json('data.ticket.id');

        $this->assertSame('pagado', Ticket::find($ticketId)->payment_status->value);
        $this->assertFalse(PrintJob::query()->where('kind', PrintJobKind::Drawer)->exists());
    }

    public function test_quick_sale_opens_bar_order_without_table(): void
    {
        $this->asWaiter()->postJson('/api/quick-sale')->assertCreated()->assertJsonPath('data.type', 'barra')->assertJsonPath('data.table', null);
    }

    public function test_reprint_marks_copy_and_second_copy_needs_authorization(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->product('Caña', 250, $this->drinks), 1]]);
        $ticketId = $this->checkout($order, [['method' => 'tarjeta', 'amount' => 250]])->json('data.ticket.id');

        $this->asWaiter()->postJson("/api/tickets/{$ticketId}/reprint")->assertOk();
        $copy = PrintJob::query()->where('ticket_id', $ticketId)->whereNotNull('send_number')->first();
        $this->assertStringContainsString('*** COPIA ***', collect($copy->content)->pluck('text')->implode("\n"));

        $this->asWaiter()->postJson("/api/tickets/{$ticketId}/reprint")->assertForbidden()->assertJsonPath('code', 'authorization_required');
    }

    public function test_ticket_print_failure_uses_print_jobs_and_can_be_redirected(): void
    {
        Queue::fake();
        $this->openCash();
        $order = $this->orderWith([[$this->product('Caña', 250, $this->drinks), 1]]);
        $ticketId = $this->checkout($order, [['method' => 'tarjeta', 'amount' => 250]])->json('data.ticket.id');
        $job = PrintJob::query()->where('ticket_id', $ticketId)->where('kind', PrintJobKind::Receipt)->firstOrFail();

        (new ProcessPrintJob($job->id))->failed(new \RuntimeException('Sin papel'));

        $this->asWaiter()->getJson('/api/print-jobs/failed')->assertJsonPath('data.0.kind', 'ticket');
        $this->asWaiter()->postJson("/api/print-jobs/{$job->id}/redirect", ['printer_id' => $this->bar->id])->assertOk();
    }

    public function test_58mm_ticket_fits_32_columns(): void
    {
        $this->tickets->update(['columns' => 32]);
        $this->openCash();
        $order = $this->orderWith([[$this->product('Croquetas caseras de jamón ibérico', 750), 2]]);
        $ticketId = $this->checkout($order, [['method' => 'efectivo', 'amount' => 1500, 'tendered' => 2000]])->json('data.ticket.id');

        app(PrinterManager::class)->forPrinter($this->tickets->fresh())
            ->print(PrintJob::where('ticket_id', $ticketId)->where('kind', 'ticket')->first()->printable());
        $file = collect(Storage::disk('local')->files('prints'))->first(fn ($f) => str_ends_with($f, '.txt'));
        $lines = explode("\n", Storage::disk('local')->get($file));

        foreach ($lines as $line) {
            if (! str_contains($line, '**') && ! str_contains($line, '✂')) {
                $this->assertLessThanOrEqual(32, mb_strlen($line), "Línea demasiado larga: «{$line}»");
            }
        }
    }
}
