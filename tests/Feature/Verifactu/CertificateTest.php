<?php

namespace Tests\Feature\Verifactu;

use App\Exceptions\AeatConnectionException;
use App\Models\Setting;
use App\Services\Verifactu\AeatClient;
use App\Services\Verifactu\CertificateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class CertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSettings(['tax_id' => 'B12345674']);
    }

    /** .p12 autofirmado de prueba. $dn: serialNumber (persona) u organizationIdentifier (entidad). */
    public static function p12(array $dn, string $password = 'secreto', int $days = 365): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new($dn + ['countryName' => 'ES', 'commonName' => 'PRUEBA'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_pkcs12_export($cert, $out, $key, $password);

        return $out;
    }

    public function test_imports_encrypts_and_describes_an_entity_certificate(): void
    {
        $info = app(CertificateStore::class)->import(self::p12(['organizationIdentifier' => 'VATES-B12345674', 'organizationName' => 'PIZZERIA SL']), 'secreto');

        $this->assertSame('B12345674', $info['nif']);
        $settings = Setting::current();
        $this->assertTrue(CertificateStore::installed($settings));
        $this->assertSame('B12345674', $settings->verifactu_certificate_nif);
        $this->assertGreaterThan(360, CertificateStore::daysToExpiry($settings));
        $raw = DB::table('settings')->first();
        $this->assertStringNotContainsString('secreto', (string) $raw->verifactu_certificate_password);   // cifrada (APP_KEY)
        $this->assertNotSame($settings->verifactu_certificate, $raw->verifactu_certificate);
        $pem = CertificateStore::pem($settings);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $pem['cert']);
        $this->assertStringContainsString('PRIVATE KEY', $pem['key']);
    }

    public function test_obliged_mode_requires_the_local_nif_and_representative_mode_does_not(): void
    {
        $person = self::p12(['serialNumber' => 'IDCES-12345678Z']);
        try {
            app(CertificateStore::class)->import($person, 'secreto');
            $this->fail('El NIF no coincide con el del local.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('12345678Z', $e->getMessage());
        }

        $info = app(CertificateStore::class)->import($person, 'secreto', CertificateStore::MODE_REPRESENTATIVE);
        $this->assertSame('12345678Z', $info['nif']);
        $this->assertSame('representante', Setting::current()->verifactu_certificate_mode);
    }

    public function test_rejects_wrong_password_and_expired_certificates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CertificateStore::class)->import(self::p12(['organizationIdentifier' => 'VATES-B12345674']), 'otra');
    }

    public function test_rejects_expired_certificate(): void
    {
        $p12 = self::p12(['organizationIdentifier' => 'VATES-B12345674'], days: 1);
        $this->travel(3)->days();

        $this->expectExceptionMessage('caducado');
        app(CertificateStore::class)->import($p12, 'secreto');
    }

    public function test_converts_legacy_rc2_p12(): void
    {
        $dir = sys_get_temp_dir().'/legacy-'.getmypid();
        @mkdir($dir);
        openssl_pkcs12_read(self::p12(['organizationIdentifier' => 'VATES-B12345674']), $parts, 'secreto');
        file_put_contents("$dir/all.pem", $parts['cert'].$parts['pkey']);
        exec('openssl pkcs12 -export -legacy -in '.escapeshellarg("$dir/all.pem").' -out '.escapeshellarg("$dir/legacy.p12").' -passout pass:secreto 2>&1', $out, $code);
        if ($code !== 0) {
            $this->markTestSkipped('openssl sin proveedor legacy en este entorno.');
        }
        $legacy = file_get_contents("$dir/legacy.p12");
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);

        $this->assertFalse(@openssl_pkcs12_read($legacy, $ignored, 'secreto'));   // OpenSSL 3 no lo lee directamente
        $info = app(CertificateStore::class)->import($legacy, 'secreto');
        $this->assertSame('B12345674', $info['nif']);
        $this->assertTrue(openssl_pkcs12_read(base64_decode(Setting::current()->verifactu_certificate), $converted, 'secreto'));
    }

    public function test_client_uses_the_endpoint_of_environment_and_certificate_kind(): void
    {
        $settings = Setting::current();
        $this->assertSame('https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', AeatClient::endpoint($settings));   // pruebas por defecto
        $settings->update(['verifactu_environment' => 'produccion']);
        $this->assertSame('https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', AeatClient::endpoint($settings));
        $settings->update(['verifactu_certificate_kind' => 'sello']);
        $this->assertSame('https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP', AeatClient::endpoint($settings));
    }

    public function test_client_posts_soap_and_maps_connection_errors(): void
    {
        app(CertificateStore::class)->import(self::p12(['organizationIdentifier' => 'VATES-B12345674']), 'secreto');
        Http::fake(['*' => Http::response('<ok/>', 200)]);

        $result = app(AeatClient::class)->post('<soap/>');
        $this->assertSame(200, $result['status']);
        Http::assertSent(fn (Request $r) => $r->url() === AeatClient::endpoint() && $r->body() === '<soap/>'
            && str_starts_with($r->header('Content-Type')[0], 'text/xml'));

        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));
        $this->expectException(AeatConnectionException::class);
        app(AeatClient::class)->post('<soap/>');
    }
}
