<?php

namespace Tests\Feature\Verifactu;

use App\Enums\OrderType;
use App\Models\Customer;
use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Models\VerifactuChain;
use App\Models\VerifactuSubmission;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\TicketIssuer;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\CorrectionService;
use App\Services\Verifactu\ResponseParser;
use App\Services\Verifactu\SubmissionXml;
use App\Services\Verifactu\VerifactuSender;
use App\Support\VerifactuAlarm;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pos\InvoiceTestCase;
use Tests\Support\FakeAeat;
use Tests\Support\VerifactuSchema;

/** Envío con el servicio de la AEAT simulado: control de flujo, sin conexión, respuestas, duplicados, SOAP Fault y subsanación. */
class SenderTest extends InvoiceTestCase
{
    /** Comportamiento actual de la AEAT simulada (Http::fake acumula; se cambia este cierre). */
    private \Closure $aeat;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tpv.install_mode' => 'cliente']);   // VERI*FACTU solo en instalaciones reales
        Queue::fake();   // el envío se lanza a mano en cada test
        $this->aeat = fn () => Http::response('', 503);
        Http::fake(fn (Request $request) => ($this->aeat)($request));
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL']);
        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674']), 'secreto');
        $this->openCash();
        VerifactuAlarm::clear();
    }

    private function record(?Customer $recipient = null): FiscalRecord
    {
        $order = app(OrderService::class)->open(OrderType::Bar, $this->waiter);
        app(OrderLineBuilder::class)->addProduct($order, $this->pizza);
        $ticket = app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession(), recipient: $recipient);

        return FiscalRecord::query()->where('ticket_id', $ticket->id)->sole();
    }

    /** Responde «Correcto» a todo lo que llegue (lee el XML enviado). */
    private function acceptEverything(int $wait = 60): void
    {
        $this->aeat = (function (Request $request) use ($wait) {
            $doc = new DOMDocument;
            $doc->loadXML($request->body());
            $xp = new DOMXPath($doc);
            $lines = [];
            foreach ($xp->query('//*[local-name()="NumSerieFactura" and parent::*[local-name()="IDFactura"]]') as $n) {
                $lines[] = ['record' => FiscalRecord::query()->where('invoice_number', $n->textContent)->latest('id')->first(), 'status' => 'Correcto'];
            }

            return Http::response(FakeAeat::response($lines, 'Correcto', $wait));
        });
    }

    private function stateOf(FiscalRecord $record): string
    {
        return $record->state()->first()->status;
    }

    public function test_fake_responses_follow_the_official_schema(): void
    {
        $record = $this->record();
        $body = FakeAeat::body([['record' => $record, 'status' => 'Incorrecto', 'code' => '3000', 'duplicate' => ['status' => 'Correcta']]], 'Incorrecto');
        $this->assertSame([], VerifactuSchema::errors($body, 'RespuestaSuministro.xsd'));

        $parsed = ResponseParser::parse(FakeAeat::response([['record' => $record, 'status' => 'AceptadoConErrores', 'code' => '2001']], 'ParcialmenteCorrecto', 120));
        $this->assertSame(['csv' => 'A-TEST1234567890', 'wait' => 120, 'status' => 'ParcialmenteCorrecto'], array_intersect_key($parsed, array_flip(['csv', 'wait', 'status'])));
        $this->assertSame('2001', $parsed['lines'][0]['error_code']);
    }

    public function test_sends_and_stores_csv_request_response_and_first_sent_date(): void
    {
        $this->acceptEverything();
        $record = $this->record();

        $this->assertStringStartsWith('Envío Correcto', app(VerifactuSender::class)->run());

        $this->assertSame(FiscalRecordState::ACCEPTED, $this->stateOf($record));
        $submission = VerifactuSubmission::query()->sole();
        $this->assertSame('A-TEST1234567890', $submission->csv);
        $this->assertSame([], VerifactuSchema::errors($this->bodyOf($submission->request_xml)));
        $this->assertStringContainsString($record->hash, $submission->request_xml);
        $this->assertNotNull(Setting::current()->verifactu_first_sent_at);   // RRSIF 16.5
        Http::assertSent(fn (Request $r) => $r->url() === 'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP');
    }

    public function test_flow_control_waits_the_time_given_by_the_aeat_unless_the_batch_is_full(): void
    {
        $this->acceptEverything(wait: 120);
        $this->record();
        app(VerifactuSender::class)->run();
        $this->assertSame(120, VerifactuChain::head()->wait_seconds);

        $second = $this->record();
        $this->assertStringStartsWith('Control de flujo', app(VerifactuSender::class)->run());
        $this->assertSame(1, VerifactuSubmission::query()->count());

        $this->travel(121)->seconds();
        app(VerifactuSender::class)->run();
        $this->assertSame(FiscalRecordState::ACCEPTED, $this->stateOf($second));

        // Con el máximo de registros acumulado se envía sin esperar (OM 16.2.c), en lotes de ese máximo.
        config(['verifactu.max_records_per_submission' => 2]);
        $more = [$this->record(), $this->record(), $this->record()];
        app(VerifactuSender::class)->run();
        $this->assertSame(3, VerifactuSubmission::query()->count());
        $this->assertSame(2, VerifactuSubmission::query()->latest('id')->first()->record_count);
        $this->assertSame(FiscalRecordState::PENDING, $this->stateOf($more[2]));
    }

    public function test_offline_keeps_records_pending_and_sends_them_later_in_order_with_incidencia(): void
    {
        $this->aeat = fn () => throw new ConnectionException('Could not resolve host');
        $first = $this->record();
        $this->assertStringStartsWith('Sin envío', app(VerifactuSender::class)->run());
        $this->assertSame(FiscalRecordState::PENDING, $this->stateOf($first));
        $this->assertTrue($first->state()->first()->incident);
        $this->assertNotNull(VerifactuChain::head()->incident_since);

        $during = $this->record();   // generado durante la incidencia
        $this->assertTrue($during->state()->first()->incident);

        $this->travel(2)->hours();
        $this->acceptEverything();
        app(VerifactuSender::class)->run();

        $request = VerifactuSubmission::query()->latest('id')->first()->request_xml;
        $this->assertStringContainsString('<sf:Incidencia>S</sf:Incidencia>', $request);
        $this->assertLessThan(strpos($request, $during->invoice_number), strpos($request, $first->invoice_number));   // en orden
        $this->assertSame(FiscalRecordState::ACCEPTED, $this->stateOf($first));
        $this->assertSame(FiscalRecordState::ACCEPTED, $this->stateOf($during));
        $this->assertNull(VerifactuChain::head()->incident_since);

        // Después de la incidencia, los registros nuevos ya no llevan Incidencia.
        $this->travel(2)->minutes();
        $this->record();
        app(VerifactuSender::class)->run();
        $this->assertStringNotContainsString('Incidencia', VerifactuSubmission::query()->latest('id')->first()->request_xml);
    }

    public function test_each_record_status_from_the_response(): void
    {
        [$ok, $warn, $exempt, $bad, $dupOk, $dupAnnulled] = [$this->record(), $this->record($this->company), $this->record(), $this->record(), $this->record(), $this->record()];
        $this->aeat = fn () => Http::response(FakeAeat::response([
            ['record' => $ok, 'status' => 'Correcto'],
            ['record' => $warn, 'status' => 'AceptadoConErrores', 'code' => '2001', 'error' => 'El NIF del bloque Destinatarios no está identificado en el censo de la AEAT.'],
            ['record' => $exempt, 'status' => 'AceptadoConErrores', 'code' => '2004', 'error' => 'FechaHoraHusoGenRegistro'],
            ['record' => $bad, 'status' => 'Incorrecto', 'code' => '1100', 'error' => 'Valor o tipo incorrecto'],
            ['record' => $dupOk, 'status' => 'Incorrecto', 'code' => '3000', 'error' => 'Registro de facturación duplicado.', 'duplicate' => ['status' => 'Correcta']],
            ['record' => $dupAnnulled, 'status' => 'Incorrecto', 'code' => '3000', 'error' => 'Registro de facturación duplicado.', 'duplicate' => ['status' => 'Anulada']],
        ], 'ParcialmenteCorrecto'));

        app(VerifactuSender::class)->run();

        $this->assertSame(FiscalRecordState::ACCEPTED, $this->stateOf($ok));
        $this->assertSame([FiscalRecordState::ACCEPTED_WITH_ERRORS, true], [$this->stateOf($warn), $warn->state()->first()->needs_correction]);
        $this->assertSame([FiscalRecordState::ACCEPTED_WITH_ERRORS, false], [$this->stateOf($exempt), $exempt->state()->first()->needs_correction]);
        $this->assertSame([FiscalRecordState::REJECTED, true, '1100'], [$this->stateOf($bad), $bad->state()->first()->needs_correction, $bad->state()->first()->error_code]);
        $this->assertSame(FiscalRecordState::ACCEPTED, $this->stateOf($dupOk));
        $this->assertSame(FiscalRecordState::REJECTED, $this->stateOf($dupAnnulled));
        $this->assertNotNull(VerifactuAlarm::current());
        $this->assertSame(0, VerifactuSender::pendingQuery()->count());   // los rechazados no se reenvían solos
    }

    public function test_soap_fault_client_needs_review_and_server_retries(): void
    {
        $record = $this->record();
        $this->aeat = fn () => Http::response(FakeAeat::fault('Server', 'Error interno'), 500);
        app(VerifactuSender::class)->run();
        $this->assertSame(FiscalRecordState::PENDING, $this->stateOf($record));
        $this->assertTrue($record->state()->first()->incident);

        $this->travel(2)->minutes();
        $this->aeat = fn () => Http::response(FakeAeat::fault('Client', 'Codigo[4104].El NIF del titular en la cabecera no está identificado.'), 500);
        app(VerifactuSender::class)->run();
        $state = $record->state()->first();
        $this->assertSame(FiscalRecordState::SEND_ERROR, $state->status);
        $this->assertStringContainsString('4104', $state->error_message);
        $this->assertSame('soap_fault_cliente', VerifactuSubmission::query()->latest('id')->first()->result);
        $this->assertNotNull(VerifactuAlarm::current());
    }

    public function test_correction_of_rejected_and_not_censused_records(): void
    {
        [$rejected, $notCensused] = [$this->record(), $this->record($this->company)];
        $this->aeat = fn () => Http::response(FakeAeat::response([
            ['record' => $rejected, 'status' => 'Incorrecto', 'code' => '1100'],
            ['record' => $notCensused, 'status' => 'AceptadoConErrores', 'code' => '2001'],
        ], 'ParcialmenteCorrecto'));
        app(VerifactuSender::class)->run();

        $fixed = app(CorrectionService::class)->correct($rejected);
        $this->assertTrue($fixed->is_correction);
        $this->assertSame('X', $fixed->previous_rejection);   // alta rechazada: no consta en la AEAT
        $this->assertStringContainsString('<sf:Subsanacion>S</sf:Subsanacion><sf:RechazoPrevio>X</sf:RechazoPrevio>', $fixed->xml);
        $this->assertSame($fixed->id, $rejected->state()->first()->corrected_by_record_id);

        $fixed2 = app(CorrectionService::class)->correct($notCensused);
        $this->assertNull($fixed2->previous_rejection);       // aceptada con errores: consta en la AEAT
        $this->assertStringContainsString('<sf:IDType>07</sf:IDType>', $fixed2->xml);

        $this->assertSame([], VerifactuSchema::errors(SubmissionXml::build([$fixed, $fixed2], Setting::current())));
        $this->expectException(\LogicException::class);
        app(CorrectionService::class)->correct($rejected);   // ya subsanado
    }

    private function bodyOf(string $soap): string
    {
        $doc = new DOMDocument;
        $doc->loadXML($soap);
        $body = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0);

        return $doc->saveXML($body->firstElementChild);
    }
}
