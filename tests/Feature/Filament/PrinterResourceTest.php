<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Models\Printer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

class PrinterResourceTest extends FilamentTestCase
{
    public function test_print_test_action_prints_on_that_printer(): void
    {
        Storage::fake('local');
        config(['printing.mode' => 'simulated']);
        $printer = Printer::factory()->create(['name' => 'Cocina', 'columns' => 32]);

        Livewire::test(ListPrinters::class)
            ->callAction(TestAction::make('printTest')->table($printer))
            ->assertNotified('Prueba enviada a Cocina');

        $file = collect(Storage::disk('local')->files('prints'))->first(fn ($f) => str_ends_with($f, '.txt'));
        $this->assertStringContainsString('Impresora: Cocina', Storage::disk('local')->get($file));
        $this->assertStringContainsString(str_repeat('-', 32)."\n", Storage::disk('local')->get($file));
    }

    public function test_print_test_reports_connection_errors(): void
    {
        config(['printing.mode' => 'network', 'printing.network.timeout' => 1]);
        $printer = Printer::factory()->create(['name' => 'Barra', 'host' => '127.0.0.1', 'port' => 9]);

        Livewire::test(ListPrinters::class)
            ->callAction(TestAction::make('printTest')->table($printer))
            ->assertNotified('No se ha podido imprimir en Barra');
    }
}
