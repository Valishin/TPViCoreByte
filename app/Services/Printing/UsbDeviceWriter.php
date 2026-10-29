<?php

namespace App\Services\Printing;

use RuntimeException;

/**
 * Escribe los bytes ESC/POS en una impresora USB (/dev/usb/lp0, /dev/impresora-…)
 * con un límite de tiempo: una impresora apagada, sin papel o con la tapa
 * abierta puede dejar la escritura bloqueada para siempre.
 *
 * El dispositivo se abre en modo no bloqueante y se escribe por trozos con
 * stream_select y un plazo. No depende de pcntl, así que funciona igual en el
 * worker de colas que en una petición web (PHP-FPM / FrankenPHP).
 *
 * No se usa SIGALRM: con el buffer del dispositivo lleno, write() devuelve
 * escrituras parciales en lugar de EINTR y PHP reintenta dentro de C, así que
 * el manejador de la alarma no llega a ejecutarse y la escritura se queda
 * colgada.
 */
class UsbDeviceWriter
{
    public function write(string $path, string $bytes, int $timeout): void
    {
        if ($path === '' || ! file_exists($path)) {
            throw new RuntimeException("Impresora no conectada: no existe el dispositivo {$path}.");
        }
        if (! is_writable($path)) {
            throw new RuntimeException("Impresora no conectada o sin permiso de escritura en {$path} (el usuario debe estar en el grupo lp).");
        }

        $this->writeNonBlocking($path, $bytes, $timeout);
    }

    private function writeNonBlocking(string $path, string $bytes, int $timeout): void
    {
        // "n" = O_NONBLOCK: ni siquiera la apertura puede quedarse esperando.
        $fp = @fopen($path, 'wbn');
        if ($fp === false) {
            throw new RuntimeException("Impresora no conectada: no se puede abrir {$path}.");
        }

        try {
            stream_set_blocking($fp, false);
            $deadline = microtime(true) + max(1, $timeout);
            $offset = 0;
            $length = strlen($bytes);

            while ($offset < $length) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new RuntimeException($this->timeoutMessage($path));
                }
                $read = $except = null;
                $write = [$fp];
                $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));
                if ($ready === false) {
                    throw new RuntimeException("Error al escribir en la impresora {$path}.");
                }
                if ($ready === 0) {
                    throw new RuntimeException($this->timeoutMessage($path));
                }

                $written = @fwrite($fp, substr($bytes, $offset, 8192));
                if ($written === false) {
                    throw new RuntimeException("Impresora no conectada: error al escribir en {$path}.");
                }
                $offset += $written;
            }
        } finally {
            fclose($fp);
        }
    }

    private function timeoutMessage(string $path): string
    {
        return "Impresora USB sin respuesta en {$path} (¿sin papel, tapa abierta o apagada?).";
    }
}
