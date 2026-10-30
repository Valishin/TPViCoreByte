<?php

namespace App\Console\Commands;

use App\Services\Verifactu\VerifactuSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Envía los registros pendientes respetando el control de flujo. Lo lanza el
 * planificador cada minuto (OM 16.4 exige reintentar al menos cada hora).
 */
#[Signature('tpv:verifactu:send {--all : Envía lotes hasta que no quede nada pendiente o toque esperar}')]
#[Description('Envía a la AEAT los registros de facturación pendientes (VERI*FACTU)')]
class VerifactuSendCommand extends Command
{
    public function handle(VerifactuSender $sender): int
    {
        do {
            $result = $sender->run();
            $this->line($result);
        } while ($this->option('all') && str_starts_with($result, 'Envío ') && VerifactuSender::pendingQuery()->exists() && VerifactuSender::secondsToWait() === 0);

        return self::SUCCESS;
    }
}
