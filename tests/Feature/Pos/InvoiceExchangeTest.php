<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Tickets\RectificationService;
use Illuminate\Support\Carbon;

class InvoiceExchangeTest extends InvoiceTestCase
{
    private function paidTicket(): Ticket
    {
        $order = $this->orderWith([[$this->pizza, 2], [$this->beer, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => 2250, 'tendered' => 2250]])->assertCreated();

        return Ticket::query()->where('order_id', $order)->sole();
    }

    private function exchange(Ticket $ticket, array $extra = [])
    {
        return $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/exchange", ['customer_id' => $this->company->id] + $extra);
    }

    public function test_exchange_issues_an_invoice_with_same_amounts_that_replaces_the_ticket(): void
    {
        $this->openCash();
        Carbon::setTestNow('2026-11-03 21:00:00');
        $ticket = $this->paidTicket();
        Carbon::setTestNow('2026-11-05 10:00:00');

        $this->exchange($ticket)->assertCreated()
            ->assertJsonPath('data.type', 'completa_canje')
            ->assertJsonPath('data.replaces', $ticket->fullNumber())
            ->assertJsonPath('data.recipient.tax_id', 'B12345674');

        $invoice = Ticket::query()->where('type', 'completa_canje')->sole();
        $this->assertSame('F', $invoice->series_code);
        $this->assertSame('F3', $invoice->verifactuType());
        $this->assertSame([$ticket->total, $ticket->tax_base, $ticket->tax_amount], [$invoice->total, $invoice->tax_base, $invoice->tax_amount]);
        $this->assertSame($ticket->vat_breakdown, $invoice->vat_breakdown);
        $this->assertSame($ticket->lines->count(), $invoice->lines->count());
        $this->assertSame('2026-11-03', $invoice->operation_date->toDateString()); // art. 6.1.i
        $this->assertSame('2026-11-05', $invoice->issued_at->toDateString());
        $this->assertSame('pagado', $invoice->payment_status->value);
        $this->assertSame(0, $invoice->payments()->count()); // el cobro sigue en el ticket
        $this->assertSame('canjeado', $ticket->refresh()->status->value);
        $this->assertSame(90909, $invoice->lines->firstWhere('name', 'Margarita')->unit_price_net);
    }

    public function test_double_exchange_is_rejected(): void
    {
        $this->openCash();
        $ticket = $this->paidTicket();
        $this->exchange($ticket)->assertCreated();

        $this->exchange($ticket)->assertStatus(422)->assertJsonPath('message', 'Este ticket ya se canjeó por la factura '.$this->docNumber('F', 1).'.');
        $this->assertSame(1, Ticket::where('type', 'completa_canje')->count());
    }

    /** FAQ 27 (casos 2 y 3): devolución de un ticket canjeado = R5 del ticket + F3 del R5 para el cliente. */
    public function test_rectifying_an_exchanged_ticket_issues_r5_and_its_exchange_invoice(): void
    {
        $this->openCash();
        $ticket = $this->paidTicket();
        $this->exchange($ticket)->assertCreated();
        $exchange = $ticket->refresh()->exchangedBy;

        $r5 = app(RectificationService::class)->byDifferences($ticket, $this->waiter, 'Devolución');
        $f3 = Ticket::query()->where('replaces_ticket_id', $r5->id)->sole();

        $this->assertSame('rectificativa', $r5->type->value);
        $this->assertSame('completa_canje', $f3->type->value);
        $this->assertSame(-$ticket->total, $f3->total);
        $this->assertSame($exchange->recipient, $f3->recipient);
        $this->assertSame('canjeado', $ticket->refresh()->status->value);           // sigue canjeado
        $this->assertTrue($ticket->exchangedBy->is($exchange));                      // la F3 original sigue vigente

        $this->expectExceptionMessage('Un ticket canjeado solo se rectifica por diferencias');
        app(RectificationService::class)->bySubstitution($ticket, $this->waiter, 'Error', []);
    }

    /** FAQ 27 (caso 1): datos del cliente mal en la F3 = F3 negativa + F3 nueva sobre el mismo ticket. */
    public function test_exchange_recipient_correction_issues_negative_and_new_exchange_invoices(): void
    {
        $this->openCash();
        $ticket = $this->paidTicket();
        $this->exchange($ticket)->assertCreated();
        $wrong = $ticket->refresh()->exchangedBy;
        $right = Customer::create(['legal_name' => 'Restaurants del Port SLU', 'tax_id' => 'A58818501', 'fiscal_address' => 'Moll 1', 'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu']);

        $new = app(RectificationService::class)->correctRecipient($wrong, $right, $this->waiter, 'Datos del cliente');
        $exchanges = $ticket->exchanges()->orderBy('id')->get();

        $this->assertCount(3, $exchanges);
        $this->assertSame([$ticket->total, -$ticket->total, $ticket->total], $exchanges->pluck('total')->all());
        $this->assertSame(['completa_canje', 'completa_canje', 'completa_canje'], $exchanges->map(fn ($t) => $t->type->value)->all());
        $this->assertSame('rectificado', $wrong->refresh()->status->value);
        $this->assertSame('A58818501', $new->recipient['tax_id']);
        $this->assertTrue($ticket->refresh()->exchangedBy->is($new));
        $this->assertSame(0, Ticket::query()->where('type', 'rectificativa_completa')->count());   // sin R1-R4
    }

    public function test_rectified_or_pending_tickets_cannot_be_exchanged(): void
    {
        $this->openCash();
        $ticket = $this->paidTicket();
        app(RectificationService::class)->byDifferences($ticket, $this->waiter, 'Devolución');
        $this->exchange($ticket->refresh())->assertStatus(422)->assertJsonPath('message', 'Este ticket tiene rectificativos: no se puede canjear.');

        $id = $this->deliveryWith();
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id])->assertOk();
        $pending = Ticket::query()->where('order_id', $id)->sole();
        $this->exchange($pending)->assertStatus(422)
            ->assertJsonPath('message', 'El ticket está pendiente de cobro: se podrá canjear cuando esté cobrado.');
    }

    public function test_ticket_from_another_year_needs_confirmation(): void
    {
        $this->openCash();
        Carbon::setTestNow('2026-12-30 22:00:00');
        $ticket = $this->paidTicket();
        Carbon::setTestNow('2027-01-08 12:00:00');

        $this->exchange($ticket)->assertStatus(409)->assertJsonPath('code', 'exchange_other_year')->assertJsonPath('year', 2026);
        $this->exchange($ticket, ['confirm_other_year' => true])->assertCreated();
    }

    public function test_search_ticket_by_number_from_the_cash_screen(): void
    {
        $this->openCash();
        $ticket = $this->paidTicket();

        $this->asWaiter()->getJson('/api/tickets?q='.$ticket->fullNumber())->assertOk()
            ->assertJsonPath('data.0.id', $ticket->id)->assertJsonPath('data.0.can_exchange', true);
        $this->asWaiter()->getJson('/api/tickets?q='.$ticket->number)->assertJsonCount(1, 'data');
        $this->asWaiter()->getJson('/api/tickets?q=nada')->assertJsonCount(0, 'data');
    }
}
