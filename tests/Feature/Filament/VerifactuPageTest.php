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

    public function test_renouncing_before_any_send_disables_and_after_a_send_lasts_until_31_december(): void
    {
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subDay()]);
        Livewire::test(Verifactu::class)->callAction('renounce')->assertNotified('VERI*FACTU desactivado');
        $this->assertFalse((bool) Setting::current()->verifactu_enabled);

        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subDay(), 'verifactu_first_sent_at' => now()->subHour()]);
        Livewire::test(Verifactu::class)->callAction('renounce');
        $settings = Setting::current();
        $this->assertTrue((bool) $settings->verifactu_enabled);
        $this->assertSame(now('Europe/Madrid')->year.'-12-31', $settings->verifactu_end_date->toDateString());
    }

    public function test_admin_shows_persistent_notices(): void
    {
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute()]);
        $this->get('/admin')->assertOk()->assertSee('activo pero no hay certificado', false);
    }

    public function test_verify_chain_action(): void
    {
        Livewire::test(Verifactu::class)->callAction('verifyChain')->assertNotified('Cadena correcta: 0 registros');
    }

    public function test_about_page_shows_the_declaration(): void
    {
        $this->get('/admin/acerca-de')->assertOk()->assertSee('DECLARACIÓN RESPONSABLE', false)->assertSee('iCOREBYTE TPV');
    }
}
