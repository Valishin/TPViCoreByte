<?php

namespace App\Console\Commands;

use App\Enums\OrderType;
use App\Enums\RectificationMethod;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\FiscalRecord;
use App\Models\Setting;
use App\Models\Ticket;
use App\Services\Verifactu\AeatClient;
use App\Services\Verifactu\RecordBuilder;
use App\Services\Verifactu\ResponseParser;
use App\Services\Verifactu\SubmissionXml;
use App\Services\Verifactu\TaxQr;
use App\Services\Verifactu\Verifactu;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Envío manual de prueba al ENTORNO DE PRUEBAS de la AEAT con el certificado
 * instalado. Envía un ticket ficticio (F2, serie PRUEBA) y, con --rectify, su
 * rectificativo R5 con importe negativo (para comprobar el QR con importe
 * negativo: duda Q7). Los registros NO entran en la cadena real del TPV: llevan
 * su propia cadena de pruebas en storage/app/private/verifactu-test-chain.json.
 */
#[Signature('tpv:verifactu:test-send {--rectify : Envía también un R5 con importe negativo}')]
#[Description('Envía un registro de prueba al entorno de PRUEBAS de la AEAT con el certificado instalado')]
class VerifactuTestSendCommand extends Command
{
    public function handle(AeatClient $client, RecordBuilder $builder): int
    {
        $settings = Setting::current();
        $endpoint = config('verifactu.endpoints.pruebas.'.($settings->verifactu_certificate_kind === 'sello' ? 'sello' : 'personal'));
        $this->warn("Entorno de PRUEBAS: {$endpoint}");

        $state = json_decode((string) Storage::disk('local')->get('verifactu-test-chain.json'), true) ?: [];
        $previous = isset($state['hash']) ? new FiscalRecord($state) : null;
        $ticket = $this->fakeTicket($settings, 'PRUEBA-'.now()->format('ymdHis'), 1210, 110, TicketType::Simplified);
        $docs = [$ticket];
        if ($this->option('rectify')) {
            $rect = $this->fakeTicket($settings, 'PRUEBA-R-'.now()->format('ymdHis'), -1210, -110, TicketType::Rectifying);
            $rect->rectification_method = RectificationMethod::Differences;
            $rect->setRelation('rectifiedTicket', $ticket);
            $docs[] = $rect;
        }

        $records = [];
        foreach ($docs as $doc) {
            $built = $builder->alta($doc, $settings, $previous, Verifactu::timestamp(now()));
            $previous = new FiscalRecord($built['columns'] + ['hash' => $built['hash'], 'xml' => $built['xml']]);
            $records[] = $previous;
        }
        $xml = SubmissionXml::soap(SubmissionXml::build($records, $settings));
        try {
            $response = $client->post($xml, $endpoint, $settings);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        Storage::disk('local')->put('verifactu-test-'.now()->format('Ymd-His').'.xml', $xml."\n\n<!-- RESPUESTA HTTP {$response['status']} -->\n".$response['body']);
        $parsed = ResponseParser::parse($response['body']);
        if ($parsed['fault']) {
            $this->error("SOAP Fault ({$parsed['fault']}): {$parsed['fault_message']}");

            return self::FAILURE;
        }
        $this->info("Estado del envío: {$parsed['status']} · CSV {$parsed['csv']} · espera {$parsed['wait']} s");
        foreach ($parsed['lines'] as $line) {
            $this->line("  {$line['number']}: {$line['status']}".($line['error_code'] ? " [{$line['error_code']}] {$line['error']}" : ''));
        }
        foreach ($records as $record) {
            $this->line('  QR: '.TaxQr::url($record, 'pruebas'));
        }
        Storage::disk('local')->put('verifactu-test-chain.json', json_encode(collect($previous->getAttributes())->only(['issuer_nif', 'invoice_number', 'issue_date', 'hash'])->all()));
        $this->line('XML enviado y respuesta guardados en storage/app/private/.');

        return self::SUCCESS;
    }

    private function fakeTicket(Setting $settings, string $number, int $total, int $tax, TicketType $type): Ticket
    {
        $ticket = new Ticket([
            'type' => $type, 'status' => TicketStatus::Issued, 'order_type' => OrderType::Bar, 'issued_at' => now(),
            'total' => $total, 'tax_amount' => $tax, 'tax_base' => $total - $tax,
            'vat_breakdown' => [['rate' => 1000, 'base' => $total - $tax, 'tax' => $tax, 'total' => $total]],
            'issuer' => $settings->issuerSnapshot(),
        ]);
        $ticket->full_number = $number;
        $ticket->setRelation('lines', collect());
        $ticket->setRelation('rectifiedTicket', null);
        $ticket->setRelation('replacedTicket', null);

        return $ticket;
    }
}
