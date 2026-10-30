<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\Ticket;
use App\Support\InvoiceRules;

class DirectInvoiceTest extends InvoiceTestCase
{
    public function test_checkout_with_recipient_issues_a_full_invoice_in_series_f(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->pizza, 2], [$this->beer, 1]]); // 20,00 € al 10 % + 2,50 € al 10 % en mesa

        $this->invoiceCheckout($order, 2250)->assertCreated()
            ->assertJsonPath('data.ticket.type', 'completa');

        $invoice = Ticket::query()->sole();
        $this->assertSame('F', $invoice->series_code);
        $this->assertSame(1, $invoice->number);
        $this->assertSame('F1', $invoice->verifactuType());
        $this->assertSame('Restaurants del Port SL', $invoice->recipient['name']);
        $this->assertSame('B12345674', $invoice->recipient['tax_id']);
        $this->assertSame('Passeig dels Guíxols, 5', $invoice->recipient['address']);
        $this->assertSame($this->company->id, $invoice->customer_id);
        $this->assertSame(2250, $invoice->total);
        // Precio unitario sin IVA con 4 decimales (art. 6.1.f): 10,00 € al 10 % → 9,0909 €
        $pizza = $invoice->lines->firstWhere('name', 'Margarita');
        $this->assertSame(90909, $pizza->unit_price_net);
    }

    public function test_numbering_is_correlative_per_series(): void
    {
        $this->openCash();
        foreach ([true, false, true, false, true] as $invoice) {
            $order = $this->orderWith([[$this->water, 1]]);
            $invoice ? $this->invoiceCheckout($order, 150) : $this->checkout($order, [['method' => 'efectivo', 'amount' => 150]]);
        }

        $this->assertSame([1, 2, 3], Ticket::where('series_code', 'F')->orderBy('id')->pluck('number')->all());
        $this->assertSame([1, 2], Ticket::where('series_code', '!=', 'F')->orderBy('id')->pluck('number')->all());
    }

    public function test_tickets_over_3000_euros_are_refused_and_invoice_required(): void
    {
        $this->openCash();
        $expensive = $this->product('Banquete', 150000);

        $exact = $this->orderWith([[$expensive, 2]]); // 3.000,00 €: aún vale ticket
        $this->checkout($exact, [['method' => 'tarjeta', 'amount' => 300000]])->assertCreated();

        $over = $this->orderWith([[$expensive, 2], [$this->water, 1]]); // 3.001,50 €
        $this->checkout($over, [['method' => 'tarjeta', 'amount' => 300150]])->assertStatus(409)
            ->assertJsonPath('code', 'invoice_required')
            ->assertJsonPath('total', 300150);
        $this->assertSame(1, Ticket::count());

        $this->invoiceCheckout($over, 300150)->assertCreated()->assertJsonPath('data.ticket.type', 'completa');
        $this->assertSame(300000, InvoiceRules::SIMPLIFIED_LIMIT);
    }

    public function test_recipient_without_fiscal_data_is_refused(): void
    {
        $this->openCash();
        $incomplete = Customer::create(['phone' => '600000000', 'name' => 'Sin datos']);
        $order = $this->orderWith([[$this->water, 1]]);

        $this->checkout($order, [['method' => 'efectivo', 'amount' => 150]], null, ['invoice_customer_id' => $incomplete->id])
            ->assertStatus(422)->assertJsonPath('message', 'Faltan datos fiscales del destinatario (nombre o razón social, NIF y domicilio).');
    }

    public function test_delivery_can_be_invoiced_on_dispatch_and_limit_applies(): void
    {
        $this->openCash();
        $id = $this->deliveryWith();
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id, 'invoice_customer_id' => $this->company->id])
            ->assertOk()->assertJsonPath('ticket.payment_status', 'pendiente');

        $invoice = Ticket::query()->where('order_id', $id)->sole();
        $this->assertSame('completa', $invoice->type->value);
        // Liquidación del repartidor: la factura pendiente cuenta como su documento.
        $javi = collect($this->asWaiter()->getJson('/api/couriers/settlement')->json('data'))->firstWhere('courier.name', 'Javi');
        $this->assertSame(2150, $javi['orders'][0]['pending_amount']);
        $this->asWaiter()->postJson("/api/couriers/{$this->javi->id}/settle", [])->assertCreated();
        $this->assertSame('pagado', $invoice->refresh()->payment_status->value);
    }

    public function test_prepaid_takeaway_handed_over_with_invoice(): void
    {
        $this->openCash();
        $id = $this->newTakeaway(['payment' => 'pagado:tarjeta'])->json('data.id');
        $this->addLine($id, ['product_id' => $this->pizza->id]);
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'listo']);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/status", ['status' => 'entregado', 'invoice_customer_id' => $this->company->id])->assertOk();

        $this->assertSame('completa', Ticket::query()->where('order_id', $id)->sole()->type->value);
    }
}
