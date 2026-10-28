<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de prueba de la página /estado.
 *
 * ShouldBroadcastNow: se envía a Reverb en la misma petición, sin pasar por la
 * cola, para que la prueba de websockets no dependa del worker.
 */
class DiagnosticPing implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public string $sentAt;

    public function __construct(
        public string $id,
        public string $origin,
        public ?string $ip,
    ) {
        $this->sentAt = now()->toIso8601String();
    }

    public function broadcastOn(): Channel
    {
        return new Channel('diagnostico');
    }

    public function broadcastAs(): string
    {
        return 'diagnostico.ping';
    }
}
