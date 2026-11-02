<?php

namespace App\Support;

use App\Enums\PrinterType;
use App\Models\BackupRun;
use App\Models\Printer;
use App\Models\Setting;
use App\Services\Verifactu\EmissionGuard;
use App\Services\Verifactu\Verifactu;

/**
 * Pasos de la «Puesta en marcha» y si están completos. Se calcula con los
 * datos guardados (no con marcas), salvo los que solo se confirman (tipos de
 * pedido) o se pueden omitir (Tailscale): settings.setup_steps.
 */
final class SetupProgress
{
    public const STEPS = [
        'empresa' => 'Datos de la empresa',
        'fiscal' => 'Fiscal',
        'pedidos' => 'Tipos de pedido',
        'impresoras' => 'Impresoras',
        'copias' => 'Copias de seguridad',
        'remoto' => 'Acceso remoto (opcional)',
        'verifactu' => 'Certificado y VERI*FACTU',
    ];

    /** @return array<string, array{label: string, complete: bool, skipped: bool}> */
    public static function steps(?Setting $s = null): array
    {
        $s ??= Setting::current();
        $marks = $s->setup_steps ?? [];
        $out = [];
        foreach (self::STEPS as $key => $label) {
            $skipped = ($marks[$key] ?? null) === 'skipped';
            $out[$key] = ['label' => $label, 'complete' => $skipped || self::complete($key, $s, $marks), 'skipped' => $skipped];
        }

        return $out;
    }

    public static function completed(?Setting $s = null): int
    {
        return count(array_filter(self::steps($s), fn ($step) => $step['complete']));
    }

    public static function total(): int
    {
        return count(self::STEPS);
    }

    public static function done(?Setting $s = null): bool
    {
        return self::completed($s) === self::total();
    }

    /** Índice (1…7) del primer paso pendiente, para retomar. */
    public static function firstPending(?Setting $s = null): int
    {
        $i = 1;
        foreach (self::steps($s) as $step) {
            if (! $step['complete']) {
                return $i;
            }
            $i++;
        }

        return 1;
    }

    public static function mark(string $key, string $value = 'done'): void
    {
        $s = Setting::current();
        $s->update(['setup_steps' => [...($s->setup_steps ?? []), $key => $value]]);
    }

    private static function complete(string $key, Setting $s, array $marks): bool
    {
        return match ($key) {
            'empresa' => FiscalSetup::missingCompany($s) === [],
            'fiscal' => FiscalSetup::missingFiscal($s) === [],
            'pedidos' => ($marks['pedidos'] ?? null) === 'done' && ! empty($s->enabled_order_types),
            'impresoras' => Printer::query()->where('type', PrinterType::Ticket)->where('is_active', true)->exists(),
            'copias' => filled(config('backup.age_recipient')) && filled(config('backup.remote'))
                && BackupRun::query()->where('remote_status', 'subida')->exists(),
            'remoto' => ($marks['remoto'] ?? null) === 'done',
            // En demo no aplica; en cliente: certificado válido y VERI*FACTU activo o programado.
            'verifactu' => InstallMode::isDemo()
                || (EmissionGuard::certificateValid($s) && $s->verifactu_enabled && $s->verifactu_started_at !== null),
        };
    }

    /** Fecha de obligación que resulta de la respuesta del paso 2 (para enseñarla). */
    public static function obligationLabel(?bool $corporateTax): string
    {
        return Verifactu::obligationDate((new Setting)->forceFill(['corporate_tax' => $corporateTax ?? false]))->format('d/m/Y');
    }
}
