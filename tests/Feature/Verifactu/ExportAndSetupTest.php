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
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Pos\InvoiceTestCase;
use ZipArchive;

/** Registros en la exportación fiscal (RRSIF 8.2.f; OM 7.k). */
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
}
