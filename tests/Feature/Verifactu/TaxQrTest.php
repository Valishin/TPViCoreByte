<?php

namespace Tests\Feature\Verifactu;

use App\Models\Customer;
use App\Models\FiscalRecord;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Billing\InvoicePdf;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\ReceiptFormatter;
use App\Services\Printing\PrinterManager;
use App\Services\Tickets\TicketIssuer;
use App\Services\Verifactu\TaxQr;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Feature\Pos\InvoiceTestCase;

/** QR tributario según la especificación técnica del QR v0.5.0 y los arts. 20 y 21 de la OM. */
class TaxQrTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL']);
    }

    private function ticket(?Customer $recipient = null): Ticket
    {
        $order = Order::query()->findOrFail($this->orderWith([[$this->pizza, 2], [$this->beer, 1]]));

        return app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession(), recipient: $recipient);
    }

    public function test_url_has_the_four_parameters_of_the_specification(): void
    {
        $this->openCash();
        $ticket = $this->ticket();

        $this->assertSame(
            'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B12345674&numserie='.$ticket->fullNumber()
                .'&fecha='.$ticket->issued_at->format('d-m-Y').'&importe=22.50',
            TaxQr::for($ticket),
        );
        $this->assertStringStartsWith('https://www2.agenciatributaria.gob.es/wlpl/TIKE-CONT/ValidarQR?', TaxQr::url(FiscalRecord::query()->firstOrFail(), 'produccion'));
        $this->assertStringNotContainsString('formato=', TaxQr::for($ticket));   // apdo. 7: nunca en el QR
    }

    public function test_url_encoding_example_of_the_specification(): void
    {
        // Apdo. 4: numserie «12345678&G33» → «12345678%26G33».
        $record = new FiscalRecord(['issuer_nif' => '89890001K', 'invoice_number' => '12345678&G33', 'issue_date' => '01-01-2024', 'amount_total' => 24140]);

        $this->assertSame('https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=89890001K&numserie=12345678%26G33&fecha=01-01-2024&importe=241.40', TaxQr::url($record, 'pruebas'));
    }

    public function test_generated_image_decodes_to_the_url_with_level_m_and_size_between_30_and_40_mm(): void
    {
        $this->openCash();
        $url = TaxQr::for($this->ticket());

        $result = (new QRCode)->readFromBlob(TaxQr::png($url));
        $this->assertSame($url, (string) $result);
        $this->assertSame(EccLevel::M, $result->eccLevel->getLevel());

        foreach ([$url, $url.'&x='.str_repeat('9', 60)] as $data) {   // también con URL más larga
            $mm = TaxQr::sizing($data)['mm'];
            $this->assertGreaterThanOrEqual(30, $mm);
            $this->assertLessThanOrEqual(40, $mm);
        }
        // En 58 mm (384 puntos útiles a 8 puntos/mm) cabe con su margen.
        $size = TaxQr::sizing($url);
        $this->assertLessThanOrEqual(384, $size['module'] * ($size['modules'] + 8));
    }

    public function test_thermal_ticket_and_invoice_start_with_the_qr_at_80_and_58_mm(): void
    {
        $this->openCash();
        foreach ([$this->ticket(), $this->ticket($this->company)] as $document) {
            foreach ([48, 32] as $columns) {
                $ops = app(ReceiptFormatter::class)->ticket($document, $columns)->operations();
                $this->assertSame('QR tributario:', $ops[0]['text']);
                $this->assertSame('tax_qr', $ops[1]['type']);
                $this->assertSame(TaxQr::for($document), $ops[1]['data']);
                $this->assertSame('VERI*FACTU', $ops[2]['text']);
            }
        }
    }

    public function test_escpos_output_contains_the_raster_image(): void
    {
        Storage::fake('local');
        $this->openCash();
        $ticket = $this->ticket();
        $manager = new PrinterManager(['mode' => 'simulated'] + config('printing'));

        $result = $manager->print(app(ReceiptFormatter::class)->ticket($ticket, 48));
        $bin = Storage::disk('local')->get(str_replace('.txt', '.bin', str_replace(Storage::disk('local')->path(''), '', $result['file'])));
        $this->assertStringContainsString("\x1d\x76\x30", $bin);   // GS v 0: imagen ráster
        $this->assertStringContainsString('[QR tributario', Storage::disk('local')->get(str_replace(Storage::disk('local')->path(''), '', $result['file'])));
    }

    public function test_a4_pdf_has_qr_and_legend_before_the_content(): void
    {
        $this->openCash();
        $invoice = $this->ticket($this->company);
        $qr = InvoicePdf::qr($invoice);

        $this->assertSame(38, $qr['code_mm']);
        $this->assertSame($qr['url'], (string) (new QRCode)->readFromBlob(base64_decode(substr($qr['src'], strlen('data:image/png;base64,')))));
        $text = preg_replace('/\s+/u', ' ', (new Parser)->parseContent(app(InvoicePdf::class)->render($invoice))->getText());
        $this->assertStringContainsString('QR tributario:', $text);
        $this->assertStringContainsString('Factura verificable en la sede electrónica de la AEAT', $text);
        $this->assertLessThan(strpos($text, 'FACTURA'), strpos($text, 'QR tributario:'));
    }

    public function test_no_qr_without_verifactu(): void
    {
        Setting::current()->update(['verifactu_enabled' => false]);
        $this->openCash();
        $ticket = $this->ticket();

        $this->assertNull(TaxQr::for($ticket));
        $this->assertNotSame('QR tributario:', app(ReceiptFormatter::class)->ticket($ticket)->operations()[0]['text'] ?? null);
    }
}
