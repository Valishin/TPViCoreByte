<?php

namespace App\Services\Verifactu;

use App\Jobs\SendVerifactuRecords;
use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\VerifactuChain;
use App\Support\VerifactuAlarm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Genera los registros de facturación encadenados (RRSIF 9 a 11; OM 7, 10, 11 y 13).
 *
 * - Siempre dentro de la transacción que crea o anula el documento: si algo
 *   falla, no hay documento sin registro ni registro sin documento (FAQ 5).
 * - Bloquea la cabeza de la cadena (SELECT … FOR UPDATE): los registros se
 *   encadenan en el orden cronológico de generación (OM 7.a; FAQ 15.4).
 * - Antes de generar comprueba el registro anterior (huella y enlace) y que su
 *   fecha no sea posterior en más de un minuto (OM 7.i, voluntario en
 *   VERI*FACTU): si algo falla, alarma, pero la facturación no se interrumpe
 *   nunca (FAQ 15.2).
 */
class FiscalRecordGenerator
{
    public function __construct(private RecordBuilder $builder) {}

    /** Registro de alta del documento recién emitido (null si VERI*FACTU no está activo). */
    public function alta(Ticket $ticket, array $flags = [], ?FiscalRecord $corrects = null): ?FiscalRecord
    {
        return $this->generate($ticket, fn (Setting $s, ?FiscalRecord $previous, string $at) => $this->builder->alta($ticket, $s, $previous, $at, $flags), $corrects);
    }

    /** Registro de anulación de un documento emitido por error (RRSIF 11.1). */
    public function anulacion(Ticket $ticket, array $flags = [], ?FiscalRecord $corrects = null): ?FiscalRecord
    {
        if (! array_key_exists('without_previous_record', $flags)) {
            // Sin alta aceptada o pendiente en la cadena (documento anterior a la activación): SinRegistroPrevio = S.
            $flags['without_previous_record'] = ! FiscalRecord::query()->where('ticket_id', $ticket->id)->where('record_type', FiscalRecord::ALTA)->exists();
        }

        return $this->generate($ticket, fn (Setting $s, ?FiscalRecord $previous, string $at) => $this->builder->anulacion($ticket, $s, $previous, $at, $flags), $corrects);
    }

    private function generate(Ticket $ticket, callable $build, ?FiscalRecord $corrects): ?FiscalRecord
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('El registro de facturación se genera en la transacción del documento.');
        }
        $settings = Setting::current();
        if (! Verifactu::active($settings)) {
            return null;
        }

        $head = VerifactuChain::query()->whereKey(1)->lockForUpdate()->first() ?? VerifactuChain::head();
        $previous = $head->last_record_id ? FiscalRecord::query()->find($head->last_record_id) : null;
        $generatedAt = Verifactu::timestamp(now());
        $this->checkPrevious($previous);

        ['xml' => $xml, 'hash' => $hash, 'columns' => $columns] = $build($settings, $previous, $generatedAt);

        $record = FiscalRecord::create($columns + [
            'ticket_id' => $ticket->id,
            'is_first' => $previous === null,
            'previous_record_id' => $previous?->id,
            'previous_hash' => $previous?->hash,
            'generated_at' => $generatedAt,
            'hash' => $hash,
            'corrects_record_id' => $corrects?->id,
            'xml' => $xml,
        ]);
        // Generado durante una incidencia de envío: irá con Incidencia = S (OM 16.4).
        FiscalRecordState::create(['fiscal_record_id' => $record->id, 'status' => FiscalRecordState::PENDING, 'incident' => $head->incident_since !== null]);
        $head->update(['last_record_id' => $record->id]);
        // Se intenta enviar en cuanto se confirme la transacción (respetando el control de flujo).
        DB::afterCommit(fn () => SendVerifactuRecords::dispatch()->onQueue('verifactu'));

        return $record;
    }

    /** OM 7.i: el último registro está bien encadenado y su fecha no es posterior en más de un minuto. */
    private function checkPrevious(?FiscalRecord $previous): void
    {
        if (! $previous) {
            return;
        }
        $problems = ChainVerifier::checkRecord($previous);
        if (now()->addMinute()->lessThan(Carbon::parse($previous->generated_at))) {
            $problems[] = "El registro anterior ({$previous->invoice_number}) tiene fecha {$previous->generated_at}, posterior a la hora actual: revisa la hora del equipo.";
        }
        if ($problems) {
            VerifactuAlarm::raise(implode(' ', $problems));
        }
    }
}
