<?php

namespace Tests\Feature\Pos;

use App\Models\Ticket;
use App\Services\Cash\CashReportService;
use App\Services\Fiscal\FiscalExportService;
use App\Services\Tickets\RectificationService;
use ZipArchive;

class InvoiceReportsTest extends InvoiceTestCase
{
    /** Ticket 22,50 + factura directa 10,00 + canje del ticket + rectificativa de la factura (-10,00). */
    private function day(): array
    {
        $session = $this->openCash();
        $ticketOrder = $this->orderWith([[$this->pizza, 2], [$this->beer, 1]]);
        $this->checkout($ticketOrder, [['method' => 'efectivo', 'amount' => 2250, 'tendered' => 2250]])->assertCreated();
        $ticket = Ticket::query()->where('order_id', $ticketOrder)->sole();

        $invoiceOrder = $this->orderWith([[$this->pizza, 1]]);
        $this->invoiceCheckout($invoiceOrder, 1000)->assertCreated();
        $invoice = Ticket::query()->where('order_id', $invoiceOrder)->sole();

        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/exchange", ['customer_id' => $this->company->id])->assertCreated();
        app(RectificationService::class)->byDifferences($invoice, $this->waiter, 'Devolución');

        return [$session, $ticket, $invoice];
    }

    public function test_z_counts_direct_invoices_and_does_not_duplicate_exchanges(): void
    {
        [$session] = $this->day();

        $report = app(CashReportService::class)->build($session->refresh());

        $this->assertSame(['count' => 1, 'total' => 2250], $report['tickets']);
        $this->assertSame(['count' => 1, 'total' => 1000], $report['invoices']);
        $this->assertSame(['count' => 1, 'total' => -1000], $report['rectifications']);
        $this->assertSame(1, $report['exchanges']['count']);
        $this->assertSame(2250, $report['exchanges']['total']);
        // 22,50 + 10,00 − 10,00: el canje (22,50) no se suma otra vez.
        $this->assertSame(2250, $report['sales_total']);
        $this->assertSame(2250, collect($report['by_vat'])->sum('total'));
        $this->assertSame(2250, collect($report['by_waiter'])->sum('total'));
    }

    public function test_fiscal_export_includes_invoices_exchanges_and_rectifying(): void
    {
        [, $ticket] = $this->day();

        $path = app(FiscalExportService::class)->export(now()->subDay(), now());
        $zip = new ZipArchive;
        $zip->open($path);
        $facturas = $zip->getFromName('facturas.csv');
        $rect = $zip->getFromName('rectificativos.csv');
        $tickets = $zip->getFromName('tickets.csv');
        $readme = $zip->getFromName('LEEME.txt');
        $zip->close();

        $this->assertStringContainsString('NIF destinatario', $facturas);
        $this->assertStringContainsString('B12345674', $facturas);
        $this->assertStringContainsString(';F1;', $facturas);
        $this->assertStringContainsString(';F3;', $facturas);
        $this->assertStringContainsString($ticket->fullNumber(), $facturas);  // sustituye a
        $this->assertStringContainsString($this->docNumber('FR', 1).';FR;', $rect);
        $this->assertStringContainsString('error_descuento', $rect);
        $this->assertStringContainsString('Canjeado por factura', $tickets);
        $this->assertStringContainsString($this->docNumber('F', 2), $tickets);               // canjeado por
        $this->assertStringContainsString('facturas.csv', $readme);
    }
}
