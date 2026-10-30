<?php

namespace Tests\Feature\Verifactu;

use App\Enums\OrderType;
use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\TicketIssuer;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\Reconciler;
use App\Services\Verifactu\Verifactu;
use App\Support\VerifactuAlarm;
use DOMDocument;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pos\InvoiceTestCase;
use Tests\Support\FakeAeat;
use Tests\Support\VerifactuSchema;

class ReconcileVerifyAndTestSendTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL']);
        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674']), 'secreto');
        $this->openCash();
        VerifactuAlarm::clear();
    }

    private function accepted(): FiscalRecord
    {
        $order = app(OrderService::class)->open(OrderType::Bar, $this->waiter);
        app(OrderLineBuilder::class)->addProduct($order, $this->pizza);
        $ticket = app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession());
        $record = FiscalRecord::query()->where('ticket_id', $ticket->id)->sole();
        $record->state->update(['status' => FiscalRecordState::ACCEPTED]);

        return $record;
    }

    /** Respuesta de la consulta con la estructura de RespuestaConsultaLR.xsd. */
    private function queryResponse(array $rows, string $more = 'N'): string
    {
        $sf = Verifactu::NS;
        $r = Verifactu::NS_QUERY_RESPONSE;
        $xml = "<tikLRRC:RespuestaConsultaFactuSistemaFacturacion xmlns:tikLRRC=\"{$r}\" xmlns:tik=\"{$sf}\">"
            .'<tikLRRC:Cabecera><tik:IDVersion>1.0</tik:IDVersion><tik:ObligadoEmision><tik:NombreRazon>Pizzeria Bella Napoli SL</tik:NombreRazon><tik:NIF>B12345674</tik:NIF></tik:ObligadoEmision></tikLRRC:Cabecera>'
            .'<tikLRRC:PeriodoImputacion><tikLRRC:Ejercicio>'.now()->format('Y').'</tikLRRC:Ejercicio><tikLRRC:Periodo>'.now()->format('m').'</tikLRRC:Periodo></tikLRRC:PeriodoImputacion>'
            ."<tikLRRC:IndicadorPaginacion>{$more}</tikLRRC:IndicadorPaginacion><tikLRRC:ResultadoConsulta>".($rows ? 'ConDatos' : 'SinDatos').'</tikLRRC:ResultadoConsulta>';
        foreach ($rows as [$nif, $number, $date, $status]) {
            $xml .= '<tikLRRC:RegistroRespuestaConsultaFactuSistemaFacturacion><tikLRRC:IDFactura>'
                ."<tik:IDEmisorFactura>{$nif}</tik:IDEmisorFactura><tik:NumSerieFactura>{$number}</tik:NumSerieFactura><tik:FechaExpedicionFactura>{$date}</tik:FechaExpedicionFactura>"
                .'</tikLRRC:IDFactura><tikLRRC:DatosRegistroFacturacion/>'
                ."<tikLRRC:EstadoRegistro><tikLRRC:TimestampUltimaModificacion>2026-10-30T12:00:00+01:00</tikLRRC:TimestampUltimaModificacion><tikLRRC:EstadoRegistro>{$status}</tikLRRC:EstadoRegistro></tikLRRC:EstadoRegistro>"
                .'</tikLRRC:RegistroRespuestaConsultaFactuSistemaFacturacion>';
        }

        return $xml.'</tikLRRC:RespuestaConsultaFactuSistemaFacturacion>';
    }

    public function test_reconciliation_flags_discrepancies(): void
    {
        [$ok, $missing, $annulledThere] = [$this->accepted(), $this->accepted(), $this->accepted()];
        $body = $this->queryResponse([
            [$ok->issuer_nif, $ok->invoice_number, $ok->issue_date, 'Correcto'],
            [$annulledThere->issuer_nif, $annulledThere->invoice_number, $annulledThere->issue_date, 'Anulado'],
            ['B12345674', 'OTRO-26-000001', $ok->issue_date, 'Correcto'],
        ]);
        $this->assertSame([], VerifactuSchema::errors($body, 'RespuestaConsultaLR.xsd'));
        Http::fake(['*' => Http::response('<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>'.$body.'</env:Body></env:Envelope>')]);

        $result = app(Reconciler::class)->reconcile((int) now()->format('Y'), (int) now()->format('n'));

        $this->assertSame(3, $result['checked']);
        $this->assertSame(1, $result['matched']);
        $this->assertCount(3, $result['discrepancies']);   // no consta, consta anulado, factura ajena
        $this->assertSame('no_consta', $missing->state()->first()->aeat_status);
        $this->assertSame('Correcto', $ok->state()->first()->aeat_status);
        Http::assertSent(function (Request $request) {
            $doc = new DOMDocument;
            $doc->loadXML($request->body());
            $body = $doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0)->firstElementChild;

            return VerifactuSchema::errors($doc->saveXML($body), 'ConsultaLR.xsd') === [];   // la consulta cumple el XSD
        });
    }

    public function test_verify_command_checks_the_whole_chain(): void
    {
        $first = $this->accepted();
        $this->accepted();
        $this->artisan('tpv:verifactu:verify')->expectsOutputToContain('Cadena correcta: 2 registros')->assertSuccessful();

        DB::table('fiscal_records')->insert(collect(FiscalRecord::query()->latest('id')->first()->getAttributes())->except(['id', 'created_at'])
            ->merge(['previous_record_id' => FiscalRecord::query()->latest('id')->value('id'), 'hash' => str_repeat('B', 64)])->all());
        $this->artisan('tpv:verifactu:verify')->assertFailed();
        $this->assertSame('chain', VerifactuAlarm::current(VerifactuAlarm::CHAIN)['kind']);
        $this->assertNotNull($first);
    }

    public function test_test_send_goes_to_the_test_environment_outside_the_real_chain(): void
    {
        Storage::fake('local');
        $sent = [];
        Http::fake(function (Request $request) use (&$sent) {
            $sent[] = $request;

            return Http::response('<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body>'
                .FakeAeat::body([], 'Correcto').'</env:Body></env:Envelope>');
        });
        Setting::current()->update(['verifactu_environment' => 'produccion']);   // aun así, va a pruebas

        $this->artisan('tpv:verifactu:test-send', ['--rectify' => true])->expectsOutputToContain('Entorno de PRUEBAS')->assertSuccessful();
        $this->artisan('tpv:verifactu:test-send')->assertSuccessful();

        $this->assertSame('https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', $sent[0]->url());
        $this->assertStringContainsString('<sf:TipoFactura>R5</sf:TipoFactura>', $sent[0]->body());
        $this->assertStringContainsString('<sf:ImporteTotal>-12.10</sf:ImporteTotal>', $sent[0]->body());
        $this->assertStringContainsString('<sf:PrimerRegistro>S</sf:PrimerRegistro>', $sent[0]->body());
        $this->assertStringContainsString('<sf:RegistroAnterior>', $sent[1]->body());   // su propia cadena de pruebas
        $this->assertSame(0, FiscalRecord::query()->count());   // nada en la cadena real
        $doc = new DOMDocument;
        $doc->loadXML($sent[0]->body());
        $this->assertSame([], VerifactuSchema::errors($doc->saveXML($doc->getElementsByTagNameNS('http://schemas.xmlsoap.org/soap/envelope/', 'Body')->item(0)->firstElementChild)));
    }
}
