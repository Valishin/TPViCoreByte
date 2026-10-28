<?php

namespace App\Events\Pos;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de pantalla del TPV: se emite al momento (sin cola) y solo cuando la
 * transacción se ha confirmado, en el canal privado `pos`.
 */
abstract class PosEvent implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('pos');
    }

    abstract public function broadcastAs(): string;
}
