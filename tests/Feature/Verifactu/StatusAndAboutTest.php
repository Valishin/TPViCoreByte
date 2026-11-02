<?php

namespace Tests\Feature\Verifactu;

use App\Enums\OrderType;
use App\Enums\UserRole;
use App\Filament\Pages\Verifactu as VerifactuPage;
use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Models\User;
use App\Models\VerifactuChain;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\TicketIssuer;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\Verifactu;
use App\Services\Verifactu\VerifactuStatus;
use App\Support\VerifactuAlarm;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Pos\InvoiceTestCase;

/** Avisos persistentes (OM 16.4, 6.f, 7.j), «Acerca de» con la declaración responsable (OM 15.3) y renuncia (OM 17). */
class StatusAndAboutTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['tpv.install_mode' => 'cliente']);   // VERI*FACTU solo en instalaciones reales
        Queue::fake();
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL']);
        VerifactuAlarm::clear();
    }

    private function issue(): void
    {
        $order = app(OrderService::class)->open(OrderType::Bar, $this->waiter);
        app(OrderLineBuilder::class)->addProduct($order, $this->pizza);
        app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession());
    }

    private function texts(): array
    {
        return array_column(VerifactuStatus::notices(), 'text');
    }

    public function test_warns_without_certificate_and_with_pending_records(): void
    {
        $this->openCash();
        $this->issue();

        $texts = implode("\n", $this->texts());
        $this->assertStringContainsString('activo pero no hay certificado', $texts);
        $this->assertStringContainsString('faltan 1 registros por enviar', $texts);
        $this->assertSame(1, VerifactuStatus::summary()['pending']);
    }

    public function test_incident_pending_becomes_danger_after_the_configured_hours(): void
    {
        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674']), 'secreto');
        $this->openCash();
        $this->issue();
        VerifactuChain::head()->update(['incident_since' => now()]);

        $notice = collect(VerifactuStatus::notices())->first(fn ($n) => str_contains($n['text'], 'por enviar'));
        $this->assertSame('warning', $notice['level']);
        $this->assertStringContainsString('sin conexión con la AEAT', $notice['text']);

        $this->travel((int) config('verifactu.pending_alarm_hours') + 1)->hours();
        $notice = collect(VerifactuStatus::notices())->first(fn ($n) => str_contains($n['text'], 'por enviar'));
        $this->assertSame('danger', $notice['level']);
    }

    public function test_rejected_records_alarms_and_certificate_expiry(): void
    {
        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674'], days: 20), 'secreto');
        $this->openCash();
        $this->issue();
        FiscalRecordState::query()->update(['status' => FiscalRecordState::REJECTED, 'needs_correction' => true]);
        VerifactuAlarm::raise('Cadena de registros rota', VerifactuAlarm::CHAIN);

        $notices = VerifactuStatus::notices();
        $this->assertSame('danger', $notices[0]['level']);
        $texts = implode("\n", array_column($notices, 'text'));
        $this->assertStringContainsString('Cadena de registros rota', $texts);
        $this->assertStringContainsString('1 registros rechazados', $texts);
        $this->assertMatchesRegularExpression('/caduca en (19|20) días/', $texts);
    }

    public function test_end_date_notice(): void
    {
        Setting::current()->update(['verifactu_end_date' => now('Europe/Madrid')->endOfYear()->toDateString()]);
        $this->assertStringContainsString('Renuncia a VERI*FACTU comunicada', implode("\n", $this->texts()));
    }

    public function test_renunciation_keeps_verifactu_until_the_end_date(): void
    {
        $settings = Setting::current();
        $settings->update(['verifactu_end_date' => now()->addDay()->toDateString()]);
        $this->assertTrue(Verifactu::active($settings->fresh()));
        $this->travel(2)->days();
        $this->assertFalse(Verifactu::active($settings->fresh()));
    }

    public function test_pos_endpoints_return_notices_and_the_declaration(): void
    {
        $this->asWaiter()->getJson('/api/verifactu/status')->assertOk()
            ->assertJsonPath('notices.0.level', 'danger');

        $about = $this->asWaiter()->getJson('/api/about')->assertOk();
        $about->assertJsonPath('obliged.nif', 'B12345674')
            ->assertJsonPath('system.name', 'iCOREBYTE TPV')
            ->assertJsonPath('system.id', 'IC')
            ->assertJsonPath('system.only_verifactu', true)
            ->assertJsonPath('system.producer', 'Alex Valishin Abubekirov')
            ->assertJsonPath('system.producer_nif', '49298273G')
            ->assertJsonPath('system.producer_address', 'Carretera de Palamós 57, bajos. 17220 Sant Feliu de Guíxols (Girona). España')
            ->assertJsonPath('support.phone', '678 758 210')
            ->assertJsonPath('support.email', 'info@icorebyte.com')
            ->assertJsonPath('support.web', 'icorebyte.com');
        $html = $about->json('declaration_html');
        $this->assertStringContainsString('49298273G', $html);
        $this->assertStringNotContainsString('{{version}}', $html);
        $this->assertStringContainsString(Verifactu::system()['version'], $html);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/about')->assertUnauthorized();
    }

    public function test_admin_records_table_shows_detail_and_retries_send_errors(): void
    {
        $this->openCash();
        $this->issue();
        $record = FiscalRecord::query()->sole();
        $record->state->update(['status' => FiscalRecordState::SEND_ERROR, 'error_message' => 'Fallo']);
        $this->actingAs(User::factory()->role(UserRole::Admin)->create());
        Filament::setCurrentPanel('admin');

        Livewire::test(VerifactuPage::class)
            ->assertCanSeeTableRecords([$record])
            ->assertTableActionVisible('retry', $record)
            ->assertTableActionHidden('correct', $record)
            ->callTableAction('detail', $record)->assertHasNoErrors()
            ->callTableAction('retry', $record);
        $this->assertSame(FiscalRecordState::PENDING, $record->state()->first()->status);
    }

    /** Soporte técnico abajo en la entrada de la TPV (meta de la página) y en el login de /admin. */
    public function test_support_contact_on_both_login_pages(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/entrar')->assertOk()
            ->assertSee('name="tpv-support"', false)->assertSee('info@icorebyte.com', false);
        $this->get('/admin/login')->assertOk()
            ->assertSee('Soporte técnico · iCOREBYTE')
            ->assertSee('tel:+34678758210', false)->assertSee('https://wa.me/34678758210', false)
            ->assertSee('https://icorebyte.com', false)->assertSee('mailto:info@icorebyte.com', false);
    }
}
