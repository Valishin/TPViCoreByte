<?php

namespace Tests\Feature\Pos;

use App\Services\Printing\UsbDeviceWriter;
use RuntimeException;
use Tests\TestCase;

/**
 * Escritura USB con límite de tiempo, sin pcntl (como en una petición web).
 * Un FIFO con un lector que nunca lee simula una impresora que no acepta datos
 * (sin papel, tapa abierta…).
 */
class UsbDeviceWriterTest extends TestCase
{
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $item) {
            is_resource($item) ? fclose($item) : @unlink($item);
        }
        parent::tearDown();
    }

    private function tempDevice(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lp');
        $this->cleanup[] = $path;

        return $path;
    }

    /** FIFO con un lector abierto que nunca lee: se llena y la escritura se bloquea. */
    private function stuckDevice(): string
    {
        $path = sys_get_temp_dir().'/lp-fifo-'.uniqid();
        posix_mkfifo($path, 0666);
        $this->cleanup[] = $path;
        $this->cleanup[] = fopen($path, 'r+'); // lector que no lee (r+ no bloquea al abrir en Linux)

        return $path;
    }

    public function test_writes_all_bytes(): void
    {
        $device = $this->tempDevice();
        $bytes = str_repeat("\x1b@Hola ñ €\n", 2000);

        app(UsbDeviceWriter::class)->write($device, $bytes, 3);

        $this->assertSame($bytes, file_get_contents($device));
    }

    public function test_stuck_printer_times_out_instead_of_blocking(): void
    {
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Necesita posix');
        }
        $device = $this->stuckDevice();
        $started = microtime(true);

        try {
            app(UsbDeviceWriter::class)->write($device, str_repeat('x', 2_000_000), 1);
            $this->fail('La escritura no se ha cortado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Impresora USB sin respuesta', $e->getMessage());
        }

        $this->assertLessThan(3, microtime(true) - $started, 'Ha tardado demasiado en cortar');
    }

    public function test_device_that_cannot_be_opened_fails_at_once(): void
    {
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Necesita posix');
        }
        // FIFO sin lector: un open() bloqueante se quedaría esperando para siempre.
        $path = sys_get_temp_dir().'/lp-fifo-'.uniqid();
        posix_mkfifo($path, 0666);
        $this->cleanup[] = $path;
        $started = microtime(true);

        try {
            app(UsbDeviceWriter::class)->write($path, 'x', 1);
            $this->fail('No ha fallado');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no se puede abrir', $e->getMessage());
        }
        $this->assertLessThan(1, microtime(true) - $started);
    }

    public function test_missing_device(): void
    {
        $this->expectExceptionMessage('Impresora no conectada: no existe el dispositivo /dev/no-existe.');
        app(UsbDeviceWriter::class)->write('/dev/no-existe', 'x', 1);
    }
}
