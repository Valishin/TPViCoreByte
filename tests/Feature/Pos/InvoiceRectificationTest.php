<?php

namespace Tests\Feature\Pos;

use App\Enums\RectificationMethod;
use App\Enums\RectificationReason;
use App\Models\Customer;
use App\Models\Ticket;
use App\Services\Tickets\RectificationService;

class InvoiceRectificationTest extends InvoiceTestCase
{
    private function invoice(): Ticket
    {
        $order = $this->orderWith([[$this->pizza, 2], [$this->beer, 1]]);
        $this->invoiceCheckout($order, 2250)->assertCreated();

        return Ticket::query()->where('order_id', $order)->sole();
    }

    private function service(): RectificationService
    {
        return app(RectificationService::class);
    }

    public function test_full_cancellation_of_an_invoice_goes_to_series_fr_with_reason_code(): void
    {
        $this->openCash();
        $invoice = $this->invoice();

        $rect = $this->service()->byDifferences($invoice, $this->waiter, 'Devolución del cliente', reasonCode: RectificationReason::ErrorOrDiscount);

        $this->assertSame('FR', $rect->series_code);
        $this->assertSame(1, $rect->number);
        $this->assertSame('rectificativa_completa', $rect->type->value);
        $this->assertSame('R1', $rect->verifactuType());
        $this->assertSame(-2250, $rect->total);
        $this->assertSame($invoice->recipient, $rect->recipient);  // mismo destinatario
        $this->assertSame($invoice->id, $rect->rectified_ticket_id); // identifica la rectificada (art. 15.4)
        $this->assertSame('rectificado', $invoice->refresh()->status->value);
        $this->assertSame(-2250, $rect->payments()->sum('amount'));  // devolución por el método del cobro
        $this->assertSame('tarjeta', $rect->payments()->first()->method->value);
    }

    public function test_series_r_and_fr_are_separate(): void
    {
        $this->openCash();
        $invoice = $this->invoice();
        $order = $this->orderWith([[$this->water, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => 150]]);
        $ticket = Ticket::query()->where('order_id', $order)->sole();

        $r = $this->service()->byDifferences($ticket, $this->waiter, 'Error');
        $fr = $this->service()->byDifferences($invoice, $this->waiter, 'Error');

        $this->assertNotSame('FR', $r->series_code);
        $this->assertSame([1, 'rectificativa', 'R5'], [$r->number, $r->type->value, $r->verifactuType()]);
        $this->assertSame(['FR', 1, 'rectificativa_completa'], [$fr->series_code, $fr->number, $fr->type->value]);
    }

    public function test_partial_rectification_by_differences_and_substitution(): void
    {
        $this->openCash();
        $invoice = $this->invoice();
        $beerLine = $invoice->lines->firstWhere('name', 'Cerveza');

        $partial = $this->service()->byDifferences($invoice, $this->waiter, 'Cerveza no servida', [$beerLine->id => 1], reasonCode: RectificationReason::ErrorOrDiscount);
        $this->assertSame(-250, $partial->total);
        $this->assertSame(0, $this->service()->remaining($invoice->refresh())[$beerLine->id]);

        $other = $this->invoice();
        $pizzaLine = $other->lines->firstWhere('name', 'Margarita');
        $subst = $this->service()->bySubstitution($other, $this->waiter, 'Una pizza menos', [$pizzaLine->id => 1], reasonCode: RectificationReason::Other);
        $this->assertSame(1250, $subst->total);   // factura corregida
        $this->assertSame('R4', $subst->verifactuType());
        $this->assertSame(-1000, $subst->payments()->sum('amount'));
    }

    public function test_recipient_correction_by_substitution_keeps_amounts(): void
    {
        $this->openCash();
        $invoice = $this->invoice();
        $right = Customer::create(['legal_name' => 'Port Nou SL', 'tax_id' => 'A28015865', 'fiscal_address' => 'Carrer Major, 1',
            'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu de Guíxols']);

        $rect = $this->service()->correctRecipient($invoice, $right, $this->waiter, 'NIF equivocado');

        $this->assertSame(RectificationMethod::Substitution, $rect->rectification_method);
        $this->assertSame($invoice->total, $rect->total);
        $this->assertSame('A28015865', $rect->recipient['tax_id']);
        $this->assertSame('datos_destinatario', $rect->rectification_reason_code);
        $this->assertSame(0, $rect->payments()->count()); // mismos importes: nada que devolver
    }

    public function test_exchange_invoice_can_be_rectified_with_refund_from_ticket_payment(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->pizza, 1]]);
        $this->checkout($order, [['method' => 'bizum', 'amount' => 1000]])->assertCreated();
        $ticket = Ticket::query()->where('order_id', $order)->sole();
        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/exchange", ['customer_id' => $this->company->id])->assertCreated();
        $exchange = Ticket::query()->where('type', 'completa_canje')->sole();

        $this->asWaiter()->postJson("/api/tickets/{$exchange->id}/rectify", ['reason' => 'Devolución', 'reason_code' => 'error_descuento'])
            ->assertStatus(403); // camarero: autorización de encargado, como con los tickets
        $rect = $this->service()->byDifferences($exchange, $this->waiter, 'Devolución');

        $this->assertSame('FR', $rect->series_code);
        $this->assertSame('bizum', $rect->payments()->first()->method->value);
    }

    public function test_a_rectifying_invoice_cannot_be_rectified(): void
    {
        $this->openCash();
        $rect = $this->service()->byDifferences($this->invoice(), $this->waiter, 'Error');

        $this->expectExceptionMessage('Solo se pueden rectificar tickets y facturas (no otro rectificativo).');
        $this->service()->byDifferences($rect, $this->waiter, 'Otra vez');
    }
}
