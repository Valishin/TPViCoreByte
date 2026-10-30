<?php

namespace App\Services\Verifactu;

use App\Enums\OrderType;
use App\Enums\RectificationMethod;
use App\Enums\TaxIdType;
use App\Models\FiscalRecord;
use App\Models\Setting;
use App\Models\Ticket;
use DOMDocument;
use DOMElement;

/**
 * XML de los registros de facturación de alta y de anulación (Orden
 * HAC/1177/2024 arts. 10 y 11; esquema SuministroInformacion.xsd tikeV1.0,
 * tipos RegistroFacturacionAltaType y RegistroFacturacionAnulacionType;
 * Diseños de registro v1.0, hojas 2 y 3). Ver docs/verifactu/PLAN.md, 2.1 y 2.2.
 *
 * El XML se genera una vez y se guarda tal cual en fiscal_records: es el que se
 * remite (y el que se vuelve a remitir si hay que repetir el envío).
 */
final class RecordBuilder
{
    private DOMDocument $doc;

    /**
     * Registro de alta de un documento.
     *
     * @param  array{correction?: bool, previous_rejection?: ?string}  $flags  Subsanacion / RechazoPrevio (L4, L17)
     * @return array{xml: string, hash: string, columns: array<string, mixed>}
     */
    public function alta(Ticket $ticket, Setting $settings, ?FiscalRecord $previous, string $generatedAt, array $flags = []): array
    {
        $ticket->loadMissing(['rectifiedTicket', 'replacedTicket', 'lines']);
        $issuerNif = self::nif($ticket->issuer['tax_id'] ?? $settings->tax_id);
        $number = $ticket->fullNumber();
        $issueDate = Verifactu::date($ticket->issued_at);
        $type = $ticket->verifactuType();
        $taxTotal = (int) $ticket->tax_amount;
        $amountTotal = (int) $ticket->total;

        $hash = Fingerprint::alta([
            'IDEmisorFactura' => $issuerNif, 'NumSerieFactura' => $number, 'FechaExpedicionFactura' => $issueDate,
            'TipoFactura' => $type, 'CuotaTotal' => Verifactu::amount($taxTotal), 'ImporteTotal' => Verifactu::amount($amountTotal),
            'Huella' => $previous?->hash, 'FechaHoraHusoGenRegistro' => $generatedAt,
        ]);

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $root = $this->el($this->doc, 'RegistroAlta');
        $this->el($root, 'IDVersion', Verifactu::SCHEMA_VERSION);
        $id = $this->el($root, 'IDFactura');
        $this->el($id, 'IDEmisorFactura', $issuerNif);
        $this->el($id, 'NumSerieFactura', $number);
        $this->el($id, 'FechaExpedicionFactura', $issueDate);
        $this->el($root, 'NombreRazonEmisor', self::text($ticket->issuer['legal_name'] ?? $settings->legal_name, 120));
        if (! empty($flags['correction'])) {
            $this->el($root, 'Subsanacion', 'S');
        }
        if (! empty($flags['previous_rejection'])) {
            $this->el($root, 'RechazoPrevio', $flags['previous_rejection']);
        }
        $this->el($root, 'TipoFactura', $type);

        // Rectificativas (R1-R5): tipo, factura rectificada e importes en sustitución (VAL 3.1.3.3, 4 y 6).
        if ($ticket->type->isRectifying()) {
            $bySubstitution = $ticket->rectification_method === RectificationMethod::Substitution;
            $this->el($root, 'TipoRectificativa', $bySubstitution ? 'S' : 'I');
            if ($original = $ticket->rectifiedTicket) {
                $this->invoiceId($this->el($this->el($root, 'FacturasRectificadas'), 'IDFacturaRectificada'), $original, $settings);
            }
            if ($bySubstitution && $original) {
                $rect = $this->el($root, 'ImporteRectificacion');
                $this->el($rect, 'BaseRectificada', Verifactu::amount((int) $original->tax_base));
                $this->el($rect, 'CuotaRectificada', Verifactu::amount((int) $original->tax_amount));
            }
        }
        // F3: facturas simplificadas sustituidas (RRSIF 10.1.h; FAQ 27).
        if ($type === 'F3' && $ticket->replacedTicket) {
            $this->invoiceId($this->el($this->el($root, 'FacturasSustituidas'), 'IDFacturaSustituida'), $ticket->replacedTicket, $settings);
        }
        if ($ticket->operation_date && Verifactu::date($ticket->operation_date) !== $issueDate) {
            $this->el($root, 'FechaOperacion', Verifactu::date($ticket->operation_date));
        }
        $this->el($root, 'DescripcionOperacion', self::description($ticket));

        // Destinatario: obligatorio en F1, F3 y R1-R4; prohibido en F2 y R5 (VAL 3.1.3.13).
        if ($ticket->recipient && ! in_array($type, ['F2', 'R5'], true)) {
            $this->recipient($this->el($this->el($root, 'Destinatarios'), 'IDDestinatario'), $ticket->recipient);
        }

        $desglose = $this->el($root, 'Desglose');
        foreach (self::breakdown($ticket) as $row) {
            $detail = $this->el($desglose, 'DetalleDesglose');
            $this->el($detail, 'Impuesto', '01');                                    // IVA (L1)
            $this->el($detail, 'ClaveRegimen', $settings->vat_regime_key ?: '01');   // L8A: 01 general, 20 simplificado
            $this->el($detail, 'CalificacionOperacion', 'S1');                       // sujeta y no exenta (L9)
            $this->el($detail, 'TipoImpositivo', self::rate($row['rate']));
            $this->el($detail, 'BaseImponibleOimporteNoSujeto', Verifactu::amount($row['base']));
            $this->el($detail, 'CuotaRepercutida', Verifactu::amount($row['tax']));
        }
        $this->el($root, 'CuotaTotal', Verifactu::amount($taxTotal));
        $this->el($root, 'ImporteTotal', Verifactu::amount($amountTotal));
        $this->chain($root, $previous);
        $this->system($root);
        $this->el($root, 'FechaHoraHusoGenRegistro', $generatedAt);
        $this->el($root, 'TipoHuella', '01');
        $this->el($root, 'Huella', $hash);

        return ['xml' => $this->doc->saveXML($root), 'hash' => $hash, 'columns' => [
            'record_type' => FiscalRecord::ALTA, 'issuer_nif' => $issuerNif, 'invoice_number' => $number, 'issue_date' => $issueDate,
            'invoice_type' => $type, 'tax_total' => $taxTotal, 'amount_total' => $amountTotal,
            'is_correction' => ! empty($flags['correction']), 'previous_rejection' => $flags['previous_rejection'] ?? null,
        ]];
    }

