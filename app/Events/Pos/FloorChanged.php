<?php

namespace App\Events\Pos;

/** Salas o mesas creadas, cambiadas o borradas (p. ej. en /admin): el plano se recarga. */
class FloorChanged extends PosEvent
{
    public function broadcastAs(): string
    {
        return 'floor.changed';
    }
}
