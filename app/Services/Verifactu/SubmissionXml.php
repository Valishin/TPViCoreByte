<?php

namespace App\Services\Verifactu;

use App\Models\FiscalRecord;
use App\Models\Setting;
use DOMDocument;
use DOMElement;

/**
 * Mensaje de remisión RegFactuSistemaFacturacion (SuministroLR.xsd): Cabecera
 * + 1..1000 RegistroFactura con el XML de cada registro tal como se generó.
 * Descripción servicios web 6.2.1-6.2.2; Diseños de registro, hoja 1.
 */
final class SubmissionXml
{
    /**
     * @param  iterable<FiscalRecord>  $records
     * @param  array{incident?: bool, end_date?: ?string}  $options  Incidencia (OM 16.4) y FechaFinVeriFactu (OM 17.3)
     */
    public static function build(iterable $records, Setting $settings, array $options = []): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElementNS(Verifactu::NS_LR, 'sfLR:RegFactuSistemaFacturacion');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sf', Verifactu::NS);
        $doc->appendChild($root);

        // Cabecera es elemento local de SuministroLR (sfLR); su contenido es de SuministroInformacion (sf).
        $header = $doc->createElementNS(Verifactu::NS_LR, 'sfLR:Cabecera');
        $root->appendChild($header);
        $obliged = self::sf($doc, $header, 'ObligadoEmision');
        self::sf($doc, $obliged, 'NombreRazon', mb_substr(trim((string) $settings->legal_name), 0, 120));
        self::sf($doc, $obliged, 'NIF', RecordBuilder::nif($settings->tax_id));
        // Representante: solo en la colaboración social «transitiva» (DR hoja 1; decisión Q4: desactivado).
        if ($settings->verifactu_representative_nif) {
            $rep = self::sf($doc, $header, 'Representante');
            self::sf($doc, $rep, 'NombreRazon', mb_substr((string) $settings->verifactu_representative_name, 0, 120));
            self::sf($doc, $rep, 'NIF', RecordBuilder::nif($settings->verifactu_representative_nif));
        }
        if (! empty($options['incident']) || ! empty($options['end_date'])) {
            $voluntary = self::sf($doc, $header, 'RemisionVoluntaria');
            if (! empty($options['end_date'])) {
                self::sf($doc, $voluntary, 'FechaFinVeriFactu', $options['end_date']);
            }
            if (! empty($options['incident'])) {
                self::sf($doc, $voluntary, 'Incidencia', 'S');
            }
        }

        foreach ($records as $record) {
            $wrapper = $doc->createElementNS(Verifactu::NS_LR, 'sfLR:RegistroFactura');
            $root->appendChild($wrapper);
            $fragment = new DOMDocument;
            $fragment->loadXML($record->xml);
            $wrapper->appendChild($doc->importNode($fragment->documentElement, true));
        }

        return $doc->saveXML($root);
    }

    /** Sobre SOAP 1.1 (document/literal) con el mensaje en el Body. */
    public static function soap(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"><soapenv:Header/><soapenv:Body>'
            .$body.'</soapenv:Body></soapenv:Envelope>';
    }

    private static function sf(DOMDocument $doc, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $doc->createElementNS(Verifactu::NS, 'sf:'.$name);
        if ($value !== null) {
            $element->appendChild($doc->createTextNode($value));
        }
        $parent->appendChild($element);

        return $element;
    }
}
