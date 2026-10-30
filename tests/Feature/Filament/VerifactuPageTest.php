<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Verifactu;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Verifactu\CertificateTest;

class VerifactuPageTest extends FilamentTestCase
{
    public function test_only_admin_can_open_it(): void
    {
        $this->get('/admin/verifactu')->assertOk()->assertSee('Certificado electrónico');
        $this->actingAsManager();
        $this->get('/admin/verifactu')->assertForbidden();
    }

    public function test_admin_uploads_certificate_and_saves_environment_and_regime(): void
    {
        Setting::current()->update(['tax_id' => 'B12345674']);
        $file = UploadedFile::fake()->createWithContent('cert.p12', CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674']));

        Livewire::test(Verifactu::class)
            ->callAction('installCertificate', ['file' => $file, 'password' => 'secreto', 'mode' => 'obligado', 'kind' => 'personal'])
            ->assertNotified('Certificado instalado');

        $this->assertSame('B12345674', Setting::current()->verifactu_certificate_nif);

        Livewire::test(Verifactu::class)
            ->fillForm(['verifactu_environment' => 'produccion', 'vat_regime_key' => '20'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('produccion', Setting::current()->verifactu_environment);
        $this->assertSame('20', Setting::current()->vat_regime_key);
    }
}
