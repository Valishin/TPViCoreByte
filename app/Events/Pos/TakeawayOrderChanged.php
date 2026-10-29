<?php

namespace App\Events\Pos;

use App\Models\Order;

/** Un pedido de llevar / domicilio ha cambiado de estado, datos o repartidor: el tablero "Pedidos" lo recarga. */
class TakeawayOrderChanged extends PosEvent
{
    public int $orderId;

    public string $type;

    public ?string $deliveryStatus;

    public ?int $courierId;

    public function __construct(Order $order)
    {
        $this->orderId = $order->id;
        $this->type = $order->type->value;
        $this->deliveryStatus = $order->delivery_status?->value;
        $this->courierId = $order->courier_id;
    }

    public function broadcastAs(): string
    {
        return 'takeaway.changed';
    }
}
