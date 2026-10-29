<?php

namespace Tests\Feature\Pos;

use App\Enums\PrinterConnection;
use App\Enums\PrinterStatus;
use App\Enums\PrinterType;
use App\Enums\UserRole;
use App\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Filament\Resources\Printers\Pages\ListPrinters;
use App\Jobs\PrintTestPage;
use App\Jobs\ProcessPrintJob;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Printing\PrinterManager;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use RuntimeException;

class UsbPrinterTest extends PosTestCase
{
    private string $device;

    protected function setUp(): void
    {
        parent::setUp();
        // "Dispositivo" USB de prueba: un fichero temporal donde escribe el conector.
        $this->device = tempnam(sys_get_temp_dir(), 'lp');
        config(['printing.mode' => 'real']);
    }

    protected function tearDown(): void
    {
        @chmod($this->device, 0644);
        @unlink($this->device);
        parent::tearDown();
    }

    private function usbPrinter(array $attributes = []): Printer
    {
        return Printer::factory()->create($attributes + [
            'name' => 'Tickets USB', 'type' => PrinterType::Ticket,
            'connection_type' => PrinterConnection::Usb, 'device_path' => $this->device, 'host' => null,
        ]);
    }

    public function test_network_printer_requires_host_and_port(): void
    {
        $this->expectException(ValidationException::class);
        Printer::factory()->create(['connection_type' => PrinterConnection::Network, 'host' => null]);
    }

    public function test_usb_printer_requires_absolute_device_path_but_not_host(): void
    {
        $this->assertSame($this->device, $this->usbPrinter()->address());

        foreach ([null, 'dev/usb/lp0'] as $bad) {
            try {
                $this->usbPrinter(['device_path' => $bad]);
                $this->fail("Aceptado device_path inválido: {$bad}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('device_path', $e->errors());
            }
        }
    }

    public function test_filament_form_validates_fields_by_connection(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(CreatePrinter::class)
            ->fillForm(['name' => 'Cocina', 'type' => 'cocina', 'connection_type' => 'usb', 'device_path' => null, 'columns' => 48, 'profile' => 'default'])
            ->call('create')->assertHasFormErrors(['device_path' => 'required']);

        Livewire::test(CreatePrinter::class)
            ->fillForm(['name' => 'Cocina', 'type' => 'cocina', 'connection_type' => 'red', 'host' => null, 'columns' => 48, 'profile' => 'default'])
            ->call('create')->assertHasFormErrors(['host' => 'required']);

        Livewire::test(CreatePrinter::class)
            ->fillForm(['name' => 'Cocina USB', 'type' => 'cocina', 'connection_type' => 'usb', 'device_path' => '/dev/impresora-cocina', 'columns' => 32, 'profile' => 'default'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('/dev/impresora-cocina', Printer::where('name', 'Cocina USB')->first()->device_path);
    }

    public function test_usb_connector_writes_escpos_bytes_to_the_device(): void
    {
        $result = app(PrinterManager::class)->forPrinter($this->usbPrinter())->print(PrintTestPage::ticket());

        $this->assertSame($this->device, $result['target']);
        $bytes = file_get_contents($this->device);
        $this->assertSame($result['bytes'], strlen($bytes));
        $this->assertStringStartsWith("\x1b@", $bytes);       // ESC @ (inicializar)
        $this->assertStringContainsString("\x1dV", $bytes);   // GS V (corte)
    }

    public function test_missing_device_gives_clear_error(): void
    {
        $printer = $this->usbPrinter(['device_path' => '/dev/no-existe-impresora']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Impresora no conectada: no existe el dispositivo /dev/no-existe-impresora.');
        app(PrinterManager::class)->forPrinter($printer)->print(PrintTestPage::ticket());
    }

    public function test_device_without_write_permission_gives_clear_error(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('Como root se puede escribir siempre.');
        }
        chmod($this->device, 0444);

        $this->expectExceptionMessage('sin permiso de escritura');
        app(PrinterManager::class)->forPrinter($this->usbPrinter())->print(PrintTestPage::ticket());
    }

    public function test_usb_failures_use_the_same_print_jobs_states_and_alerts(): void
    {
        Queue::fake();
        $usb = $this->usbPrinter(['device_path' => '/dev/no-existe-impresora']);
        $this->food->update(['printer_id' => $usb->id]);
        $order = $this->openTable();
        $this->addLine($order['id'], ['product_id' => $this->product('Bravas', 550)->id]);
        $this->asWaiter()->postJson("/api/orders/{$order['id']}/send")->assertOk();
        $job = PrintJob::query()->where('printer_id', $usb->id)->firstOrFail();

        try {
            app()->call([new ProcessPrintJob($job->id), 'handle']);
        } catch (RuntimeException $e) {
            (new ProcessPrintJob($job->id))->failed($e); // lo que hace la cola tras los reintentos
        }

        $this->assertSame('fallido', $job->fresh()->status->value);
        $this->assertStringContainsString('Impresora no conectada', $job->fresh()->error);
        $this->assertSame(PrinterStatus::Error, $usb->fresh()->status);
        $this->asWaiter()->getJson('/api/print-jobs/failed')->assertJsonPath('data.0.printer.name', 'Tickets USB');
    }

    public function test_filament_print_test_on_usb_printer(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create(), 'web');
        Filament::setCurrentPanel('admin');
        $ok = $this->usbPrinter();
        $missing = $this->usbPrinter(['name' => 'Cocina USB', 'device_path' => '/dev/no-existe-impresora']);

        Livewire::test(ListPrinters::class)
            ->callAction(TestAction::make('printTest')->table($ok))
            ->assertNotified("Prueba enviada a {$ok->name}");
        $this->assertGreaterThan(0, filesize($this->device));

        Livewire::test(ListPrinters::class)
            ->callAction(TestAction::make('printTest')->table($missing))
            ->assertNotified('No se ha podido imprimir en Cocina USB');
    }
}
