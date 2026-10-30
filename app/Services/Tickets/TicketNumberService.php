<?php

namespace App\Services\Tickets;

use App\Models\TicketSeries;
use App\Support\DocumentNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numeración correlativa sin huecos por serie y año natural (art. 6.1.a RD
 * 1619/2012; formato y año en App\Support\DocumentNumber).
 *
 * Bloquea la fila del contador (serie, año) con SELECT ... FOR UPDATE y la
 * incrementa dentro de la transacción de emisión: si la emisión falla, el
 * rollback deshace también el contador, así que no quedan huecos; y dos
 * emisiones simultáneas se serializan, así que no hay duplicados. La clave
 * única (ticket_series_id, year, number) de tickets es la red de seguridad.
 * El primer documento del año crea su contador (INSERT ... ON CONFLICT DO
 * NOTHING: si dos llegan a la vez, uno lo crea y el otro espera al bloqueo).
 */
class TicketNumberService
{
    /** $issuedAt: la fecha de emisión que se guarda en el documento (da el año). */
    public function next(TicketSeries|int $series, ?CarbonInterface $issuedAt = null): DocumentNumber
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TicketNumberService::next() debe llamarse dentro de la transacción que crea el ticket.');
        }

        $series = $series instanceof TicketSeries ? $series : TicketSeries::query()->findOrFail($series);
        if (! $series->is_active) {
            throw new LogicException("La serie {$series->code} no está activa.");
        }
        $year = DocumentNumber::yearOf($issuedAt ?? now());

        return DocumentNumber::make($series->code, $year, self::increment('ticket_series_counters', ['ticket_series_id' => $series->id, 'year' => $year]), $series->digits ?? DocumentNumber::DEFAULT_DIGITS);
    }

    /** Siguiente número de un contador (fila bloqueada hasta el commit). */
    public static function increment(string $table, array $key): int
    {
        $now = now();
        DB::table($table)->insertOrIgnore($key + ['last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $row = DB::table($table)->where($key)->lockForUpdate()->first();
        DB::table($table)->where($key)->update(['last_number' => $row->last_number + 1, 'updated_at' => $now]);

        return $row->last_number + 1;
    }
}
