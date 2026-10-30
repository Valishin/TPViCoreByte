<?php

namespace App\Services\Verifactu;

use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Conciliación con la AEAT mediante el servicio de consulta
 * ConsultaFactuSistemaFacturacion (ConsultaLR.xsd; Descripción servicios web
 * 6.4: por ejercicio y periodo de imputación —fecha de operación o, si no hay,
 * de expedición—, como emisor, con paginación de hasta 10.000 registros).
 *
 * Compara el estado que consta en la AEAT (Correcta, AceptadaConErrores,
 * Anulada) con el que esperamos según nuestros registros y anota las
 * discrepancias.
 */
class Reconciler
{
    public function __construct(private AeatClient $client) {}

    /**
     * @return array{checked: int, matched: int, discrepancies: list<string>}
     */
    public function reconcile(int $year, int $month): array
    {
        $settings = Setting::current();
        $aeat = $this->query($settings, $year, $month);
        $local = $this->localInvoices($year, $month);

        $discrepancies = [];
        $matched = 0;
        foreach ($local as $key => $item) {
            $remote = $aeat[$key] ?? null;
            /** @var FiscalRecord $last */
            $last = $item['last'];
            $state = $last->state;
            if (! $state || ! in_array($state->status, [FiscalRecordState::ACCEPTED, FiscalRecordState::ACCEPTED_WITH_ERRORS], true)) {
                continue;   // aún no aceptado: nada que comparar
            }
            // RespuestaConsultaLR.xsd: Correcto | AceptadoConErrores | Anulado (la descripción de los servicios usa el femenino).
            $expected = $last->isAlta() ? ['Correcto', 'AceptadoConErrores', 'Correcta', 'AceptadaConErrores'] : ['Anulado', 'Anulada'];
            $state->update(['aeat_status' => $remote ?? 'no_consta', 'reconciled_at' => now()]);
            if ($remote === null) {
                $discrepancies[] = "{$last->invoice_number}: aceptado según nuestros registros pero no consta en la AEAT.";
            } elseif (! in_array($remote, $expected, true)) {
                $discrepancies[] = "{$last->invoice_number}: en la AEAT consta «{$remote}» y esperábamos «".implode(' o ', $expected).'».';
            } else {
                $matched++;
            }
        }
        foreach (array_diff_key($aeat, $local) as $key => $status) {
            $discrepancies[] = explode('|', $key)[1].": consta en la AEAT ({$status}) pero no en este TPV (¿otro sistema de facturación?).";
        }

        return ['checked' => count($local), 'matched' => $matched, 'discrepancies' => $discrepancies];
    }

    /** XML de la consulta (para tests y para el envío). */
    public static function queryXml(Setting $settings, int $year, int $month, ?array $pageKey = null): string
    {
        $sf = Verifactu::NS;
        $c = Verifactu::NS_QUERY;
        $e = fn (string $v) => htmlspecialchars($v, ENT_XML1);
        $xml = "<con:ConsultaFactuSistemaFacturacion xmlns:con=\"{$c}\" xmlns:sf=\"{$sf}\"><con:Cabecera><sf:IDVersion>".Verifactu::SCHEMA_VERSION.'</sf:IDVersion>'
            .'<sf:ObligadoEmision><sf:NombreRazon>'.$e(mb_substr(trim((string) $settings->legal_name), 0, 120)).'</sf:NombreRazon><sf:NIF>'.$e(RecordBuilder::nif($settings->tax_id)).'</sf:NIF></sf:ObligadoEmision>'
            .'</con:Cabecera><con:FiltroConsulta><con:PeriodoImputacion><sf:Ejercicio>'.sprintf('%04d', $year).'</sf:Ejercicio><sf:Periodo>'.sprintf('%02d', $month).'</sf:Periodo></con:PeriodoImputacion>';
        if ($pageKey) {
            $xml .= '<con:ClavePaginacion><sf:IDEmisorFactura>'.$e($pageKey['nif']).'</sf:IDEmisorFactura><sf:NumSerieFactura>'.$e($pageKey['number']).'</sf:NumSerieFactura><sf:FechaExpedicionFactura>'.$e($pageKey['date']).'</sf:FechaExpedicionFactura></con:ClavePaginacion>';
        }

        return $xml.'</con:FiltroConsulta></con:ConsultaFactuSistemaFacturacion>';
    }

    /** @return array<string, string> "nif|número|fecha" => estado en la AEAT */
    private function query(Setting $settings, int $year, int $month): array
    {
        $found = [];
        $pageKey = null;
        do {
            $response = $this->client->post(SubmissionXml::soap(self::queryXml($settings, $year, $month, $pageKey)), null, $settings);
            $doc = new DOMDocument;
            if (! @$doc->loadXML($response['body'])) {
                throw new RuntimeException('Respuesta de la consulta no válida.');
            }
            $xp = new DOMXPath($doc);
            if ($fault = $xp->query('//*[local-name()="Fault"]')->item(0)) {
                throw new RuntimeException('La AEAT ha rechazado la consulta: '.trim($fault->textContent));
            }
            foreach ($xp->query('//*[local-name()="RegistroRespuestaConsultaFactuSistemaFacturacion"]') as $row) {
                /** @var DOMElement $row */
                $id = fn (string $f) => trim((string) $xp->evaluate('string(./*[local-name()="IDFactura"]/*[local-name()="'.$f.'"])', $row));
                $status = trim((string) $xp->evaluate('string(./*[local-name()="EstadoRegistro"]/*[local-name()="EstadoRegistro"])', $row));
                $found[$id('IDEmisorFactura').'|'.$id('NumSerieFactura').'|'.$id('FechaExpedicionFactura')] = $status;
            }
            $more = trim((string) $xp->evaluate('string(//*[local-name()="IndicadorPaginacion"])')) === 'S';
            $key = $xp->query('//*[local-name()="RespuestaConsultaFactuSistemaFacturacion"]/*[local-name()="ClavePaginacion"]')->item(0);
            $pageKey = $more && $key ? [
                'nif' => trim((string) $xp->evaluate('string(./*[local-name()="IDEmisorFactura"])', $key)),
                'number' => trim((string) $xp->evaluate('string(./*[local-name()="NumSerieFactura"])', $key)),
                'date' => trim((string) $xp->evaluate('string(./*[local-name()="FechaExpedicionFactura"])', $key)),
            ] : null;
        } while ($pageKey);

        return $found;
    }

    /**
     * Facturas con registro cuyo periodo de imputación es el mes pedido, con su
     * último registro (el que manda: alta, subsanación o anulación).
     *
     * @return array<string, array{last: FiscalRecord}>
     */
    private function localInvoices(int $year, int $month): array
    {
        $result = [];
        $records = FiscalRecord::query()->with(['state', 'ticket'])->orderBy('id')->get();
        foreach ($records as $record) {
            $ticket = $record->ticket;
            $imputation = Carbon::parse($ticket->operation_date ?? $ticket->issued_at)->setTimezone('Europe/Madrid');
            if ((int) $imputation->format('Y') !== $year || (int) $imputation->format('n') !== $month) {
                continue;
            }
            $result[$record->issuer_nif.'|'.$record->invoice_number.'|'.$record->issue_date] = ['last' => $record];
        }

        return $result;
    }
}
