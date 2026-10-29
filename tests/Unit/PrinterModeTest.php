<?php

namespace Tests\Unit;

use App\Jobs\PrintTestPage;
use App\Services\Printing\PrinterManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class PrinterModeTest extends TestCase
{
    private function manager(string $mode): PrinterManager
    {
        return new PrinterManager(['mode' => $mode] + config('printing'));
    }

    public function test_real_and_simulated_modes(): void
    {
        $this->assertSame('real', $this->manager('real')->mode());
        $this->assertSame('simulated', $this->manager('simulated')->mode());
    }

    public function test_network_is_an_alias_of_real_with_a_single_log_warning(): void
    {
        Log::spy();

        $this->assertSame('real', $this->manager('network')->mode());
        $this->assertSame('real', $this->manager('network')->mode());

        Log::shouldHaveReceived('warning')->once()->with('PRINTER_MODE=network está obsoleto: usa PRINTER_MODE=real.');
    }

    public function test_unknown_mode_is_rejected(): void
    {
        Storage::fake('local');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('usa real o simulated');
        $this->manager('impresora')->print(PrintTestPage::ticket());
    }
}
