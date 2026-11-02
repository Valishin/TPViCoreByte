<?php

namespace App\Services\Printing;

use RuntimeException;

/**
 * Fallo al escribir en una impresora, con los bytes que ya habían llegado:
 * si llegó algo, el ticket puede haber salido a medias y no se reintenta solo
 * (ProcessPrintJob); se reintenta a mano desde el aviso de impresión.
 */
class PrintWriteException extends RuntimeException
{
    public function __construct(string $message, public readonly int $bytesWritten = 0)
    {
        parent::__construct($message);
    }
}
