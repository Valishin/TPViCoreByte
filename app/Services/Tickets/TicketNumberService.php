<?php

namespace App\Services\Tickets;

use App\Models\TicketSeries;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Numeración correlativa sin huecos por serie.
 *
 * Bloquea la fila de la serie (SELECT ... FOR UPDATE) e incrementa su
 * contador dentro de la transacción de emisión: si la emisión falla, el
 * rollback deshace también el contador, así que no quedan huecos; y dos
 * emisiones simultáneas se serializan, así que no hay duplicados. La clave
 * única (ticket_series_id, number) es la red de seguridad.
 */
class TicketNumberService
{
    public function next(TicketSeries|int $series): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TicketNumberService::next() debe llamarse dentro de la transacción que crea el ticket.');
        }

        $id = $series instanceof TicketSeries ? $series->getKey() : $series;

        $locked = TicketSeries::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (! $locked->is_active) {
            throw new LogicException("La serie {$locked->code} no está activa.");
        }

        $locked->last_number++;
        $locked->save();

        return $locked->last_number;
    }
}
