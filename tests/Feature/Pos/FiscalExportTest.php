<?php

namespace Tests\Feature\Pos;

use App\Enums\UserRole;
use App\Filament\Pages\FiscalExport;
use App\Filament\Resources\Tickets\Pages\ViewTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Cash\CashSessionService;
use App\Services\Fiscal\FiscalExportService;
use App\Services\Pos\CheckoutService;
use App\Services\Tickets\RectificationService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use ZipArchive;

class FiscalExportTest extends PosTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->role(UserRole::Admin)->withPin('0000')->create();
        $this->openCash(10000);
        $a = $this->checkout($this->orderWith([[$this->product('Caña', 250, $this->drinks), 2]]), [['method' => 'efectivo', 'amount' => 500]])->json('data.ticket.id');
        $this->checkout($this->orderWith([[$this->product('Cena', 1810), 1]]), [['method' => 'tarjeta', 'amount' => 1810]]);
        app(RectificationService::class)->byDifferences(Ticket::find($a), $this->admin, 'Error');
        app(CashSessionService::class)->close(CheckoutService::openSession(), $this->admin, 10000);
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

    public function test_export_zip_contains_csv_and_pdf(): void
    {
        $files = $this->unzip(app(FiscalExportService::class)->export(today()->subDay(), today()));

        $this->assertEqualsCanonicalizing(
            ['tickets.csv', 'rectificativos.csv', 'facturas.csv', 'lineas.csv', 'desglose_iva.csv', 'cierres_z.csv', 'tickets.pdf', 'cierres_z.pdf', 'LEEME.txt'],
            array_keys($files),
        );

        $tickets = array_map(fn ($r) => str_getcsv($r, ';', '"', ''), array_filter(explode("\n", ltrim($files['tickets.csv'], "\u{FEFF}"))));
        $this->assertCount(3, $tickets); // cabecera + 2 tickets
        $this->assertSame(['Número completo', 'Serie', 'Año', 'Número'], array_slice($tickets[0], 0, 4));
        $this->assertSame($this->docNumber($tickets[2][1], 2), $tickets[2][0]); // número completo = serie-AA-número
        $this->assertSame('18,10', $tickets[2][11]);

        $this->assertStringContainsString('-5,00', $files['rectificativos.csv']);
        $this->assertStringContainsString('Error', $files['rectificativos.csv']);
        $this->assertStringContainsString(';1;', $files['cierres_z.csv']);
        $this->assertStringStartsWith('%PDF', $files['tickets.pdf']);
        $this->assertStringStartsWith('%PDF', $files['cierres_z.pdf']);
        // Subconjunto de fuentes: sin él cada PDF lleva ~900 KB de DejaVu.
        $this->assertLessThan(150_000, strlen($files['tickets.pdf']));
        $this->assertLessThan(150_000, strlen($files['cierres_z.pdf']));
    }

    public function test_artisan_command_and_filament_page(): void
    {
        $this->assertSame(0, Artisan::call('tpv:export-fiscal', ['--from' => today()->toDateString()]));
        $this->assertStringContainsString('.zip', Artisan::output());

        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel('admin');
        Livewire::test(FiscalExport::class)->call('export')->assertFileDownloaded();
    }

    public function test_manager_cannot_export(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Manager)->create(), 'web');

        $this->get('/admin/exportacion-fiscal')->assertForbidden();
    }

    public function test_filament_substitution_rectification(): void
    {
        $this->openCash();
        $ticket = Ticket::find($this->checkout($this->orderWith([[$this->product('Tapa', 400), 3]]), [['method' => 'tarjeta', 'amount' => 1200]])->json('data.ticket.id'));
        $line = $ticket->lines()->first();
        $this->actingAs($this->admin, 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(ViewTicket::class, ['record' => $ticket->id])
            ->callAction('substitute', data: ['reason' => 'Sobraba una', 'refund_method' => 'tarjeta', 'lines' => [$line->id => 1]])
            ->assertHasNoActionErrors();

        $r = Ticket::query()->where('rectified_ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame(800, $r->total);
        $this->assertSame('rectificado', $ticket->fresh()->status->value);
    }
}
