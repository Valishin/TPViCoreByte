<?php

namespace App\Console\Commands;

use App\Services\Verifactu\CertificateStore;
use App\Services\Verifactu\Reconciler;
use App\Support\VerifactuAlarm;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RuntimeException;

#[Signature('tpv:verifactu:reconcile {--month= : AAAA-MM (por defecto, este mes y el anterior)}')]
#[Description('Concilia el estado de los registros con la AEAT mediante el servicio de consulta (VERI*FACTU)')]
class VerifactuReconcileCommand extends Command
{
    public function handle(Reconciler $reconciler): int
    {
        if (! CertificateStore::installed()) {
            $this->warn('Sin certificado: no se puede consultar.');

            return self::SUCCESS;
        }
        $months = $this->option('month')
            ? [Carbon::createFromFormat('Y-m', (string) $this->option('month'), 'Europe/Madrid')]
            : [now('Europe/Madrid')->startOfMonth()->subMonthNoOverflow(), now('Europe/Madrid')->startOfMonth()];
        foreach ($months as $month) {
            try {
                $result = $reconciler->reconcile((int) $month->format('Y'), (int) $month->format('n'));
            } catch (RuntimeException $e) {
                $this->error($month->format('m/Y').': '.$e->getMessage());

                continue;
            }
            $this->info($month->format('m/Y').": {$result['checked']} facturas, {$result['matched']} coinciden.");
            foreach ($result['discrepancies'] as $d) {
                $this->warn('  '.$d);
            }
            if ($result['discrepancies']) {
                VerifactuAlarm::raise('Conciliación con la AEAT ('.$month->format('m/Y').'): '.count($result['discrepancies']).' discrepancias. '.$result['discrepancies'][0], VerifactuAlarm::RECONCILE);
            }
        }

        return self::SUCCESS;
    }
}
