<?php

namespace App\Services\Verifactu;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Respuesta de la AEAT a un envío (RespuestaSuministro.xsd; Descripción
 * servicios web 5.1, 6.2.4-6.2.5 y 6.4.4).
 *
 * - SOAP Fault: «Client» = mensaje mal formado o con datos incorrectos
 *   (revisar antes de reenviar); «Server» = reenviar (apdo. 5.1).
 * - RespuestaRegFactuSistemaFacturacion: CSV, TiempoEsperaEnvio, EstadoEnvio
 *   y una RespuestaLinea por registro (EstadoRegistro, error y, si es un
 *   duplicado, RegistroDuplicado con el estado del que ya consta).
 */
final class ResponseParser
{
    public const FAULT_CLIENT = 'client';

    public const FAULT_SERVER = 'server';

    /**
     * @return array{fault: ?string, fault_message: ?string, csv: ?string, wait: ?int, status: ?string,
     *     lines: list<array{nif: string, number: string, date: string, operation: string, status: string,
     *     error_code: ?string, error: ?string, duplicate: ?array{request_id: ?string, status: ?string, error_code: ?string, error: ?string}}>}
     */
    public static function parse(string $body): array
    {
        $result = ['fault' => null, 'fault_message' => null, 'csv' => null, 'wait' => null, 'status' => null, 'lines' => []];
        $doc = new DOMDocument;
        if (trim($body) === '' || ! @$doc->loadXML($body)) {
            return ['fault' => self::FAULT_SERVER, 'fault_message' => 'Respuesta vacía o no XML'] + $result;
        }
        $xp = new DOMXPath($doc);

        $fault = $xp->query('//*[local-name()="Fault"]')->item(0);
        if ($fault instanceof DOMElement) {
            $code = (string) $xp->evaluate('string(./*[local-name()="faultcode"])', $fault);
            $message = trim((string) $xp->evaluate('string(./*[local-name()="faultstring"])', $fault));

            return ['fault' => str_contains(strtolower($code), 'client') ? self::FAULT_CLIENT : self::FAULT_SERVER, 'fault_message' => $message ?: $code] + $result;
        }

        $root = $xp->query('//*[local-name()="RespuestaRegFactuSistemaFacturacion"]')->item(0);
        if (! $root instanceof DOMElement) {
            return ['fault' => self::FAULT_SERVER, 'fault_message' => 'Respuesta sin RespuestaRegFactuSistemaFacturacion'] + $result;
        }
        $text = fn (DOMElement $ctx, string $path) => ($v = trim((string) $xp->evaluate('string('.$path.')', $ctx))) === '' ? null : $v;
        $result['csv'] = $text($root, './*[local-name()="CSV"]');
        $wait = $text($root, './*[local-name()="TiempoEsperaEnvio"]');
        $result['wait'] = $wait === null ? null : (int) $wait;
        $result['status'] = $text($root, './*[local-name()="EstadoEnvio"]');

        foreach ($xp->query('./*[local-name()="RespuestaLinea"]', $root) as $line) {
            $dup = $xp->query('./*[local-name()="RegistroDuplicado"]', $line)->item(0);
            $result['lines'][] = [
                'nif' => (string) $text($line, './*[local-name()="IDFactura"]/*[local-name()="IDEmisorFactura"]'),
                'number' => (string) $text($line, './*[local-name()="IDFactura"]/*[local-name()="NumSerieFactura"]'),
                'date' => (string) $text($line, './*[local-name()="IDFactura"]/*[local-name()="FechaExpedicionFactura"]'),
                'operation' => (string) ($text($line, './*[local-name()="Operacion"]/*[local-name()="TipoOperacion"]') ?? 'Alta'),
                'status' => (string) $text($line, './*[local-name()="EstadoRegistro"]'),
                'error_code' => $text($line, './*[local-name()="CodigoErrorRegistro"]'),
                'error' => $text($line, './*[local-name()="DescripcionErrorRegistro"]'),
                'duplicate' => $dup instanceof DOMElement ? [
                    'request_id' => $text($dup, './*[local-name()="IdPeticionRegistroDuplicado"]'),
                    'status' => $text($dup, './*[local-name()="EstadoRegistroDuplicado"]'),
                    'error_code' => $text($dup, './*[local-name()="CodigoErrorRegistro"]'),
                    'error' => $text($dup, './*[local-name()="DescripcionErrorRegistro"]'),
                ] : null,
            ];
        }

        return $result;
    }
}
