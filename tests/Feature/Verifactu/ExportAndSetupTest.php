<?php

namespace Tests\Feature\Verifactu;

use App\Enums\OrderType;
use App\Models\FiscalRecord;
use App\Models\Setting;
use App\Services\Fiscal\FiscalExportService;
use App\Services\Orders\OrderLineBuilder;
use App\Services\Orders\OrderService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\TicketIssuer;
use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\Verifactu;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pos\InvoiceTestCase;
use ZipArchive;

/** Registros en la exportación fiscal (RRSIF 8.2.f; OM 7.k) y activación en producción desde tpv:setup (Q5). */
class ExportAndSetupTest extends InvoiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function unzip(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
        }
        $zip->close();

        return $files;
    }

    public function test_export_contains_the_records_with_their_hash_state_and_full_xml(): void
    {
        Setting::current()->update(['verifactu_enabled' => true, 'verifactu_started_at' => now()->subMinute(), 'tax_id' => 'B12345674', 'legal_name' => 'Pizzeria Bella Napoli SL']);
        $this->openCash();
        foreach ([1, 2] as $ignored) {
            $order = app(OrderService::class)->open(OrderType::Bar, $this->waiter);
            app(OrderLineBuilder::class)->addProduct($order, $this->pizza);
            app(TicketIssuer::class)->issue($order, $this->waiter, CheckoutService::openSession());
        }
        $records = FiscalRecord::query()->orderBy('id')->get();

        $files = $this->unzip(app(FiscalExportService::class)->export(today(), today()));

        $rows = array_map(fn ($l) => str_getcsv($l, ';', '"', ''), array_filter(explode("\n", trim(preg_replace('/^\xEF\xBB\xBF/', '', $files['registros_verifactu.csv'])))));
        $this->assertCount(3, $rows);
        $this->assertSame('huella', $rows[0][11]);
        $this->assertSame([$records[0]->hash, $records[1]->hash], [$rows[1][11], $rows[2][11]]);
        $this->assertSame($records[0]->hash, $rows[2][10]);   // encadenado
        $this->assertSame('pendiente', $rows[1][12]);
        $this->assertSame(2, substr_count($files['registros_verifactu.xml'], '<sf:RegistroAlta'));
        $this->assertStringContainsString('<sf:Huella>'.$records[1]->hash.'</sf:Huella>', $files['registros_verifactu.xml']);
        $this->assertStringContainsString('registros_verifactu.csv/.xml', $files['LEEME.txt']);
    }

    public function test_export_without_records_has_no_verifactu_files(): void
    {
        $files = $this->unzip(app(FiscalExportService::class)->export(today(), today()));

        $this->assertArrayNotHasKey('registros_verifactu.csv', $files);
    }

    public function test_setup_fills_fiscal_data_and_activates_verifactu_in_production_only_with_certificate(): void
    {
        Setting::query()->delete();
        $options = ['--name' => 'Bar', '--admin-email' => 'jefa@bar.cat', '--legal-name' => 'Bar Sant Pol SL', '--tax-id' => 'b12345674', '--vat-regime' => '20'];
        $this->assertSame(0, Artisan::call('tpv:setup', $options));
        $settings = Setting::current();
        $this->assertSame(['B12345674', 'Bar Sant Pol SL', '20'], [$settings->tax_id, $settings->legal_name, $settings->vat_regime_key]);
        $this->assertFalse((bool) $settings->verifactu_enabled);

        $this->assertSame(1, Artisan::call('tpv:setup', ['--admin-email' => 'jefa@bar.cat', '--verifactu' => true]));
        $this->assertStringContainsString('certificado', Artisan::output());
        $this->assertFalse((bool) Setting::current()->verifactu_enabled);

        app(CertificateStore::class)->import(CertificateTest::p12(['organizationIdentifier' => 'VATES-B12345674']), 'secreto');
        $this->assertSame(0, Artisan::call('tpv:setup', ['--admin-email' => 'jefa@bar.cat', '--verifactu' => true]));
        $settings = Setting::current();
        $this->assertTrue(Verifactu::active($settings));
        $this->assertSame('produccion', $settings->verifactu_environment);
    }

    public function test_setup_rejects_invalid_nif_and_regime_and_does_not_overwrite_admin_data(): void
    {
        $this->assertSame(1, Artisan::call('tpv:setup', ['--admin-email' => 'a@b.cat', '--tax-id' => '12345678A']));
        $this->assertSame(1, Artisan::call('tpv:setup', ['--admin-email' => 'a@b.cat', '--vat-regime' => '03']));

        $before = Setting::current()->tax_id;
        Artisan::call('tpv:setup', ['--admin-email' => 'a@b.cat', '--tax-id' => 'B12345674', '--legal-name' => 'Otra SL']);
        $this->assertSame($before, Setting::current()->tax_id);   // ya tenía datos fiscales
    }
}
