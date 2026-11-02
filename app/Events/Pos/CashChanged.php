<?php

namespace App\Events\Pos;

/** Caja: cobros, tickets (anulados, rectificados, canjes), movimientos o apertura y cierre. La pantalla Caja se recarga. */
class CashChanged extends PosEvent
{
    public function broadcastAs(): string
    {
        return 'cash.changed';
    }
}
