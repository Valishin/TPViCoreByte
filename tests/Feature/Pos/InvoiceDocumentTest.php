<?php

namespace Tests\Feature\Pos;

use App\Models\PrintJob;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Billing\InvoicePdf;
use App\Services\Pos\ReceiptFormatter;
use App\Services\Tickets\RectificationService;
use Smalot\PdfParser\Parser;

class InvoiceDocumentTest extends InvoiceTestCase
{
    private function invoice(): Ticket
    {
        Setting::current()->update(['legal_name' => 'Pizzeria Bella Napoli SL', 'tax_id' => 'B12345674', 'address' => 'Rambla del Portalet, 12',
            'postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols', 'province' => 'Girona']);
        $order = $this->orderWith([[$this->pizza, 2], [$this->beer, 1]]);
        $this->invoiceCheckout($order, 2250)->assertCreated();

        return Ticket::query()->where('order_id', $order)->sole();
    }

    private function pdfText(Ticket $ticket): string
    {
        $pdf = app(InvoicePdf::class)->render($ticket);
        $this->assertStringStartsWith('%PDF', $pdf);

        return preg_replace('/\s+/u', ' ', (new Parser)->parseContent($pdf)->getText());
    }

    /** Art. 6.1 RD 1619/2012: a) serie y número, b) fecha, c-e) emisor y destinatario, f) descripción y precio unitario sin IVA, g) tipo, h) cuota, base por tipo y total. */
    public function test_pdf_contains_every_mandatory_field(): void
    {
        $this->openCash();
        $text = $this->pdfText($this->invoice());

        foreach ([
            'FACTURA', 'F-000001', 'Fecha de expedición: '.now()->format('d/m/Y'),                       // a, b
            'Pizzeria Bella Napoli SL', 'NIF B12345674', 'Rambla del Portalet, 12', '17220 Sant Feliu de Guíxols (Girona)', // emisor
            'Restaurants del Port SL', 'Passeig dels Guíxols, 5',                                            // destinatario
            'Margarita', 'Precio unitario sin IVA', '9,0909 €', 'Cerveza', '2,2727 €',                         // f
            '10 %', 'Base imponible', '20,45 €', 'Cuota', '2,05 €', 'TOTAL', '22,50 €',                       // g, h, total
        ] as $expected) {
            $this->assertStringContainsString($expected, $text, "Falta «{$expected}» en el PDF");
        }
    }

    public function test_pdf_of_exchange_and_rectifying_invoice_reference_the_original(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->water, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => 150]]);
        $ticket = Ticket::query()->where('order_id', $order)->sole();
        $this->travel(2)->days();
        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/exchange", ['customer_id' => $this->company->id])->assertCreated();
        $exchange = Ticket::query()->where('type', 'completa_canje')->sole();

        $text = $this->pdfText($exchange);
        $this->assertStringContainsString('Sustituye a la factura simplificada '.$ticket->fullNumber(), $text);
        $this->assertStringContainsString('Fecha de la operación: '.$ticket->issued_at->format('d/m/Y'), $text); // art. 6.1.i
        $this->assertSame(1, PrintJob::query()->where('ticket_id', $exchange->id)->count()); // impresa

        $rect = app(RectificationService::class)->byDifferences($exchange, $this->waiter, 'Devolución');
        $text = $this->pdfText($rect);
        $this->assertStringContainsString('FACTURA RECTIFICATIVA', $text);
        $this->assertStringContainsString('Rectifica a la factura F-000001', $text);
        $this->assertStringContainsString('Motivo: Devolución', $text);
    }

    public function test_thermal_invoice_at_80_and_58_mm(): void
    {
        $this->openCash();
        $invoice = $this->invoice();
        $formatter = app(ReceiptFormatter::class);

        foreach ([48, 32] as $columns) {
            $text = $this->printedText($formatter->ticket($invoice, $columns)->operations());
            foreach (['FACTURA', 'Nº F-000001', 'DESTINATARIO', 'Restaurants del Port SL', 'NIF B12345674',
                'P.u. sin IVA 9,0909 €', 'IVA 10 %', 'Base imponible', 'Cuota IVA', 'TOTAL  22,50 €'] as $expected) {
                $this->assertStringContainsString($expected, $text, "{$columns} columnas: falta «{$expected}»");
            }
            $this->assertStringNotContainsString('FACTURA SIMPLIFICADA', $text);
        }
        // El cobro con factura imprime la factura (no el ticket).
        $job = PrintJob::query()->where('ticket_id', $invoice->id)->where('kind', 'ticket')->sole();
        $this->assertStringContainsString('DESTINATARIO', $this->printedText($job->content));
    }

    public function test_pdf_download_from_the_cash_screen(): void
    {
        $this->openCash();
        $invoice = $this->invoice();

        $this->asWaiter()->get("/api/tickets/{$invoice->id}/pdf")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="Factura-F-000001.pdf"');
    }
}
