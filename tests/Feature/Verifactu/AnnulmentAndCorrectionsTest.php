<?php

namespace Tests\Feature\Verifactu;

use App\Models\Customer;
use App\Models\FiscalRecord;
use App\Models\Precheck;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Cash\CashReportService;
use App\Services\Tickets\AnnulmentService;
use App\Services\Tickets\NotDeliveredPolicy;
use App\Services\Tickets\RectificationService;
use App\Services\Verifactu\ChainVerifier;
use App\Services\Verifactu\SubmissionXml;
use App\Services\Verifactu\Verifactu;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Pos\InvoiceTestCase;
use Tests\Support\VerifactuSchema;

/** Anulación (C3), no entregado (C4), correcciones de canje según la FAQ 27 (C1, C2) y precuentas (Q9). */
class AnnulmentAndCorrectionsTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tpv.install_mode' => 'cliente']);   // VERI*FACTU solo en instalaciones reales
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674']);
    }

    private function paidTicket(int $amount = 1000): Ticket
    {
        $order = $this->orderWith([[$this->pizza, 1]]);
        $this->checkout($order, [['method' => 'efectivo', 'amount' => $amount, 'tendered' => $amount]])->assertCreated();

        return Ticket::query()->where('order_id', $order)->sole();
    }

    private function value(FiscalRecord $record, string $path): ?string
    {
        $doc = new DOMDocument;
        $doc->loadXML($record->xml);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('sf', Verifactu::NS);

        return $xp->query($path)->item(0)?->textContent;
    }

    public function test_annul_by_error_generates_annulment_record_refunds_and_leaves_sales(): void
    {
        $session = $this->openCash();
        $ticket = $this->paidTicket();

        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/annul", ['reason' => 'Ticket duplicado'])->assertStatus(403);  // encargado
        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/annul", ['reason' => 'Ticket duplicado', 'authorization_token' => $this->managerToken('anular_ticket')])
            ->assertOk()->assertJsonPath('data.status', 'anulado')->assertJsonPath('data.can_annul', false);

        $records = FiscalRecord::query()->where('ticket_id', $ticket->id)->orderBy('id')->get();
        $this->assertSame(['alta', 'anulacion'], $records->pluck('record_type')->all());
        $this->assertSame($records[0]->id, $records[1]->previous_record_id);
        $this->assertNull($this->value($records[1], '//sf:SinRegistroPrevio'));
        $this->assertSame(-1000, (int) $ticket->payments()->where('amount', '<', 0)->sum('amount'));

        $report = app(CashReportService::class)->build($session->refresh());
        $this->assertSame(0, $report['sales_total']);
        $this->assertSame(1, $report['annulled']['count']);
        $this->assertSame(10000, $report['cash']['expected']);   // cobro y devolución en efectivo: neto 0
        $this->assertSame(['count' => 2, 'problems' => []], ChainVerifier::verify());
        $this->assertSame([], VerifactuSchema::errors(SubmissionXml::build($records, Setting::current())));
    }

    public function test_real_sales_cannot_be_annulled(): void
    {
        $this->openCash();
        $rectified = $this->paidTicket();
        app(RectificationService::class)->byDifferences($rectified, $this->waiter, 'Devolución');
        $exchanged = $this->paidTicket();
        $this->asWaiter()->postJson("/api/tickets/{$exchanged->id}/exchange", ['customer_id' => $this->company->id])->assertCreated();

        foreach ([$rectified->refresh(), $exchanged->refresh(), Ticket::query()->where('type', 'rectificativa')->first(), Ticket::query()->where('type', 'completa_canje')->first()] as $ticket) {
            try {
                app(AnnulmentService::class)->annul($ticket, $this->waiter, 'Ticket duplicado', authorized: true);
                $this->fail("{$ticket->fullNumber()} no debía poder anularse.");
            } catch (\LogicException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function test_not_delivered_policy_annuls_unpaid_and_rectifies_prepaid(): void
    {
        $this->openCash();
        $unpaid = $this->deliveryWith();
        $prepaid = $this->deliveryWith(null, ['payment' => 'pagado:tarjeta']);
        foreach ([$unpaid, $prepaid] as $id) {
            $this->send($id)->assertOk();
            $this->asWaiter()->postJson("/api/takeaway-orders/{$id}/dispatch", ['courier_id' => $this->javi->id])->assertOk();
        }
        $prepaidTicket = Ticket::query()->where('order_id', $prepaid)->where('type', 'simplificada')->sole();
        $this->assertSame('pagado', $prepaidTicket->payment_status->value);
        $this->assertSame(NotDeliveredPolicy::RECTIFY, NotDeliveredPolicy::decide($prepaidTicket));

        $this->asWaiter()->postJson("/api/takeaway-orders/{$unpaid}/not-delivered", ['reason' => 'Rechaza el pedido', 'authorization_token' => $this->managerToken('no_entregado')])
            ->assertOk()->assertJsonPath('outcome', 'anulacion');
        $response = $this->asWaiter()->postJson("/api/takeaway-orders/{$prepaid}/not-delivered", ['reason' => 'Rechaza el pedido', 'authorization_token' => $this->managerToken('no_entregado')])
            ->assertOk()->assertJsonPath('outcome', 'rectificativo');

        $rect = Ticket::query()->findOrFail($response->json('rectifying.id'));
        $this->assertSame(-$prepaidTicket->total, $rect->total);
        $this->assertSame('tarjeta', $rect->payments()->sole()->method->value);   // devolución del prepago
        $this->assertSame('R5', FiscalRecord::query()->where('ticket_id', $rect->id)->sole()->invoice_type);
        $this->assertSame('anulacion', FiscalRecord::query()->whereHas('ticket', fn ($q) => $q->where('order_id', $unpaid))->latest('id')->first()->record_type);
    }

    public function test_faq27_records_for_exchange_corrections(): void
    {
        $this->openCash();
        $ticket = $this->paidTicket();
        $this->asWaiter()->postJson("/api/tickets/{$ticket->id}/exchange", ['customer_id' => $this->company->id])->assertCreated();
        $exchange = $ticket->refresh()->exchangedBy;

        // C1: cliente erróneo → F3 negativa + F3 nueva, ambas sustituyendo al ticket.
        $right = Customer::create(['legal_name' => 'Restaurants del Port SLU', 'tax_id' => 'A58818501', 'fiscal_address' => 'Moll 1', 'fiscal_postal_code' => '17220', 'fiscal_city' => 'Sant Feliu']);
        app(RectificationService::class)->correctRecipient($exchange, $right, $this->waiter, 'Datos del cliente');
        [$negative, $new] = $ticket->exchanges()->where('id', '>', $exchange->id)->orderBy('id')->get()->map(fn ($t) => FiscalRecord::query()->where('ticket_id', $t->id)->sole())->all();
        $this->assertSame(['F3', 'F3'], [$negative->invoice_type, $new->invoice_type]);
        $this->assertSame('-10.00', $this->value($negative, '//sf:ImporteTotal'));
        $this->assertSame($ticket->fullNumber(), $this->value($negative, '//sf:FacturasSustituidas/sf:IDFacturaSustituida/sf:NumSerieFactura'));
        $this->assertSame('B12345674', $this->value($negative, '//sf:Destinatarios/sf:IDDestinatario/sf:NIF'));   // contrarresta la errónea
        $this->assertSame('A58818501', $this->value($new, '//sf:Destinatarios/sf:IDDestinatario/sf:NIF'));

        // C2: devolución del ticket canjeado → R5 del ticket + F3 del R5 (cliente vigente).
        $r5 = app(RectificationService::class)->byDifferences($ticket->refresh(), $this->waiter, 'Devolución');
        $f3OfR5 = FiscalRecord::query()->whereHas('ticket', fn ($q) => $q->where('replaces_ticket_id', $r5->id))->sole();
        $this->assertSame($r5->fullNumber(), $this->value($f3OfR5, '//sf:FacturasSustituidas/sf:IDFacturaSustituida/sf:NumSerieFactura'));
        $this->assertSame('A58818501', $this->value($f3OfR5, '//sf:Destinatarios/sf:IDDestinatario/sf:NIF'));
        $this->assertSame('-10.00', $this->value($f3OfR5, '//sf:ImporteTotal'));

        $all = FiscalRecord::query()->orderBy('id')->get();
        $this->assertSame([], VerifactuSchema::errors(SubmissionXml::build($all, Setting::current())));
        $this->assertSame([], ChainVerifier::verify()['problems']);
    }

    public function test_precheck_is_stored_unalterably(): void
    {
        $this->openCash();
        $order = $this->orderWith([[$this->pizza, 2]]);
        $this->asWaiter()->postJson("/api/orders/{$order}/precheck")->assertOk();

        $precheck = Precheck::query()->where('order_id', $order)->sole();
        $this->assertSame(2000, $precheck->total);
        $this->assertNotEmpty($precheck->content['printed']);
        $this->assertSame(0, FiscalRecord::query()->count());   // no es factura: sin registro

        $this->expectException(QueryException::class);
        DB::table('prechecks')->where('id', $precheck->id)->delete();
    }
}
