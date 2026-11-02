<?php

namespace Tests\Feature\Filament;

use App\Enums\PrinterType;
use App\Filament\Pages\SetupWizard;
use App\Models\Printer;
use App\Models\Setting;
use App\Services\Verifactu\Verifactu;
use App\Support\SetupProgress;
use Livewire\Livewire;

/** Asistente «Puesta en marcha»: pasos, validaciones, guardar paso a paso y retomar. */
class SetupWizardTest extends FilamentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Instalación nueva: datos por rellenar.
        Setting::current()->update(['tax_id' => 'PENDIENTE', 'legal_name' => 'Mi local', 'address' => null, 'corporate_tax' => null, 'setup_steps' => null, 'setup_seen_at' => null]);
    }

    public function test_first_visit_to_the_panel_opens_the_wizard_once(): void
    {
        $this->get('/admin')->assertRedirect(SetupWizard::getUrl());
        $this->get(SetupWizard::getUrl())->assertOk()->assertSee('Datos de la empresa');
        $this->assertNotNull(Setting::current()->setup_seen_at);
        $this->get('/admin')->assertOk()->assertSee('Puesta en marcha: 1 de 7 pasos', false);   // VERI*FACTU no aplica en demo
    }

    public function test_company_step_validates_the_nif_and_saves_on_next(): void
    {
        $wizard = Livewire::test(SetupWizard::class)->assertWizardCurrentStep(1)
            ->fillForm(['trade_name' => 'Bella Napoli', 'legal_name' => 'Bella Napoli SL', 'tax_id' => 'B12345678',
                'address' => 'Carrer Major, 1', 'postal_code' => '17220', 'city' => 'Sant Feliu de Guíxols'])
            ->goToNextWizardStep()
            ->assertHasFormErrors(['tax_id'])
            ->assertWizardCurrentStep(1);
        $this->assertSame('PENDIENTE', Setting::current()->tax_id);

        $wizard->fillForm(['tax_id' => 'b-12345674'])->goToNextWizardStep()->assertHasNoFormErrors()->assertWizardCurrentStep(2);
        $s = Setting::current();
        $this->assertSame(['B12345674', 'Bella Napoli SL', '17220'], [$s->tax_id, $s->legal_name, $s->postal_code]);
        $this->assertTrue(SetupProgress::steps()['empresa']['complete']);
    }

    public function test_fiscal_step_sets_regime_and_obligation_date(): void
    {
        Setting::current()->update(['tax_id' => 'B12345674', 'address' => 'Carrer Major, 1']);

        Livewire::test(SetupWizard::class)->assertWizardCurrentStep(2)
            ->goToNextWizardStep()->assertHasFormErrors(['corporate_tax'])
            ->fillForm(['vat_regime_key' => '20', 'corporate_tax' => true])
            ->goToNextWizardStep()->assertHasNoFormErrors()->assertWizardCurrentStep(3);

        $s = Setting::current();
        $this->assertSame(['20', true], [$s->vat_regime_key, $s->corporate_tax]);
        $this->assertSame('2027-01-01', Verifactu::obligationDate($s)->toDateString());
    }

    public function test_resumes_at_the_first_pending_step_and_counts_progress(): void
    {
        Setting::current()->update(['tax_id' => 'B12345674', 'address' => 'Carrer Major, 1', 'corporate_tax' => false]);
        Livewire::test(SetupWizard::class)->assertWizardCurrentStep(3)
            ->fillForm(['enabled_order_types' => ['mesa', 'barra']])
            ->goToNextWizardStep()->assertHasNoFormErrors();
        $this->assertSame(['mesa', 'barra'], Setting::current()->enabled_order_types->map->value->all());

        // A medias: al volver, empieza en Impresoras.
        Livewire::test(SetupWizard::class)->assertWizardCurrentStep(4);
        Printer::factory()->create(['type' => PrinterType::Ticket]);
        Livewire::test(SetupWizard::class)->assertWizardCurrentStep(5);

        $this->assertSame(5, SetupProgress::completed());   // empresa, fiscal, pedidos, impresoras y VERI*FACTU (demo)
        $this->assertSame('5/7', SetupWizard::getNavigationBadge());
    }

    public function test_remote_access_can_be_skipped(): void
    {
        Livewire::test(SetupWizard::class)->fillForm(['skip_remote' => true])->goToWizardStep(7);
        $this->assertTrue(SetupProgress::steps()['remoto']['skipped']);
        $this->assertTrue(SetupProgress::steps()['remoto']['complete']);
    }

    public function test_demo_does_not_need_verifactu_and_client_does(): void
    {
        $this->assertTrue(SetupProgress::steps()['verifactu']['complete']);   // tests en demo
        config(['tpv.install_mode' => 'cliente']);
        $this->assertFalse(SetupProgress::steps()['verifactu']['complete']);
    }

    public function test_only_admin(): void
    {
        $this->actingAsManager();
        $this->get(SetupWizard::getUrl())->assertForbidden();
    }
}