    /**
     * Registro de anulación (RRSIF 11; Descripción servicios web 9.2).
     *
     * @param  array{without_previous_record?: bool, previous_rejection?: ?string}  $flags
     * @return array{xml: string, hash: string, columns: array<string, mixed>}
     */
    public function anulacion(Ticket $ticket, Setting $settings, ?FiscalRecord $previous, string $generatedAt, array $flags = []): array
    {
        $issuerNif = self::nif($ticket->issuer['tax_id'] ?? $settings->tax_id);
        $number = $ticket->fullNumber();
        $issueDate = Verifactu::date($ticket->issued_at);
        $hash = Fingerprint::anulacion([
            'IDEmisorFacturaAnulada' => $issuerNif, 'NumSerieFacturaAnulada' => $number, 'FechaExpedicionFacturaAnulada' => $issueDate,
            'Huella' => $previous?->hash, 'FechaHoraHusoGenRegistro' => $generatedAt,
        ]);

        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $root = $this->el($this->doc, 'RegistroAnulacion');
        $this->el($root, 'IDVersion', Verifactu::SCHEMA_VERSION);
        $id = $this->el($root, 'IDFactura');
        $this->el($id, 'IDEmisorFacturaAnulada', $issuerNif);
        $this->el($id, 'NumSerieFacturaAnulada', $number);
        $this->el($id, 'FechaExpedicionFacturaAnulada', $issueDate);
        if (! empty($flags['without_previous_record'])) {
            $this->el($root, 'SinRegistroPrevio', 'S');   // el alta no existe en la AEAT (cuadro B del DR)
        }
        if (! empty($flags['previous_rejection'])) {
            $this->el($root, 'RechazoPrevio', $flags['previous_rejection']);
        }
        $this->chain($root, $previous);
        $this->system($root);
        $this->el($root, 'FechaHoraHusoGenRegistro', $generatedAt);
        $this->el($root, 'TipoHuella', '01');
        $this->el($root, 'Huella', $hash);

        return ['xml' => $this->doc->saveXML($root), 'hash' => $hash, 'columns' => [
            'record_type' => FiscalRecord::ANULACION, 'issuer_nif' => $issuerNif, 'invoice_number' => $number, 'issue_date' => $issueDate,
            'without_previous_record' => ! empty($flags['without_previous_record']), 'previous_rejection' => $flags['previous_rejection'] ?? null,
        ]];
    }

