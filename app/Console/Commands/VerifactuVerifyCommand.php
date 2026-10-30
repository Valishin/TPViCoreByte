<?php

namespace App\Console\Commands;

use App\Services\Verifactu\ChainVerifier;
use App\Support\VerifactuAlarm;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Recalcula todas las huellas y comprueba el encadenamiento (OM 6.b, 6.e y 7.h). */
#[Signature('tpv:verifactu:verify')]
#[Description('Verifica la cadena de registros de facturación (huellas, enlaces y fechas)')]
class VerifactuVerifyCommand extends Command
{
    public function handle(): int
    {
        ['count' => $count, 'problems' => $problems] = ChainVerifier::verify();
        if ($problems) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            VerifactuAlarm::raise('Cadena de registros rota: '.$problems[0], VerifactuAlarm::CHAIN);

            return self::FAILURE;
        }
        $this->info("Cadena correcta: {$count} registros, huellas y enlaces verificados.");
        VerifactuAlarm::clear(VerifactuAlarm::CHAIN);

        return self::SUCCESS;
    }
}
