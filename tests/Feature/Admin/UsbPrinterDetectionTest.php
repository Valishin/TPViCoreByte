<?php

namespace Tests\Feature\Admin;

use App\Enums\PrinterConnection;
use App\Enums\PrinterType;
use App\Filament\Resources\Printers\Pages\CreatePrinter;
use App\Filament\Resources\Printers\Schemas\PrinterForm;
use App\Models\Printer;
use App\Services\Printing\UsbPrinterDetector;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Feature\Filament\FilamentTestCase;

/** Detección de impresoras USB leyendo /sys (simulado) y alta desde /admin. */
class UsbPrinterDetectionTest extends FilamentTestCase
{
    private string $sys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sys = sys_get_temp_dir().'/tpv-sys-'.getmypid().'-'.uniqid();
        $this->usb('lp0', '1-1', '04b8', '0202', 'EPSON', 'TM-T20III', 'X5E8-012345');
        $this->usb('lp1', '1-2', '0416', '5011', 'Winbond', 'POS-58', null);
        config(['printing.sysfs' => $this->sys]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sys);
        parent::tearDown();
    }

    /** /sys/class/usbmisc/lpN/device → …/usb1/1-1/1-1:1.0 (interfaz) bajo el dispositivo con idVendor. */
    private function usb(string $lp, string $port, string $vendor, string $product, string $maker, string $model, ?string $serial): void
    {
        $device = "{$this->sys}/devices/pci0000:00/usb1/{$port}";
        File::ensureDirectoryExists("{$device}/{$port}:1.0");
        foreach (['idVendor' => $vendor, 'idProduct' => $product, 'manufacturer' => $maker, 'product' => $model, 'serial' => $serial] as $f => $v) {
            if ($v !== null) {
                file_put_contents("{$device}/{$f}", "{$v}\n");
            }
        }
        File::ensureDirectoryExists("{$this->sys}/class/usbmisc/{$lp}");
        symlink("{$device}/{$port}:1.0", "{$this->sys}/class/usbmisc/{$lp}/device");
    }

    public function test_detects_connected_printers_with_a_stable_link_name(): void
    {
        $found = (new UsbPrinterDetector)->detect();

        $this->assertSame(['/dev/usb/impresora-0416-5011', '/dev/usb/impresora-04b8-0202-x5e8012345'], array_column($found, 'path'));
        $this->assertSame('EPSON TM-T20III', $found[1]['label']);
        $this->assertSame('impresora-04b8-0202-x5e8012345', UsbPrinterDetector::linkName('04B8', '0202', 'X5E8-012345'));
        $this->assertFalse($found[0]['configured']);

        Printer::factory()->create(['connection_type' => PrinterConnection::Usb, 'device_path' => '/dev/usb/impresora-0416-5011', 'host' => null]);
        $this->assertTrue((new UsbPrinterDetector)->detect()[0]['configured']);
    }

    public function test_no_printers_when_none_is_plugged(): void
    {
        config(['printing.sysfs' => '/no/existe']);
        $this->assertSame([], (new UsbPrinterDetector)->detect());
    }

    private function usbForm()
    {
        return Livewire::test(CreatePrinter::class)
            ->fillForm(['name' => 'Tickets', 'type' => PrinterType::Ticket->value, 'connection_type' => PrinterConnection::Usb->value]);
    }

    public function test_search_with_several_printers_lets_you_choose_and_fills_the_device(): void
    {
        $this->usbForm()
            ->callAction(TestAction::make('scanUsb')->schemaComponent('usb-scan'), ['path' => '/dev/usb/impresora-04b8-0202-x5e8012345'])
            ->assertSchemaStateSet(['device_path' => '/dev/usb/impresora-04b8-0202-x5e8012345'])
            ->call('create')->assertHasNoFormErrors();

        $this->assertSame('/dev/usb/impresora-04b8-0202-x5e8012345', Printer::query()->sole()->device_path);
    }

    public function test_search_with_one_printer_fills_it_without_asking(): void
    {
        File::deleteDirectory("{$this->sys}/class/usbmisc/lp1");
        $this->usbForm()
            ->mountAction(TestAction::make('scanUsb')->schemaComponent('usb-scan'))
            ->assertSchemaStateSet(['device_path' => '/dev/usb/impresora-04b8-0202-x5e8012345'])
            ->assertNotified('Encontrada: EPSON TM-T20III');
    }

    public function test_search_with_none_says_so(): void
    {
        config(['printing.sysfs' => '/no/existe']);
        $this->usbForm()
            ->mountAction(TestAction::make('scanUsb')->schemaComponent('usb-scan'))
            ->assertNotified('No se detecta ninguna impresora USB')
            ->assertSchemaStateSet(['device_path' => null]);
    }

    public function test_status_indicator(): void
    {
        $this->assertSame(['text' => 'Conectada: EPSON TM-T20III', 'color' => 'success'],
            array_intersect_key(PrinterForm::status('/dev/usb/impresora-04b8-0202-x5e8012345'), ['text' => 1, 'color' => 1]));
        $this->assertSame('danger', PrinterForm::status('/dev/usb/impresora-ticket')['color']);
        $this->assertNull(PrinterForm::status(null)['text']);

        $this->usbForm()->fillForm(['device_path' => '/dev/usb/impresora-0416-5011'])
            ->assertSee('Conectada: Winbond POS-58');
    }
}
