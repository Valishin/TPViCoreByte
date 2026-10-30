<?php

namespace App\Services\Verifactu;

use App\Models\FiscalRecord;
use App\Models\FiscalRecordState;
use App\Models\Setting;
use App\Models\VerifactuChain;
use App\Support\VerifactuAlarm;
use Illuminate\Support\Carbon;

/**
 * Estado de VERI*FACTU y avisos persistentes para la caja y /admin:
 *  - registros sin enviar por una incidencia, desde el primer momento y con
 *    cuántos faltan (OM 16.4), en alarma pasadas X horas;
 *  - registros rechazados o pendientes de subsanar;
 *  - alarmas de cadena, de envío o de conciliación (OM 6.f y 7.j);
 *  - certificado ausente, caducado o a punto de caducar (30 días);
 *  - obligación de VERI*FACTU no activo en producción (decisión Q5).
 */
final class VerifactuStatus
{
    /** @return array<string, mixed> */
    public static function summary(?Setting $settings = null): array
    {
        $settings ??= Setting::current();
        $head = VerifactuChain::head();
        $pending = FiscalRecordState::query()->whereIn('status', [FiscalRecordState::PENDING, FiscalRecordState::SENT]);
        $oldest = (clone $pending)->orderBy('fiscal_record_id')->first();

        return [
            'active' => Verifactu::active($settings),
            'enabled' => (bool) $settings->verifactu_enabled,
            'environment' => Verifactu::environment($settings),
            'started_at' => $settings->verifactu_started_at,
            'first_sent_at' => $settings->verifactu_first_sent_at,
            'end_date' => $settings->verifactu_end_date,
            'certificate' => CertificateStore::installed($settings),
            'certificate_days' => CertificateStore::daysToExpiry($settings),
            'pending' => (clone $pending)->count(),
            'oldest_pending_at' => $oldest ? Carbon::parse(FiscalRecord::query()->whereKey($oldest->fiscal_record_id)->value('generated_at')) : null,
            'incident_since' => $head->incident_since,
            'needs_attention' => FiscalRecordState::query()->where(fn ($q) => $q->where('needs_correction', true)->orWhere('status', FiscalRecordState::SEND_ERROR))->count(),
            'last_success_at' => $head->last_success_at,
            'wait_seconds' => $head->wait_seconds,
            'records' => FiscalRecord::query()->count(),
            'alarms' => VerifactuAlarm::all(),
        ];
    }

    /**
     * Avisos para mostrar (de más a menos grave).
     *
     * @return list<array{level: 'danger'|'warning', text: string}>
     */
    public static function notices(?Setting $settings = null): array
    {
        $settings ??= Setting::current();
        $s = self::summary($settings);
        $notices = [];

        foreach ($s['alarms'] as $alarm) {
            $notices[] = ['level' => 'danger', 'text' => 'VERI*FACTU: '.$alarm['message']];
        }
        if ($s['pending'] > 0 && ($s['incident_since'] || ! $s['certificate'])) {
            $hours = (int) config('verifactu.pending_alarm_hours');
            $late = $s['oldest_pending_at'] && $s['oldest_pending_at']->lt(now()->subHours($hours));
            $why = $s['certificate'] ? 'sin conexión con la AEAT desde las '.$s['incident_since']->format('H:i') : 'no hay certificado instalado';
            $notices[] = ['level' => $late ? 'danger' : 'warning',
                'text' => "VERI*FACTU: faltan {$s['pending']} registros por enviar a Hacienda ({$why}). Se reintenta automáticamente."];
        }
        if ($s['needs_attention'] > 0) {
            $notices[] = ['level' => 'danger', 'text' => "VERI*FACTU: {$s['needs_attention']} registros rechazados o con errores por subsanar. Avisa al encargado (/admin → VERI*FACTU)."];
        }
        if ($s['active'] && ! $s['certificate']) {
            $notices[] = ['level' => 'danger', 'text' => 'VERI*FACTU está activo pero no hay certificado: los registros no se pueden enviar.'];
        } elseif ($s['certificate'] && $s['certificate_days'] !== null && $s['certificate_days'] <= 30) {
            $notices[] = ['level' => $s['certificate_days'] < 0 ? 'danger' : 'warning',
                'text' => $s['certificate_days'] < 0 ? 'El certificado de VERI*FACTU ha caducado: renuévalo.' : "El certificado de VERI*FACTU caduca en {$s['certificate_days']} días: renuévalo."];
        }
        if (EmissionGuard::enforced() && ! $s['active']) {
            $date = Verifactu::obligationDate();
            if (now()->greaterThanOrEqualTo($date)) {
                $notices[] = ['level' => 'danger', 'text' => 'VERI*FACTU es obligatorio desde el '.$date->format('d/m/Y').' y no está activo: no se pueden emitir tickets ni facturas.'];
            } elseif (($days = EmissionGuard::warningDaysLeft($settings)) !== null) {
                $notices[] = ['level' => 'warning', 'text' => 'VERI*FACTU será obligatorio el '.$date->format('d/m/Y')." (faltan {$days} días) y no está activo."];
            }
        }
        if ($s['end_date']) {
            $notices[] = ['level' => 'warning', 'text' => 'Renuncia a VERI*FACTU comunicada: dejará de funcionar como VERI*FACTU después del '.$s['end_date']->format('d/m/Y').'.'];
        }

        usort($notices, fn ($a, $b) => ($a['level'] === 'danger' ? 0 : 1) <=> ($b['level'] === 'danger' ? 0 : 1));

        return $notices;
    }
}
