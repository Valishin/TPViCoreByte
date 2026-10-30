<?php

namespace App\Services\Verifactu;

use App\Exceptions\AeatConnectionException;
use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Models\VerifactuChain;
use App\Models\VerifactuSubmission;
use App\Support\VerifactuAlarm;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Remisión de los registros pendientes a la AEAT (RRSIF 16; OM 16; Descripción
 * servicios web 3, 5.1, 6.4.4 y 6.4.4.1). Un solo envío a la vez (bloqueo).
 *
 * Control de flujo (OM 16.2): se envía cuando han pasado «t» segundos desde
 * el envío anterior (60 al principio; después, el TiempoEsperaEnvio de cada
 * respuesta) o cuando hay 1.000 pendientes, lo que ocurra antes. Lotes de
 * hasta 1.000 registros, del más antiguo al más reciente (OM 16.4: «respetando
 * el orden temporal de generación»).
 *
 * Incidencias (OM 16.4; Diseños de registro, campo Incidencia): sin conexión o
 * con error del servidor, los registros siguen pendientes y quedan marcados;
 * se reintenta en cuanto se puede (el planificador lo intenta cada minuto, más
 * que el «al menos una vez cada hora» exigido). Los registros retrasados por
 * la incidencia —los que fallaron y los generados mientras duraba— se envían
 * en lotes propios con Cabecera/RemisionVoluntaria/Incidencia = S.
 */
class VerifactuSender
{
    public function __construct(private AeatClient $client) {}

    /** @return string resultado legible (para el comando y los registros) */
    public function run(bool $force = false): string
    {
        $lock = Cache::lock('verifactu-send', 120);
        if (! $lock->get()) {
            return 'Ya hay un envío en curso.';
        }
        try {
            return $this->sendNextBatch($force);
        } finally {
            $lock->release();
        }
    }

    public static function pendingQuery()
    {
        return FiscalRecordState::query()->where('status', FiscalRecordState::PENDING);
    }

