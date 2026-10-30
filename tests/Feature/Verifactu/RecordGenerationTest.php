<?php

namespace Tests\Feature\Verifactu;

use App\Enums\RectificationReason;
use App\Exceptions\VerifactuRequiredException;
use App\Models\Customer;
use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Billing\InvoiceExchanger;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\RectificationService;
use App\Services\Tickets\TicketIssuer;
use App\Services\Verifactu\ChainVerifier;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\FiscalRecordGenerator;
use App\Services\Verifactu\SubmissionXml;
use App\Services\Verifactu\Verifactu;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pos\InvoiceTestCase;
use Tests\Support\VerifactuSchema;

/** Registros de alta y anulación por tipo de documento, encadenados y válidos contra los XSD oficiales. */
class RecordGenerationTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL']);
    }

    private function recordOf(Ticket $ticket, string $type = FiscalRecord::ALTA): FiscalRecord
    {
        return FiscalRecord::query()->where('ticket_id', $ticket->id)->where('record_type', $type)->latest('id')->firstOrFail();
    }

    private function xpath(FiscalRecord $record): DOMXPath
    {
        $doc = new DOMDocument;
        $doc->loadXML($record->xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('sf', Verifactu::NS);

        return $xp;
    }

    private function value(FiscalRecord $record, string $path): ?string
    {
        $node = $this->xpath($record)->query($path)->item(0);

        return $node?->textContent;
    }

    private function assertValid(FiscalRecord ...$records): void
    {
        $xml = SubmissionXml::build($records, Setting::current());
        $this->assertSame([], VerifactuSchema::errors($xml), 'XML no válido contra SuministroLR.xsd');
    }

    private function ticket(array $products = []): Ticket
    {
        $order = $this->orderWith($products ?: [[$this->pizza, 2], [$this->beer, 1]]);

        return app(TicketIssuer::class)->issue(Order::query()->findOrFail($order), $this->waiter, CheckoutService::openSession());
    }

    public function test_simplified_ticket_f2_first_record_of_the_chain(): void
    {
        $this->openCash();
        $ticket = $this->ticket();
        $record = $this->recordOf($ticket);

        $this->assertSame('F2', $record->invoice_type);
        $this->assertTrue($record->is_first);
        $this->assertSame('S', $this->value($record, '//sf:Encadenamiento/sf:PrimerRegistro'));
        $this->assertSame($ticket->fullNumber(), $this->value($record, '//sf:IDFactura/sf:NumSerieFactura'));
        $this->assertSame('B12345674', $this->value($record, '//sf:IDFactura/sf:IDEmisorFactura'));
        $this->assertSame(0, $this->xpath($record)->query('//sf:Destinatarios')->length);  // F2 sin destinatario
        $this->assertSame('22.50', $this->value($record, '//sf:ImporteTotal'));
        $this->assertSame('01', $this->value($record, '//sf:ClaveRegimen'));
        $this->assertSame('S1', $this->value($record, '//sf:CalificacionOperacion'));
        $this->assertSame('IC', $this->value($record, '//sf:SistemaInformatico/sf:IdSistemaInformatico'));
        $this->assertSame('S', $this->value($record, '//sf:SistemaInformatico/sf:TipoUsoPosibleSoloVerifactu'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $record->generated_at);
        $this->assertSame(ChainVerifier::recompute($record), $record->hash);
        $this->assertSame(FiscalRecordState::PENDING, $record->state->status);
        $this->assertValid($record);
    }

    public function test_records_are_chained_in_generation_order(): void
    {
        $this->openCash();
        $first = $this->recordOf($this->ticket());
        $second = $this->recordOf($this->ticket());

        $this->assertSame($first->id, $second->previous_record_id);
        $this->assertSame($first->hash, $this->value($second, '//sf:RegistroAnterior/sf:Huella'));
        $this->assertSame($first->invoice_number, $this->value($second, '//sf:RegistroAnterior/sf:NumSerieFactura'));
        $this->assertSame(['count' => 2, 'problems' => []], ChainVerifier::verify());
        $this->assertValid($first, $second);
    }

    public function test_breakdown_by_vat_rate_with_delivery_fee(): void
    {
        $this->openCash();
        $id = $this->deliveryWith([[$this->pizza, 2], [$this->beer, 1]]);   // pizza 10 %, cerveza para llevar 21 %, envío 1,50 al 10 %
        $this->send($id);
        $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id])->assertOk();
        $ticket = Ticket::query()->where('order_id', $id)->sole();
        $record = $this->recordOf($ticket);

        $rates = array_map(fn ($n) => $n->textContent, iterator_to_array($this->xpath($record)->query('//sf:DetalleDesglose/sf:TipoImpositivo')));
        $this->assertSame(['10.00', '21.00'], $rates);
        $sum = 0;
        foreach ($ticket->vat_breakdown as $row) {
            $sum += $row['base'] + $row['tax'];
        }
        $this->assertSame($ticket->total, $sum);
        $this->assertSame('Venta de comida a domicilio', $this->value($record, '//sf:DescripcionOperacion'));
        $this->assertValid($record);
    }

    public function test_full_invoice_f1_with_spanish_nif_eu_vat_and_passport(): void
    {
        $this->openCash();
        $eu = Customer::create(['legal_name' => 'Restaurant Paris SARL', 'tax_id' => 'FR40303265045', 'fiscal_address' => '1 rue X', 'fiscal_postal_code' => '75001', 'fiscal_city' => 'Paris', 'fiscal_country' => 'FR']);
        $passport = Customer::create(['legal_name' => 'John Smith', 'tax_id' => 'X99887766', 'tax_id_type' => 'otro', 'fiscal_address' => '1 Main St', 'fiscal_postal_code' => '10001', 'fiscal_city' => 'New York', 'fiscal_country' => 'US']);

        $records = [];
        foreach ([$this->company, $eu, $passport] as $customer) {
            $order = Order::query()->findOrFail($this->orderWith([[$this->pizza, 1]]));
            $records[] = $this->recordOf(app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession(), recipient: $customer));
        }

        $this->assertSame('F1', $records[0]->invoice_type);
        $this->assertSame('B12345674', $this->value($records[0], '//sf:Destinatarios/sf:IDDestinatario/sf:NIF'));
        $this->assertSame('02', $this->value($records[1], '//sf:IDDestinatario/sf:IDOtro/sf:IDType'));
        $this->assertSame('FR40303265045', $this->value($records[1], '//sf:IDDestinatario/sf:IDOtro/sf:ID'));
        $this->assertNull($this->value($records[1], '//sf:IDOtro/sf:CodigoPais'));
        $this->assertSame('03', $this->value($records[2], '//sf:IDOtro/sf:IDType'));
        $this->assertSame('US', $this->value($records[2], '//sf:IDOtro/sf:CodigoPais'));
        $this->assertValid(...$records);
    }

    public function test_exchange_f3_lists_the_replaced_ticket_and_operation_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 13:00', 'Europe/Madrid'));
        Setting::current()->update(['verifactu_started_at' => now()->subDay()]);
        $this->openCash();
        $order = $this->orderWith([[$this->pizza, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => 1000, 'tendered' => 1000]])->assertCreated();
        $ticket = Ticket::query()->where('order_id', $order)->sole();

        $this->travelTo(Carbon::parse('2026-10-30 10:00', 'Europe/Madrid'));
        $invoice = app(InvoiceExchanger::class)->exchange($ticket, $this->company, $this->waiter);
        $record = $this->recordOf($invoice);

        $this->assertSame('F3', $record->invoice_type);
        $this->assertSame($ticket->fullNumber(), $this->value($record, '//sf:FacturasSustituidas/sf:IDFacturaSustituida/sf:NumSerieFactura'));
        $this->assertSame('28-10-2026', $this->value($record, '//sf:FacturasSustituidas/sf:IDFacturaSustituida/sf:FechaExpedicionFactura'));
        $this->assertSame('28-10-2026', $this->value($record, '//sf:FechaOperacion'));
        $this->assertSame('30-10-2026', $record->issue_date);
        $this->assertSame('B12345674', $this->value($record, '//sf:Destinatarios/sf:IDDestinatario/sf:NIF'));
        $this->assertValid($this->recordOf($ticket), $record);
    }

    public function test_r5_by_differences_and_by_substitution(): void
    {
        $this->openCash();
        $ticket = $this->ticket([[$this->pizza, 2]]);
        $diff = app(RectificationService::class)->byDifferences($ticket, $this->waiter, 'Devuelve una', [$ticket->lines->first()->id => 1], print: false);
        $record = $this->recordOf($diff);

        $this->assertSame('R5', $record->invoice_type);
        $this->assertSame('I', $this->value($record, '//sf:TipoRectificativa'));
        $this->assertSame('-10.00', $this->value($record, '//sf:ImporteTotal'));
        $this->assertSame($ticket->fullNumber(), $this->value($record, '//sf:FacturasRectificadas/sf:IDFacturaRectificada/sf:NumSerieFactura'));
        $this->assertSame(0, $this->xpath($record)->query('//sf:ImporteRectificacion')->length);

        $other = $this->ticket([[$this->pizza, 2]]);
        $subst = app(RectificationService::class)->bySubstitution($other, $this->waiter, 'Quita una', [$other->lines->first()->id => 1]);
        $sRecord = $this->recordOf($subst);
        $this->assertSame('S', $this->value($sRecord, '//sf:TipoRectificativa'));
        $this->assertSame(Verifactu::amount($other->tax_base), $this->value($sRecord, '//sf:ImporteRectificacion/sf:BaseRectificada'));
        $this->assertSame(Verifactu::amount($other->tax_amount), $this->value($sRecord, '//sf:ImporteRectificacion/sf:CuotaRectificada'));
        $this->assertValid($record, $sRecord);
    }

    public function test_full_invoice_rectifications_r1_and_r4(): void
    {
        $this->openCash();
        $issue = fn () => app(TicketIssuer::class)->issue(Order::query()->findOrFail($this->orderWith([[$this->pizza, 2]])), $this->waiter, CheckoutService::openSession(), recipient: $this->company);
        $invoice = $issue();
        $r1 = app(RectificationService::class)->byDifferences($invoice, $this->waiter, 'Devolución', [$invoice->lines->first()->id => 1], print: false, reasonCode: RectificationReason::ErrorOrDiscount);
        $other = Customer::create(['legal_name' => 'Restaurants del Port SLU', 'tax_id' => 'A58818501', 'fiscal_address' => 'Moll 1', 'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu']);
        $second = $issue();
        $r4 = app(RectificationService::class)->correctRecipient($second, $other, $this->waiter, 'Datos del cliente');

        $this->assertSame('R1', $this->recordOf($r1)->invoice_type);
        $this->assertSame('R4', $this->recordOf($r4)->invoice_type);
        $this->assertSame('A58818501', $this->value($this->recordOf($r4), '//sf:Destinatarios/sf:IDDestinatario/sf:NIF'));
        $this->assertValid($this->recordOf($invoice), $this->recordOf($r1), $this->recordOf($second), $this->recordOf($r4));
    }

    public function test_annulment_record_chained_and_without_previous_record_when_issued_before_activation(): void
    {
        $this->openCash();
        Setting::current()->update(['verifactu_enabled' => false]);
        $before = $this->ticket();                          // antes de activar: sin registro
        Setting::current()->update(['verifactu_enabled' => true]);
        $after = $this->ticket();

        [$a, $b] = DB::transaction(fn () => [
            app(FiscalRecordGenerator::class)->anulacion($after),
            app(FiscalRecordGenerator::class)->anulacion($before),
        ]);

        $this->assertSame(0, FiscalRecord::query()->where('ticket_id', $before->id)->where('record_type', 'alta')->count());
        $this->assertSame('anulacion', $a->record_type);
        $this->assertNull($this->value($a, '//sf:SinRegistroPrevio'));
        $this->assertSame('S', $this->value($b, '//sf:SinRegistroPrevio'));
        $this->assertSame($after->fullNumber(), $this->value($a, '//sf:IDFactura/sf:NumSerieFacturaAnulada'));
        $this->assertSame(ChainVerifier::recompute($a), $a->hash);
        $this->assertSame(['count' => 3, 'problems' => []], ChainVerifier::verify());
        $this->assertValid($this->recordOf($after), $a, $b);
    }

    public function test_chain_continues_across_new_year_while_numbering_restarts(): void
    {
        $this->travelTo(Carbon::parse('2026-12-31 23:59:59', 'Europe/Madrid'));
        Setting::current()->update(['verifactu_started_at' => now()->subHour()]);
        $this->openCash();
        $old = $this->recordOf($this->ticket());
        $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'Europe/Madrid'));
        $new = $this->recordOf($this->ticket());

        $this->assertStringEndsWith('-27-000001', $new->invoice_number);
        $this->assertSame($old->id, $new->previous_record_id);
        $this->assertSame('31-12-2026', $old->issue_date);
        $this->assertSame('2027-01-01T00:00:00+01:00', $new->generated_at);
        $this->assertSame(['count' => 2, 'problems' => []], ChainVerifier::verify());
    }

    public function test_no_records_while_verifactu_is_inactive(): void
    {
        Setting::current()->update(['verifactu_enabled' => false]);
        $this->openCash();
        $this->ticket();

        $this->assertSame(0, FiscalRecord::query()->count());
    }

    public function test_verify_detects_a_broken_chain(): void
    {
        $this->openCash();
        $first = $this->recordOf($this->ticket());
        // Un registro "colado" en la base de datos con una huella que no corresponde.
        DB::table('fiscal_records')->insert(collect($first->getAttributes())->except(['id', 'is_first', 'created_at'])->merge([
            'is_first' => false, 'previous_record_id' => $first->id, 'previous_hash' => $first->hash, 'hash' => str_repeat('A', 64),
        ])->all());

        $problems = ChainVerifier::verify()['problems'];
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('no corresponde a sus datos', implode(' ', $problems));
    }

    public function test_production_blocks_issuing_without_verifactu_from_the_obligation_date(): void
    {
        Setting::current()->update(['verifactu_enabled' => false]);
        config(['verifactu.obligation_date' => '2027-07-01']);
        $this->app->detectEnvironment(fn () => 'production');

        $this->travelTo(Carbon::parse('2027-06-10', 'Europe/Madrid'));
        $this->assertSame(21, EmissionGuard::warningDaysLeft());
        EmissionGuard::assertCanIssue();   // aún se puede

        $this->travelTo(Carbon::parse('2027-07-01 08:00', 'Europe/Madrid'));
        $this->expectException(VerifactuRequiredException::class);
        EmissionGuard::assertCanIssue();
    }

    public function test_schema_validator_rejects_an_invalid_record(): void
    {
        $this->openCash();
        $record = $this->recordOf($this->ticket());
        $xml = str_replace('<sf:TipoFactura>F2</sf:TipoFactura>', '<sf:TipoFactura>Z9</sf:TipoFactura>', SubmissionXml::build([$record], Setting::current()));

        $this->assertNotEmpty(VerifactuSchema::errors($xml));
    }
}
