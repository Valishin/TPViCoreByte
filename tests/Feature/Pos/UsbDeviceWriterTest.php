<?php

namespace Tests\Feature\Pos;

use App\Services\Printing\PrintWriteException;
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

    /**
     * Impresora lenta pero que avanza: un lector que vacía el FIFO a ratos (más
     * lento en total que el plazo «sin respuesta», pero sin pausas tan largas).
     * Devuelve [ruta, fichero donde el lector guarda lo que recibe, proceso].
     */
    private function slowDevice(int $chunk, float $pause): array
    {
        $path = sys_get_temp_dir().'/lp-lento-'.uniqid();
        posix_mkfifo($path, 0666);
        $out = tempnam(sys_get_temp_dir(), 'lp-out');
        $this->cleanup[] = $path;
        $this->cleanup[] = $out;
        $code = sprintf('$in=fopen(%s,"r");$o=fopen(%s,"w");while(!feof($in)){$d=fread($in,%d);if($d===false||$d==="")break;fwrite($o,$d);usleep(%d);}',
            var_export($path, true), var_export($out, true), $chunk, (int) ($pause * 1_000_000));
        $proc = proc_open([PHP_BINARY, '-r', $code], [], $pipes);
        usleep(200_000);   // que el lector abra el FIFO

        return [$path, $out, $proc];
    }

    public function test_slow_printer_that_keeps_accepting_finishes_the_ticket(): void
    {
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Necesita posix');
        }
        // ~260 KB a 32 KB cada 0,3 s ≈ 2 s en total: más que el plazo de 1 s sin respuesta.
        [$device, $out, $proc] = $this->slowDevice(32_768, 0.3);
        $bytes = str_repeat("\x1dv0 ráster del QR ", 12_000);
        $started = microtime(true);

        app(UsbDeviceWriter::class)->write($device, $bytes, 1, 10);
        proc_close($proc);

        $this->assertGreaterThan(1.2, microtime(true) - $started, 'La prueba debe durar más que el plazo «sin respuesta».');
        $this->assertSame($bytes, file_get_contents($out), 'Antes se cortaba al pasar 1 s y el ticket salía a medias.');
    }

    public function test_total_cap_stops_an_endless_trickle_and_says_how_far_it_got(): void
    {
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Necesita posix');
        }
        [$device, , $proc] = $this->slowDevice(4096, 0.4);   // ~10 KB/s: 2 MB tardaría minutos
        $started = microtime(true);

        try {
            app(UsbDeviceWriter::class)->write($device, str_repeat('x', 2_000_000), 1, 2);
            $this->fail('No se ha cortado');
        } catch (PrintWriteException $e) {
            $this->assertGreaterThan(0, $e->bytesWritten);
            $this->assertStringContainsString('a mitad del ticket', $e->getMessage());
            $this->assertStringContainsString('Comprueba qué ha salido antes de «Reintentar»', $e->getMessage());
        } finally {
            proc_terminate($proc);
        }
        $this->assertLessThan(3.5, microtime(true) - $started);
    }

    public function test_stuck_printer_reports_bytes_already_sent(): void
    {
        if (! function_exists('posix_mkfifo')) {
            $this->markTestSkipped('Necesita posix');
        }
        try {
            app(UsbDeviceWriter::class)->write($this->stuckDevice(), str_repeat('x', 2_000_000), 1);
            $this->fail('No se ha cortado');
        } catch (PrintWriteException $e) {
            $this->assertGreaterThan(0, $e->bytesWritten, 'El buffer del FIFO acepta los primeros KB.');
        }
    }
}
