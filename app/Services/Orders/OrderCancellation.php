<?php

namespace App\Services\Orders;

use App\Enums\AuthorizationAction;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use App\Services\Pos\AuthorizationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Cancelar un pedido para llevar / a domicilio ANTES de emitir su ticket, con
 * motivo. Si ya se envió algo a cocina hace falta autorización de encargado y
 * se imprime la ANULACIÓN de lo enviado. Con ticket, solo con rectificativo.
 */
class OrderCancellation
{
    public function __construct(
        private OrderService $orders,
        private AuthorizationService $authorization,
        private TakeawayOrderService $takeaway,
    ) {}

    public function cancel(Order $order, User $by, string $reason, ?string $authorizationToken = null): Order
    {
        if (! $order->isTakeawayOrDelivery()) {
            throw new InvalidArgumentException('No es un pedido para llevar ni a domicilio.');
        }
        if (blank($reason)) {
            throw new InvalidArgumentException('Indica el motivo de la cancelación.');
        }
        if (! $order->isOpen()) {
            throw new LogicException($order->tickets()->exists()
                ? 'El pedido ya tiene ticket: para anularlo, haz un rectificativo.'
                : 'El pedido ya no está abierto.');
        }

        DB::transaction(function () use ($order, $by, $reason, $authorizationToken) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $sent = $order->lines()->whereNull('parent_line_id')->whereNotNull('sent_at')->whereNull('voided_at')->get();

            if ($sent->isNotEmpty()) {
                $this->authorization->require(AuthorizationAction::CancelOrder, $by, $authorizationToken, $order, $reason);
            }
            // Las ya enviadas, con su ANULACIÓN impresa; las pendientes, quitadas sin más.
            foreach ($order->lines()->whereNull('parent_line_id')->whereNull('voided_at')->get() as $line) {
                /** @var OrderLine $line */
                $this->orders->voidLine($line, $by, $line->isSent() ? "Pedido cancelado: {$reason}" : null);
            }

            $order->update(['cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);
            $this->orders->cancel($order->refresh());
        });

        return $this->takeaway->changed($order->refresh());
    }
}
