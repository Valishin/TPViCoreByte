<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Jobs\ProcessPrintJob;
use App\Models\Printer;
use App\Models\PrintJob;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Queue;
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
        config(['printing.mode' => 'real', 'printing.network.timeout' => 1]);
        $printer = Printer::factory()->create(['name' => 'Barra', 'host' => '127.0.0.1', 'port' => 9]);

        Livewire::test(ListPrinters::class)
            ->callAction(TestAction::make('printTest')->table($printer))
            ->assertNotified('No se ha podido imprimir en Barra');
    }

    public function test_print_test_goes_through_the_printing_queue(): void
    {
        Queue::fake();
        config(['printing.web_wait_seconds' => 0.3]);
        $printer = Printer::factory()->create(['name' => 'Cocina']);
        $started = microtime(true);

        // Sin worker: la petición web no imprime ni se bloquea, espera como mucho el plazo y avisa.
        Livewire::test(ListPrinters::class)
            ->callAction(TestAction::make('printTest')->table($printer))
            ->assertNotified('Cocina: sin respuesta todavía');

        $this->assertLessThan(2, microtime(true) - $started);
        Queue::assertPushedOn('printing', ProcessPrintJob::class, fn ($job) => $job->tries === 1);
        $this->assertSame('prueba', PrintJob::latest('id')->first()->kind->value);
    }
}