    /** Segundos que faltan para poder enviar (0 = ya), según el control de flujo. */
    public static function secondsToWait(?VerifactuChain $head = null): int
    {
        $head ??= VerifactuChain::head();
        if (! $head->last_sent_at || self::pendingQuery()->count() >= (int) config('verifactu.max_records_per_submission')) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($head->last_sent_at->copy()->addSeconds($head->wait_seconds), false)));
    }

    private function sendNextBatch(bool $force): string
    {
        $settings = Setting::current();
        $head = VerifactuChain::head();
        if (! self::pendingQuery()->exists()) {
            return 'No hay registros pendientes.';
        }
        if (! CertificateStore::installed($settings)) {
            return 'Sin certificado: los registros siguen pendientes.';
        }
        if (! $force && ($wait = self::secondsToWait($head)) > 0) {
            return "Control de flujo: faltan {$wait} s para el siguiente envío.";
        }

        $batch = $this->batch();
        $incident = (bool) $batch->first()->state->incident;
        $xml = SubmissionXml::soap(SubmissionXml::build($batch, $settings, [
            'incident' => $incident,
            'end_date' => $settings->verifactu_end_date?->format('d-m-Y'),   // renuncia (OM 17.3)
        ]));
        $endpoint = AeatClient::endpoint($settings);
        $submission = VerifactuSubmission::create([
            'environment' => Verifactu::environment($settings), 'endpoint' => $endpoint, 'record_count' => $batch->count(),
            'incident' => $incident, 'request_xml' => $xml, 'sent_at' => now(),
        ]);
        FiscalRecordState::query()->whereIn('fiscal_record_id', $batch->pluck('id'))->update([
            'status' => FiscalRecordState::SENT, 'last_submission_id' => $submission->id, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ]);
        $head->update(['last_sent_at' => now()]);

        try {
            $response = $this->client->post($xml, $endpoint, $settings);
        } catch (AeatConnectionException|RuntimeException $e) {
            return $this->technicalFailure($submission, $batch, $head, 'sin_respuesta', $e->getMessage());
        }
        $submission->update(['response_xml' => $response['body'], 'http_status' => $response['status'], 'received_at' => now()]);
        $parsed = ResponseParser::parse($response['body']);

        if ($parsed['fault'] === ResponseParser::FAULT_SERVER) {
            return $this->technicalFailure($submission, $batch, $head, 'soap_fault_servidor', $parsed['fault_message']);
        }
        if ($parsed['fault'] === ResponseParser::FAULT_CLIENT) {
            // Mensaje mal formado o cabecera incorrecta: no se reintenta solo (Descripción servicios web 5.1).
            $submission->update(['result' => 'soap_fault_cliente', 'error' => $parsed['fault_message']]);
            FiscalRecordState::query()->whereIn('fiscal_record_id', $batch->pluck('id'))->update([
                'status' => FiscalRecordState::SEND_ERROR, 'error_code' => null, 'error_message' => $parsed['fault_message'], 'updated_at' => now(),
            ]);
            VerifactuAlarm::raise('La AEAT ha rechazado un envío completo: '.$parsed['fault_message']);

            return 'Envío rechazado (SOAP Fault de cliente): '.$parsed['fault_message'];
        }

        return $this->applyResponse($submission, $batch, $head, $settings, $parsed);
    }

    /**
     * Pendientes más antiguos (hasta 1.000), todos con o todos sin incidencia,
     * sin dos registros de la misma factura y operación en el mismo envío.
     */
    private function batch(): Collection
    {
        $max = (int) config('verifactu.max_records_per_submission');
        $states = self::pendingQuery()->orderBy('fiscal_record_id')->limit($max)->get();
        $incident = (bool) $states->first()->incident;
        $records = FiscalRecord::query()->whereIn('id', $states->pluck('fiscal_record_id'))->with('state')->orderBy('id')->get();

        $batch = collect();
        $seen = [];
        foreach ($records as $record) {
            $key = $record->record_type.'|'.$record->issuer_nif.'|'.$record->invoice_number.'|'.$record->issue_date;
            if ((bool) $record->state->incident !== $incident || isset($seen[$key])) {
                break;   // se mantiene el orden: el resto, en el siguiente envío
            }
            $seen[$key] = true;
            $batch->push($record);
        }

        return $batch;
    }

    private function technicalFailure(VerifactuSubmission $submission, Collection $batch, VerifactuChain $head, string $result, ?string $error): string
    {
        $submission->update(['result' => $result, 'error' => $error]);
        // Siguen pendientes y quedan marcados como afectados por una incidencia (OM 16.4).
        FiscalRecordState::query()->whereIn('fiscal_record_id', $batch->pluck('id'))->update([
            'status' => FiscalRecordState::PENDING, 'incident' => true, 'error_message' => $error, 'updated_at' => now(),
        ]);
        self::pendingQuery()->update(['incident' => true]);
        $head->update(['incident_since' => $head->incident_since ?? now()]);
        Log::warning('VERI*FACTU: envío no completado; se reintentará.', ['result' => $result, 'error' => $error]);

        return "Sin envío ({$result}): {$error}. Se reintentará.";
    }

    private function applyResponse(VerifactuSubmission $submission, Collection $batch, VerifactuChain $head, Setting $settings, array $parsed): string
    {
        $submission->update(['result' => $parsed['status'], 'csv' => $parsed['csv'], 'wait_seconds' => $parsed['wait']]);
        $head->update([
            'wait_seconds' => $parsed['wait'] ?? $head->wait_seconds,
            'last_success_at' => now(),
            'incident_since' => null,
        ]);

        $byKey = $batch->keyBy(fn (FiscalRecord $r) => ($r->isAlta() ? 'Alta' : 'Anulacion').'|'.$r->issuer_nif.'|'.$r->invoice_number.'|'.$r->issue_date);
        $counts = ['ok' => 0, 'errors' => 0, 'rejected' => 0];
        $answered = [];
        foreach ($parsed['lines'] as $line) {
            $operation = str_starts_with(strtolower($line['operation']), 'anul') ? 'Anulacion' : 'Alta';
            $record = $byKey->get($operation.'|'.$line['nif'].'|'.$line['number'].'|'.$line['date']);
            if (! $record) {
                continue;
            }
            $answered[] = $record->id;
            $counts[$this->applyLine($record, $line)]++;
        }
        // Registros sin línea de respuesta: vuelven a pendientes.
        FiscalRecordState::query()->whereIn('fiscal_record_id', $batch->pluck('id')->diff($answered))
            ->update(['status' => FiscalRecordState::PENDING, 'updated_at' => now()]);

        if ($counts['ok'] + $counts['errors'] > 0 && ! $settings->verifactu_first_sent_at) {
            // RRSIF 16.5: la opción se prolonga hasta el 31/12 del año del primer envío efectivo.
            $settings->update(['verifactu_first_sent_at' => now()]);
        }

        return "Envío {$parsed['status']} (CSV {$parsed['csv']}): {$counts['ok']} correctos, {$counts['errors']} aceptados con errores, {$counts['rejected']} rechazados.";
    }

    /** @return 'ok'|'errors'|'rejected' */
    private function applyLine(FiscalRecord $record, array $line): string
    {
        $state = $record->state;
        $dup = $line['duplicate'];
        if ($line['status'] === 'Incorrecto' && $dup && in_array($dup['status'], ['Correcta', 'AceptadaConErrores'], true)) {
            // Ya constaba en la AEAT (p. ej. se perdió la respuesta de un envío anterior): se da por aceptado.
            $withErrors = $dup['status'] === 'AceptadaConErrores';
            $state->update([
                'status' => $withErrors ? FiscalRecordState::ACCEPTED_WITH_ERRORS : FiscalRecordState::ACCEPTED,
                'error_code' => $withErrors ? $dup['error_code'] : null,
                'error_message' => 'Duplicado: ya constaba en la AEAT (petición '.$dup['request_id'].').'.($withErrors ? ' '.$dup['error'] : ''),
                'needs_correction' => $withErrors && ! self::exemptFromCorrection($dup['error_code']),
                'accepted_at' => $state->accepted_at ?? now(),
            ]);

            return $withErrors ? 'errors' : 'ok';
        }
        if ($line['status'] === 'Incorrecto' && $dup && $dup['status'] === 'Anulada') {
            $state->update(['status' => FiscalRecordState::REJECTED, 'error_code' => $line['error_code'], 'error_message' => 'Duplicado de un registro ANULADO en la AEAT. '.$line['error'], 'needs_correction' => true]);
            VerifactuAlarm::raise("El registro de {$record->invoice_number} consta como anulado en la AEAT: revísalo.");

            return 'rejected';
        }

        return match ($line['status']) {
            'Correcto' => tap('ok', fn () => $state->update(['status' => FiscalRecordState::ACCEPTED, 'error_code' => null, 'error_message' => null, 'needs_correction' => false, 'accepted_at' => now()])),
            'AceptadoConErrores' => tap('errors', fn () => $state->update([
                'status' => FiscalRecordState::ACCEPTED_WITH_ERRORS, 'error_code' => $line['error_code'], 'error_message' => $line['error'],
                'needs_correction' => ! self::exemptFromCorrection($line['error_code']), 'accepted_at' => now(),
            ])),
            default => tap('rejected', fn () => $state->update([
                'status' => FiscalRecordState::REJECTED, 'error_code' => $line['error_code'], 'error_message' => $line['error'], 'needs_correction' => true,
            ])),
        };
    }

    /**
     * Errores admisibles que no hay que subsanar (Validaciones v1.2.2, 4.3.1):
     * fecha-hora de generación posterior a la de la AEAT (2004) y ClaveRegimen
     * vacía con IPSI (2009).
     */
    public static function exemptFromCorrection(?string $code): bool
    {
        return in_array($code, ['2004', '2009'], true);
    }
}
