<?php

namespace App\Services\Verifactu;

use App\Exceptions\AeatConnectionException;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de los servicios web de la AEAT: SOAP 1.1 por HTTPS con
 * autenticación TLS mutua con el certificado instalado (Descripción servicios
 * web 4.3; OM 4.a-d). El certificado y su clave van a curl en memoria.
 */
class AeatClient
{
    /** Endpoint según el entorno y el tipo de certificado (WSDL: SistemaVerifactu / …Sello / …Pruebas). */
    public static function endpoint(?Setting $settings = null): string
    {
        $settings ??= Setting::current();
        $kind = $settings->verifactu_certificate_kind === CertificateStore::KIND_SEAL ? 'sello' : 'personal';

        return (string) config('verifactu.endpoints.'.Verifactu::environment($settings).'.'.$kind);
    }

    /**
     * @return array{status: int, body: string}
     *
     * @throws AeatConnectionException sin conexión, tiempo agotado o error de TLS
     */
    public function post(string $soapXml, ?string $endpoint = null, ?Setting $settings = null): array
    {
        $settings ??= Setting::current();
        $pem = CertificateStore::pem($settings);
        try {
            $response = Http::withOptions(['curl' => [
                CURLOPT_SSLCERT_BLOB => $pem['cert'],
                CURLOPT_SSLCERTTYPE => 'PEM',
                CURLOPT_SSLKEY_BLOB => $pem['key'],
                CURLOPT_SSLKEYTYPE => 'PEM',
            ]])
                ->withHeaders(['SOAPAction' => '""', 'Accept' => 'text/xml'])
                ->withBody($soapXml, 'text/xml; charset=utf-8')
                ->timeout((int) config('verifactu.timeout'))
                ->post($endpoint ?? self::endpoint($settings));
        } catch (ConnectionException $e) {
            throw new AeatConnectionException('Sin conexión con la AEAT: '.$e->getMessage(), previous: $e);
        }

        return ['status' => $response->status(), 'body' => (string) $response->body()];
    }
}
