<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\SetupWizard;
use App\Filament\Pages\Verifactu;
use App\Models\Setting;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\Verifactu as VerifactuState;
use App\Support\SetupProgress;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Verifactu\CertificateTest;

/** Certificado opcional y activación de VERI*FACTU desde /admin: ahora o en la fecha de obligación. */
class VerifactuActivationTest extends FilamentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tpv.install_mode' => 'cliente']);
        Setting::current()->update(['tax_id' => 'B12345674', 'legal_name' => 'Bella Napoli SL', 'corporate_tax' => false]);   // obligado el 1/7/2027
        $this->travelTo(Carbon::parse('2026-11-02 10:00', 'Europe/Madrid'));
    }

    private function certificate(int $days = 400): void
    {
        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674'], days: $days), 'secreto');
    }

    public function test_confirmation_explains_the_31_december_rule(): void
    {
        Livewire::test(Verifactu::class)->mountAction('activate')
            ->assertMountedActionModalSee('NO se puede desactivar hasta el 31 de diciembre')
            ->assertMountedActionModalSee('Activar automáticamente el 01/07/2027');
    }

    public function test_activate_now_needs_a_valid_certificate(): void
    {
        Livewire::test(Verifactu::class)->callAction('activate', ['when' => 'ahora', 'environment' => 'produccion'])
            ->assertNotified('Sube antes un certificado electrónico válido y sin caducar.');
        $this->assertFalse((bool) Setting::current()->verifactu_enabled);

        $this->certificate();
        Livewire::test(Verifactu::class)->callAction('activate', ['when' => 'ahora', 'environment' => 'produccion'])
            ->assertNotified('VERI*FACTU activado');
        $s = Setting::current();
        $this->assertTrue(VerifactuState::active($s));
        $this->assertSame('produccion', $s->verifactu_environment);
        $this->assertTrue(SetupProgress::steps()['verifactu']['complete']);
    }

    public function test_automatic_activation_on_the_obligation_date(): void
    {
        Livewire::test(Verifactu::class)->callAction('activate', ['when' => 'fecha', 'environment' => 'produccion'])
            ->assertNotified('VERI*FACTU se activará el 01/07/2027');
        $s = Setting::current();
        $this->assertTrue($s->verifactu_enabled);
        $this->assertSame('2027-07-01 00:00', $s->verifactu_started_at->timezone('Europe/Madrid')->format('Y-m-d H:i'));
        $this->assertFalse(VerifactuState::active($s));
        $this->assertStringContainsString('Se activará automáticamente el 01/07/2027', SetupWizard::verifactuText());

        // Llega la fecha sin certificado: se activa, pero no se puede emitir.
        $this->travelTo(Carbon::parse('2027-07-01 09:00', 'Europe/Madrid'));
        $this->assertTrue(VerifactuState::active($s->fresh()));
        $this->assertSame('verifactu_required', EmissionGuard::blocker()['code']);

        // Con el certificado subido después, ya se emite.
        $this->certificate();
        $this->assertNull(EmissionGuard::blocker());
    }

    public function test_after_the_date_without_verifactu_issuing_is_blocked(): void
    {
        $this->certificate();
        $this->travelTo(Carbon::parse('2027-07-02', 'Europe/Madrid'));
        $this->assertStringContainsString('no está activo', EmissionGuard::blocker()['message']);

        // Pasada la fecha, «en la fecha» activa en el momento.
        Livewire::test(Verifactu::class)->callAction('activate', ['when' => 'fecha', 'environment' => 'produccion'])
            ->assertNotified('VERI*FACTU activado');
        $this->assertNull(EmissionGuard::blocker());
    }

    public function test_certificate_can_be_uploaded_later_and_shows_holder_nif_and_expiry(): void
    {
        $this->assertStringStartsWith('Sin certificado', SetupWizard::certificateText());
        $this->certificate(days: 200);
        $text = SetupWizard::certificateText();
        $this->assertStringContainsString('NIF B12345674', $text);
        $this->assertStringContainsString('válido hasta el '.now()->addDays(200)->format('d/m/Y'), $text);
    }

    public function test_demo_cannot_activate(): void
    {
        config(['tpv.install_mode' => 'demo']);
        Livewire::test(Verifactu::class)->assertActionHidden('activate')->assertSee('Apagado (demo)');
        $this->assertNull(EmissionGuard::blocker());
    }
}