    /**
     * Desglose por tipo de IVA del snapshot del documento (base y cuota ya con
     * descuentos, invitaciones y gastos de envío; sin propinas: FAQ 20). Un
     * documento a cero (todo invitado) lleva una línea a cero con su tipo.
     *
     * @return list<array{rate: int, base: int, tax: int}>
     */
    public static function breakdown(Ticket $ticket): array
    {
        $rows = array_map(fn (array $v) => ['rate' => (int) $v['rate'], 'base' => (int) $v['base'], 'tax' => (int) $v['tax']], $ticket->vat_breakdown ?? []);
        if ($rows === []) {
            $rows[] = ['rate' => (int) ($ticket->lines->first()?->vat_rate ?? 1000), 'base' => 0, 'tax' => 0];
        }

        return $rows;
    }

    /** DescripcionOperacion (RRSIF 10.1.i): descripción general según el tipo de pedido. */
    public static function description(Ticket $ticket): string
    {
        return match ($ticket->order_type) {
            OrderType::Takeaway => 'Venta de comida para llevar',
            OrderType::Delivery => 'Venta de comida a domicilio',
            default => 'Servicio de restauración',
        };
    }

    /** TipoImpositivo: puntos básicos → porcentaje con 2 decimales (1000 → 10.00). */
    public static function rate(int $basisPoints): string
    {
        return intdiv($basisPoints, 100).'.'.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function nif(?string $value): string
    {
        return strtoupper(preg_replace('/[\s.\-]+/', '', (string) $value));
    }

    private function invoiceId(DOMElement $parent, Ticket $ticket, Setting $settings): void
    {
        $this->el($parent, 'IDEmisorFactura', self::nif($ticket->issuer['tax_id'] ?? $settings->tax_id));
        $this->el($parent, 'NumSerieFactura', $ticket->fullNumber());
        $this->el($parent, 'FechaExpedicionFactura', Verifactu::date($ticket->issued_at));
    }

    /**
     * Destinatario: NIF español o IDOtro (lista L7). NIF-IVA UE → 02 (sin
     * CodigoPais, VAL 3.1.3.13); pasaporte u otro documento → 03 con el país.
     */
    private function recipient(DOMElement $parent, array $recipient): void
    {
        $this->el($parent, 'NombreRazon', self::text($recipient['name'] ?? '', 120));
        $taxId = self::nif($recipient['tax_id'] ?? '');
        $type = TaxIdType::tryFrom((string) ($recipient['tax_id_type'] ?? ''));
        if (in_array($type, [TaxIdType::VatEu, TaxIdType::Other], true)) {
            $other = $this->el($parent, 'IDOtro');
            if ($type === TaxIdType::Other) {
                $this->el($other, 'CodigoPais', strtoupper($recipient['country'] ?? 'ES'));
            }
            $this->el($other, 'IDType', $type === TaxIdType::VatEu ? '02' : '03');
            $this->el($other, 'ID', mb_substr($taxId, 0, 20));

            return;
        }
        $this->el($parent, 'NIF', $taxId);
    }

    /** Encadenamiento con el registro inmediatamente anterior (OM 7.a y 7.b). */
    private function chain(DOMElement $root, ?FiscalRecord $previous): void
    {
        $chain = $this->el($root, 'Encadenamiento');
        if (! $previous) {
            $this->el($chain, 'PrimerRegistro', 'S');

            return;
        }
        $prev = $this->el($chain, 'RegistroAnterior');
        $this->el($prev, 'IDEmisorFactura', $previous->issuer_nif);
        $this->el($prev, 'NumSerieFactura', $previous->invoice_number);
        $this->el($prev, 'FechaExpedicionFactura', $previous->issue_date);
        $this->el($prev, 'Huella', $previous->hash);
    }

    private function system(DOMElement $root): void
    {
        $s = Verifactu::system();
        $si = $this->el($root, 'SistemaInformatico');
        $this->el($si, 'NombreRazon', self::text($s['producer_name'], 120));
        $this->el($si, 'NIF', $s['producer_nif']);
        $this->el($si, 'NombreSistemaInformatico', $s['name']);
        $this->el($si, 'IdSistemaInformatico', $s['id']);
        $this->el($si, 'Version', $s['version']);
        $this->el($si, 'NumeroInstalacion', $s['installation']);
        $this->el($si, 'TipoUsoPosibleSoloVerifactu', $s['only_verifactu']);
        $this->el($si, 'TipoUsoPosibleMultiOT', $s['multi_taxpayer']);
        $this->el($si, 'IndicadorMultiplesOT', $s['multiple_taxpayers']);
    }

    private function el(DOMDocument|DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $this->doc->createElementNS(Verifactu::NS, 'sf:'.$name);
        if ($value !== null) {
            $element->appendChild($this->doc->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }

    /** Texto libre sin espacios en los extremos (Descripción servicios web 6.7) y con su longitud máxima. */
    private static function text(?string $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }
}
