<?php

namespace App\Services\Verifactu;

use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Subsanación de un registro rechazado o aceptado con errores, cuando no
 * procede una rectificativa ni una anulación (FAQ 17.2.b; Descripción
 * servicios web 9.1.2, 9.1.3 y 9.2.2; Diseños de registro, cuadros A y B;
 * Validaciones 4.3.1). El registro original no se modifica: se genera uno nuevo
 * con la misma factura, encadenado al último.
 *
 * Alta:
 *  - rechazada (no existe en la AEAT): Subsanacion = S, RechazoPrevio = X;
 *  - aceptada (con o sin errores): Subsanacion = S;
 *  - subsanación rechazada de un alta que sí consta: Subsanacion = S, RechazoPrevio = S.
 * Anulación rechazada: RechazoPrevio = S (y SinRegistroPrevio si el alta no consta).
 *
 * Si el error es el 2001 (NIF del destinatario no censado), el nuevo registro
 * identifica al destinatario con IDOtro / IDType 07 «No censado» (Validaciones 4.3.1).
 */
class CorrectionService
{
    public function __construct(private FiscalRecordGenerator $generator) {}

    public function correct(FiscalRecord $record): FiscalRecord
    {
        return DB::transaction(function () use ($record) {
            $state = FiscalRecordState::query()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            if (! $state->needs_correction || $state->corrected_by_record_id) {
                throw new LogicException('Este registro no necesita subsanación.');
            }
            $existsInAeat = $this->existsInAeat($record);
            if ($record->isAlta()) {
                $flags = ['correction' => true];
                if ($state->status === FiscalRecordState::REJECTED) {
                    $flags['previous_rejection'] = $existsInAeat ? 'S' : 'X';
                }
                if ($state->error_code === '2001') {
                    $flags['recipient_not_censused'] = true;
                }
                $new = $this->generator->alta($record->ticket, $flags, $record);
            } else {
                $new = $this->generator->anulacion($record->ticket, ['previous_rejection' => 'S', 'without_previous_record' => ! $existsInAeat], $record);
            }
            if (! $new) {
                throw new LogicException('VERI*FACTU no está activo: no se puede generar la subsanación.');
            }
            $state->update(['needs_correction' => false, 'corrected_by_record_id' => $new->id]);

            return $new;
        });
    }

    /** ¿Consta en la AEAT algún alta aceptada de esta factura? */
    private function existsInAeat(FiscalRecord $record): bool
    {
        return FiscalRecord::query()->where('ticket_id', $record->ticket_id)->where('record_type', FiscalRecord::ALTA)
            ->whereHas('state', fn ($q) => $q->whereIn('status', [FiscalRecordState::ACCEPTED, FiscalRecordState::ACCEPTED_WITH_ERRORS]))
            ->exists();
    }
}
