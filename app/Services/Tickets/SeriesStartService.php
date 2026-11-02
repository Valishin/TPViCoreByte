<?php

namespace App\Services\Tickets;

use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketSeries;
use App\Models\User;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Primer número de una serie en el año en curso, para continuar la numeración
 * de un sistema anterior (p. ej. empezar en T-26-001543).
 *
 * Solo cambia el contador (serie, año en curso) de ticket_series_counters:
 * last_number = primer número − 1. No toca documentos emitidos, ni el formato,
 * ni otros años (el año que viene la serie vuelve a empezar en 1), ni los
 * cierres Z. Se puede cambiar mientras no haya ningún documento de la serie en
 * el año; después queda bloqueado. Todo en una transacción con la fila del
 * contador bloqueada (la misma que bloquea TicketNumberService al emitir), así
 * que no se cruza con un cobro: o va antes del primer documento o se rechaza.
 * Cada cambio queda en el log de auditoría.
 */
class SeriesStartService
{
    public const LOCKED = 'Bloqueado: ya se han emitido documentos en esta serie';

    /**
     * Situación de la serie en el año en curso.
     *
     * @return array{year: int, issued: int, last_issued: int, next: int, next_full: string, locked: bool}
     */
    public function status(TicketSeries $series): array
    {
        $year = DocumentNumber::yearOf(now());
        $issued = $this->issued($series, $year);
        $next = $series->lastNumber($year) + 1;

        return [
            'year' => $year,
            'issued' => $issued,
            'last_issued' => $this->lastIssued($series, $year),
            'next' => $next,
            'next_full' => DocumentNumber::format($series->code, $year, $next, $series->digits ?? DocumentNumber::DEFAULT_DIGITS),
            'locked' => $issued > 0,
        ];
    }

    /** Fija el primer número de la serie para el año en curso. Devuelve el próximo número completo. */
    public function setFirstNumber(TicketSeries $series, int $first, User $by): DocumentNumber
    {
        $digits = $series->digits ?? DocumentNumber::DEFAULT_DIGITS;
        $max = 10 ** $digits - 1;
        if ($first < 1 || $first > $max) {
            throw ValidationException::withMessages(['first_number' => "El primer número va de 1 a {$max} ({$digits} cifras)."]);
        }

        return DB::transaction(function () use ($series, $first, $by, $digits) {
            $year = DocumentNumber::yearOf(now());
            $key = ['ticket_series_id' => $series->id, 'year' => $year];
            $now = now();
            DB::table('ticket_series_counters')->insertOrIgnore($key + ['last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $counter = DB::table('ticket_series_counters')->where($key)->lockForUpdate()->first();

            // Con el contador bloqueado ningún cobro puede emitir en esta serie hasta el commit.
            if ($this->issued($series, $year) > 0) {
                throw ValidationException::withMessages(['first_number' => self::LOCKED.'.']);
            }
            $lastIssued = $this->lastIssued($series, $year);
            if ($first <= $lastIssued) {
                throw ValidationException::withMessages(['first_number' => 'Tiene que ser mayor que el último emitido ('
                    .DocumentNumber::format($series->code, $year, $lastIssued, $digits).').']);
            }

            $previous = (int) $counter->last_number + 1;
            if ($previous !== $first) {
                DB::table('ticket_series_counters')->where($key)->update(['last_number' => $first - 1, 'updated_at' => $now]);
                AuditLog::record(AuditLog::SERIES_FIRST_NUMBER, $by, $series,
                    ['series' => $series->code, 'year' => $year, 'first_number' => $previous],
                    ['series' => $series->code, 'year' => $year, 'first_number' => $first]);
            }

            return DocumentNumber::make($series->code, $year, $first, $digits);
        });
    }

    /** Documentos de la serie emitidos en el año. */
    private function issued(TicketSeries $series, int $year): int
    {
        return Ticket::query()->where('ticket_series_id', $series->id)->where('year', $year)->count();
    }

    private function lastIssued(TicketSeries $series, int $year): int
    {
        return (int) Ticket::query()->where('ticket_series_id', $series->id)->where('year', $year)->max('number');
    }
}
