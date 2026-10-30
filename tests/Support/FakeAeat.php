<?php

namespace Tests\Support;

use App\Models\FiscalRecord;
use App\Services\Verifactu\Verifactu;

/**
 * Respuestas simuladas del servicio RegFactuSistemaFacturacion de la AEAT, con
 * la estructura de RespuestaSuministro.xsd (los tests las validan contra él).
 */
final class FakeAeat
{
    /**
     * @param  list<array{record: FiscalRecord, status: string, code?: string, error?: string, duplicate?: array{status: string, code?: string}}>  $lines
     */
    public static function response(array $lines, string $estado = 'Correcto', int $wait = 60, ?string $csv = 'A-TEST1234567890'): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Header/><env:Body>'
            .self::body($lines, $estado, $wait, $csv).'</env:Body></env:Envelope>';
    }

    public static function body(array $lines, string $estado = 'Correcto', int $wait = 60, ?string $csv = 'A-TEST1234567890'): string
    {
        $sf = Verifactu::NS;
        $r = Verifactu::NS_RESPONSE;
        $xml = "<tikR:RespuestaRegFactuSistemaFacturacion xmlns:tikR=\"{$r}\" xmlns:tik=\"{$sf}\">";
        if ($csv) {
            $xml .= "<tikR:CSV>{$csv}</tikR:CSV><tikR:DatosPresentacion><tik:NIFPresentador>B12345674</tik:NIFPresentador><tik:TimestampPresentacion>2026-10-30T12:00:00+01:00</tik:TimestampPresentacion></tikR:DatosPresentacion>";
        }
        $xml .= '<tikR:Cabecera><tik:ObligadoEmision><tik:NombreRazon>Pizzeria Bella Napoli SL</tik:NombreRazon><tik:NIF>B12345674</tik:NIF></tik:ObligadoEmision></tikR:Cabecera>';
        $xml .= "<tikR:TiempoEsperaEnvio>{$wait}</tikR:TiempoEsperaEnvio><tikR:EstadoEnvio>{$estado}</tikR:EstadoEnvio>";
        foreach ($lines as $line) {
            /** @var FiscalRecord $rec */
            $rec = $line['record'];
            $xml .= '<tikR:RespuestaLinea><tikR:IDFactura>'
                ."<tik:IDEmisorFactura>{$rec->issuer_nif}</tik:IDEmisorFactura><tik:NumSerieFactura>{$rec->invoice_number}</tik:NumSerieFactura><tik:FechaExpedicionFactura>{$rec->issue_date}</tik:FechaExpedicionFactura>"
                .'</tikR:IDFactura><tikR:Operacion><tik:TipoOperacion>'.($rec->isAlta() ? 'Alta' : 'Anulacion').'</tik:TipoOperacion></tikR:Operacion>'
                ."<tikR:EstadoRegistro>{$line['status']}</tikR:EstadoRegistro>";
            if (isset($line['code'])) {
                $xml .= "<tikR:CodigoErrorRegistro>{$line['code']}</tikR:CodigoErrorRegistro><tikR:DescripcionErrorRegistro>".htmlspecialchars($line['error'] ?? 'Error').'</tikR:DescripcionErrorRegistro>';
            }
            if (isset($line['duplicate'])) {
                $xml .= '<tikR:RegistroDuplicado><tik:IdPeticionRegistroDuplicado>202610301200001</tik:IdPeticionRegistroDuplicado>'
                    ."<tik:EstadoRegistroDuplicado>{$line['duplicate']['status']}</tik:EstadoRegistroDuplicado>"
                    .(isset($line['duplicate']['code']) ? "<tik:CodigoErrorRegistro>{$line['duplicate']['code']}</tik:CodigoErrorRegistro>" : '')
                    .'</tikR:RegistroDuplicado>';
            }
            $xml .= '</tikR:RespuestaLinea>';
        }

        return $xml.'</tikR:RespuestaRegFactuSistemaFacturacion>';
    }

    public static function fault(string $code, string $message): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?><env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault>'
            ."<faultcode>env:{$code}</faultcode><faultstring>".htmlspecialchars($message).'</faultstring></env:Fault></env:Body></env:Envelope>';
    }
}
