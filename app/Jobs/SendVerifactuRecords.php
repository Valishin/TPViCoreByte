<?php

namespace App\Jobs;

use App\Services\Verifactu\VerifactuSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envía el siguiente lote de registros pendientes (cola «verifactu»; control de flujo en VerifactuSender). */
class SendVerifactuRecords implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 60;

    public function handle(VerifactuSender $sender): void
    {
        $sender->run();
    }
}
