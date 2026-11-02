<?php

namespace Tests\Feature\Verifactu;

use App\Models\Setting;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\Verifactu;
use App\Services\Verifactu\VerifactuStatus;
use App\Support\FiscalSetup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pos\InvoiceTestCase;

/**
 * Tipo de instalación y bloqueos de emisión (EmissionGuard):
 *  - demo: se vende siempre, sin avisos de VERI*FACTU;
 *  - cliente: sin datos fiscales no se emite; desde la fecha de obligación,
 *    tampoco sin certificado válido o sin VERI*FACTU activo.
 */
class EmissionGuardTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->openCash();
    }

    private function asClient(array $settings = []): Setting
    {
        config(['tpv.install_mode' => 'cliente']);
        $s = Setting::current();
        $s->update($settings + ['tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL', 'corporate_tax' => false, 'vat_regime_key' => '01']);

        return $s->fresh();
    }

    private function sell()
    {
        return $this->checkout($this->orderWith([[$this->pizza, 1]]), [['method' => 'efectivo', 'amount' => 1000]]);
    }

    private function notices(string $audience): string
    {
        return implode("\n", array_column(VerifactuStatus::notices(audience: $audience), 'text'));
    }

    public function test_demo_sells_with_example_data_and_shows_no_verifactu_notices(): void
    {
        Setting::current()->update(['tax_id' => 'PENDIENTE', 'corporate_tax' => null]);
        $this->travelTo(Carbon::parse('2027-08-01', 'Europe/Madrid'));

        $this->sell()->assertCreated();
        $this->assertNull(EmissionGuard::blocker());
        $this->assertSame('', $this->notices('admin'));
        $this->assertSame('', $this->notices('pos'));
    }

    public function test_client_without_fiscal_data_cannot_issue_and_both_screens_say_why(): void
    {
        $this->asClient(['tax_id' => 'PENDIENTE', 'address' => null, 'corporate_tax' => null]);

        $response = $this->sell()->assertStatus(423)->assertJsonPath('code', 'fiscal_data_required');
        $this->assertStringContainsString('NIF válido, dirección, si declara Sociedades', $response->json('message'));
        $this->assertStringContainsString('Puesta en marcha', $this->notices('pos'));
        $this->assertStringContainsString('faltan los datos fiscales', $this->notices('admin'));

        Setting::current()->update(['tax_id' => 'B12345674', 'address' => 'Carrer Major, 1', 'corporate_tax' => true]);
        $this->assertSame([], FiscalSetup::missing());
        $this->sell()->assertCreated();
    }

    public function test_obligation_date_follows_the_corporate_tax_answer(): void
    {
        config(['verifactu.obligation_date' => '2027-03-03']);
        $this->assertSame('2027-03-03', Verifactu::obligationDate(Setting::current()->fill(['corporate_tax' => null]))->toDateString());
        $this->assertSame('2027-01-01', Verifactu::obligationDate(Setting::current()->fill(['corporate_tax' => true]))->toDateString());
        $this->assertSame('2027-07-01', Verifactu::obligationDate(Setting::current()->fill(['corporate_tax' => false]))->toDateString());
    }

    public function test_without_certificate_admin_always_warns_and_the_till_from_30_days_before(): void
    {
        $this->asClient();   // obligado el 1/7/2027
        $this->travelTo(Carbon::parse('2027-05-01', 'Europe/Madrid'));
        $this->assertStringContainsString('Falta el certificado electrónico', $this->notices('admin'));
        $this->assertStringNotContainsString('certificado', $this->notices('pos'));
        $this->sell()->assertCreated();

        $this->travelTo(Carbon::parse('2027-06-10', 'Europe/Madrid'));
        $this->assertSame(21, EmissionGuard::warningDaysLeft());
        $this->assertStringContainsString('faltan 21 días', $this->notices('pos'));
        $this->sell()->assertCreated();   // aún se puede

        $this->travelTo(Carbon::parse('2027-07-01 08:00', 'Europe/Madrid'));
        $this->sell()->assertStatus(423)->assertJsonPath('code', 'verifactu_required')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'desde el 01/07/2027') && str_contains($m, 'certificado electrónico válido'));
        $this->assertStringContainsString('No se pueden emitir', $this->notices('pos'));
    }

    public function test_from_the_date_it_needs_verifactu_active_and_a_valid_certificate(): void
    {
        $this->asClient(['corporate_tax' => true]);   // obligado el 1/1/2027
        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674'], days: 400), 'secreto');
        $this->travelTo(Carbon::parse('2027-01-02', 'Europe/Madrid'));

        $this->assertStringContainsString('no está activo', EmissionGuard::blocker()['message']);
        $this->sell()->assertStatus(423);

        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subHour()]);
        $this->assertNull(EmissionGuard::blocker());
        $this->sell()->assertCreated();

        $this->travel(400)->days();   // certificado caducado
        $this->assertStringContainsString('certificado electrónico válido', EmissionGuard::blocker()['message']);
    }
}
