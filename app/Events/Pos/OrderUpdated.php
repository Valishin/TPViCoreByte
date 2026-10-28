<?php

namespace App\Events\Pos;

use App\Models\Order;

/** Algo ha cambiado en un pedido: las pantallas que lo tengan abierto lo recargan. */
class OrderUpdated extends PosEvent
{
    public int $orderId;

    public ?int $tableId;

    public string $status;

    public int $total;

    public function __construct(Order $order, public ?int $byUserId = null)
    {
        $this->orderId = $order->id;
        $this->tableId = $order->table_id;
        $this->status = $order->status->value;
        $this->total = $order->total;
    }

    public function broadcastAs(): string
    {
        return 'order.updated';
    }
}
