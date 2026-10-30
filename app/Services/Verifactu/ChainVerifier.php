<?php

namespace App\Services\Verifactu;

use App\Models\FiscalRecord;
use Illuminate\Support\Carbon;

/**
 * Comprobación de la cadena de registros (OM 6.b y 6.e, 7.h; voluntario en
 * VERI*FACTU según OM 3): recalcula la huella de cada registro con los datos
 * guardados y comprueba el enlace con el anterior y el orden de las fechas.
 */
final class ChainVerifier
{
    /** Huella recalculada a partir de las columnas del registro (las mismas que van en el XML). */
    public static function recompute(FiscalRecord $record): string
    {
        return $record->isAlta()
            ? Fingerprint::alta([
                'IDEmisorFactura' => $record->issuer_nif, 'NumSerieFactura' => $record->invoice_number,
                'FechaExpedicionFactura' => $record->issue_date, 'TipoFactura' => $record->invoice_type,
                'CuotaTotal' => Verifactu::amount((int) $record->tax_total), 'ImporteTotal' => Verifactu::amount((int) $record->amount_total),
                'Huella' => $record->previous_hash, 'FechaHoraHusoGenRegistro' => $record->generated_at,
            ])
            : Fingerprint::anulacion([
                'IDEmisorFacturaAnulada' => $record->issuer_nif, 'NumSerieFacturaAnulada' => $record->invoice_number,
                'FechaExpedicionFacturaAnulada' => $record->issue_date,
                'Huella' => $record->previous_hash, 'FechaHoraHusoGenRegistro' => $record->generated_at,
            ]);
    }

    /** Problemas de un registro (huella propia, coincidencia con el XML y enlace con el anterior). */
    public static function checkRecord(FiscalRecord $record, ?FiscalRecord $previous = null): array
    {
        $problems = [];
        if (self::recompute($record) !== $record->hash) {
            $problems[] = "La huella del registro {$record->id} ({$record->invoice_number}) no corresponde a sus datos.";
        }
        if (! str_contains($record->xml, '<sf:Huella>'.$record->hash.'</sf:Huella>')) {
            $problems[] = "El XML del registro {$record->id} no contiene su huella.";
        }
        $previous ??= $record->previous_record_id ? $record->previous : null;
        if ($record->is_first) {
            if ($record->previous_record_id || $record->previous_hash) {
                $problems[] = "El registro {$record->id} es el primero pero apunta a un anterior.";
            }
        } elseif (! $previous) {
            $problems[] = "Falta el registro anterior del registro {$record->id}.";
        } else {
            if ($previous->hash !== $record->previous_hash) {
                $problems[] = "El registro {$record->id} no enlaza con la huella del registro anterior ({$previous->id}).";
            }
            if (strcmp(self::sortable($previous->generated_at), self::sortable($record->generated_at)) > 0) {
                $problems[] = "El registro {$record->id} tiene fecha anterior a la del registro anterior ({$previous->id}).";
            }
        }

        return $problems;
    }

    /**
     * Recorre toda la cadena desde el primer registro.
     *
     * @return array{count: int, problems: list<string>}
     */
    public static function verify(): array
    {
        $problems = [];
        $count = 0;
        $byPrevious = FiscalRecord::query()->orderBy('id')->get()->keyBy(fn (FiscalRecord $r) => $r->previous_record_id ?? 0);
        $all = $byPrevious->count();
        $current = $byPrevious->get(0);
        $previous = null;
        if (! $current && $all > 0) {
            $problems[] = 'No hay primer registro de la cadena.';
        }
        while ($current) {
            $count++;
            array_push($problems, ...self::checkRecord($current, $previous));
            $previous = $current;
            $current = $byPrevious->get($current->id);
        }
        if ($count !== $all) {
            $problems[] = ($all - $count).' registros no están en la cadena.';
        }

        return ['count' => $count, 'problems' => $problems];
    }

    private static function sortable(string $iso): string
    {
        return Carbon::parse($iso)->utc()->format('Y-m-d H:i:s');
    }
}
