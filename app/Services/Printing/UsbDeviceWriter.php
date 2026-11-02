<?php

namespace App\Services\Printing;

/**
 * Escribe los bytes ESC/POS en una impresora USB (/dev/usb/impresora-…) sin
 * bloquear nunca: una impresora apagada, sin papel o con la tapa abierta
 * podría dejar la escritura colgada para siempre.
 *
 * - El dispositivo se abre en modo no bloqueante y se escribe por trozos con
 *   stream_select. No depende de pcntl: funciona igual en el worker de colas
 *   que en una petición web.
 * - Plazos: «sin respuesta» = $idleTimeout segundos sin que la impresora acepte
 *   nada (se reinicia con cada trozo aceptado: un ticket largo con el QR en
 *   imagen tarda más de 3 s y no por eso está atascado) y, además, un máximo
 *   total ($maxSeconds, por debajo del timeout del trabajo de la cola).
 * - Antes de cerrar se espera a que el driver termine de enviar el último
 *   trozo. En modo no bloqueante, usblp devuelve write() con el último trozo
 *   todavía en camino y, al cerrar, cancela lo que no se ha transferido
 *   (usblp_release → usblp_unlink_urbs): se perdían el final del ticket y el
 *   corte. usblp marca el dispositivo como escribible (poll POLLOUT) cuando ha
 *   terminado ese envío.
 *
 * No se usa SIGALRM: con el buffer del dispositivo lleno, write() devuelve
 * escrituras parciales en lugar de EINTR y PHP reintenta dentro de C, así que
 * el manejador de la alarma no llega a ejecutarse y la escritura se queda
 * colgada.
 */
class UsbDeviceWriter
{
    private const CHUNK = 8192;   // = USBLP_BUF_SIZE del driver

    public function write(string $path, string $bytes, int $idleTimeout, ?int $maxSeconds = null): void
    {
        if ($path === '' || ! file_exists($path)) {
            throw new PrintWriteException("Impresora no conectada: no existe el dispositivo {$path}.");
        }
        if (! is_writable($path)) {
            throw new PrintWriteException("Impresora no conectada o sin permiso de escritura en {$path} (el usuario debe estar en el grupo lp).");
        }

        $idle = max(1, $idleTimeout);
        $max = max($idle, $maxSeconds ?? (int) config('printing.usb.max_seconds', 15));
        $this->writeNonBlocking($path, $bytes, $idle, $max);
    }

    private function writeNonBlocking(string $path, string $bytes, int $idle, int $max): void
    {
        // "n" = O_NONBLOCK: ni siquiera la apertura puede quedarse esperando.
        $fp = @fopen($path, 'wbn');
        if ($fp === false) {
            throw new PrintWriteException("Impresora no conectada: no se puede abrir {$path}.");
        }

        try {
            stream_set_blocking($fp, false);
            $started = $lastProgress = microtime(true);
            $offset = 0;
            $length = strlen($bytes);

            while ($offset < $length) {
                $this->waitWritable($fp, $path, $started, $lastProgress, $idle, $max, $offset, $length);
                $written = @fwrite($fp, substr($bytes, $offset, self::CHUNK));
                if ($written === false) {
                    throw new PrintWriteException("Impresora no conectada: error al escribir en {$path}.", $offset);
                }
                if ($written > 0) {
                    $offset += $written;
                    $lastProgress = microtime(true);
                }
            }

            // El último trozo puede estar aún en camino: esperar a que el driver lo termine de enviar.
            $this->waitWritable($fp, $path, $started, $lastProgress, $idle, $max, $offset, $length);
        } finally {
            fclose($fp);
        }
    }

    /** Espera a que el dispositivo acepte datos (o termine el envío en curso) dentro de los plazos. */
    private function waitWritable($fp, string $path, float $started, float $lastProgress, int $idle, int $max, int $offset, int $length): void
    {
        $remaining = min($lastProgress + $idle, $started + $max) - microtime(true);
        if ($remaining > 0) {
            $read = $except = null;
            $write = [$fp];
            $ready = @stream_select($read, $write, $except, (int) $remaining, (int) (fmod($remaining, 1) * 1_000_000));
            if ($ready === false) {
                throw new PrintWriteException("Error al escribir en la impresora {$path}.", $offset);
            }
            if ($ready > 0) {
                return;
            }
        }
        throw new PrintWriteException($this->timeoutMessage($path, $offset, $length, microtime(true) - $started >= $max), $offset);
    }

    private function timeoutMessage(string $path, int $offset, int $length, bool $tooLong): string
    {
        $why = $tooLong ? "no ha terminado en {$this->maxLabel()}" : '¿sin papel, tapa abierta o apagada?';
        if ($offset === 0) {
            return "Impresora USB sin respuesta en {$path} ({$why}).";
        }

        return "Impresora USB sin respuesta en {$path} a mitad del ticket ({$why}): ".($offset >= $length
            ? 'puede que falte el final.'
            : 'ha salido una parte.').' Comprueba qué ha salido antes de «Reintentar».';
    }

    private function maxLabel(): string
    {
        return (int) config('printing.usb.max_seconds', 15).' s';
    }
}
