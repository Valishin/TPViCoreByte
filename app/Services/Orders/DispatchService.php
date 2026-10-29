<?php

namespace App\Services\Orders;

use App\Enums\DeliveryStatus;
use App\Enums\OrderType;
use App\Enums\PrintJobKind;
use App\Enums\UserRole;
use App\Exceptions\CashSessionRequiredException;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Pos\CheckoutService;
use App\Services\Pos\PrintJobDispatcher;
use App\Services\Pos\ReceiptFormatter;
use App\Services\Tickets\PaymentService;
use App\Services\Tickets\TicketIssuer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Salida de los pedidos que se van del local:
 *  - Domicilio: repartidor → ticket (cobro pendiente si se cobra al entregar;
 *    pagado si ya lo estaba) → En reparto → ticket de domicilio impreso (y la
 *    copia con QR si está activada).
 *  - Para llevar ya pagado: ticket con su pago al entregarlo.
 * La impresión se encola tras el commit: nunca bloquea la petición.
 */
class DispatchService
{
    public function __construct(
        private TicketIssuer $issuer,
        private PaymentService $payments,
        private PrintJobDispatcher $printing,
        private ReceiptFormatter $formatter,
        private TakeawayOrderService $takeaway,
    ) {}

    public function dispatch(Order $order, User $courier, User $by): Ticket
    {
        if ($order->type !== OrderType::Delivery) {
            throw new InvalidArgumentException('Solo se da salida a pedidos a domicilio.');
        }
        if ($courier->role !== UserRole::Courier || ! $courier->is_active) {
            throw new InvalidArgumentException('Elige un repartidor activo.');
        }
        $session = CheckoutService::openSession() ?? throw new CashSessionRequiredException;

        $ticket = DB::transaction(function () use ($order, $courier, $by, $session) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->isOpen()) {
                throw new LogicException('Este pedido ya ha salido.');
            }
            if (! in_array($order->delivery_status, [DeliveryStatus::InKitchen, DeliveryStatus::Ready], true)) {
                throw new LogicException('Envía la comanda a cocina antes de darle salida.');
            }
            if ($order->lines()->whereNull('sent_at')->whereNull('voided_at')->exists()) {
                throw new LogicException('Hay líneas sin enviar a cocina: envíalas antes de darle salida.');
            }

            $order->update(['courier_id' => $courier->id]);
            $ticket = $this->issuer->issue($order, $by, $session);
            if ($order->prepaid_method && $ticket->total > 0) {
                $this->payments->register($ticket, $order->prepaid_method, $ticket->total, $by, session: $session);
            }
            $order->update(['delivery_status' => DeliveryStatus::OutForDelivery, 'dispatched_at' => now()]);

            return $ticket->refresh();
        });

        $order->refresh();
        $this->printDelivery($ticket, $order, $by);
        $this->takeaway->changed($order);

        return $ticket;
    }

    /** Para llevar ya pagado (tarjeta, Bizum, online…): ticket con su pago al entregarlo. */
    public function handOverPrepaid(Order $order, User $by): Ticket
    {
        if ($order->type !== OrderType::Takeaway || ! $order->prepaid_method) {
            throw new InvalidArgumentException('Solo para pedidos para llevar ya pagados.');
        }
        $session = CheckoutService::openSession() ?? throw new CashSessionRequiredException;

        $ticket = DB::transaction(function () use ($order, $by, $session) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->delivery_status !== DeliveryStatus::Ready || ! $order->isOpen()) {
                throw new LogicException('Solo se entrega un pedido que está listo.');
            }
            $ticket = $this->issuer->issue($order, $by, $session);
            if ($ticket->total > 0) {
                $this->payments->register($ticket, $order->prepaid_method, $ticket->total, $by, session: $session);
            }

            return $ticket->refresh();
        });

        $this->takeaway->markDelivered($order->refresh(), $by);
        if (($printer = PrintJobDispatcher::ticketPrinter()) && Setting::current()->print_ticket_on_checkout) {
            $this->printing->dispatch(null, $printer, PrintJobKind::Receipt, $this->formatter->ticket($ticket, $printer->columns), by: $by, forTicket: $ticket, session: $ticket->cashSession);
        }

        return $ticket;
    }

    private function printDelivery(Ticket $ticket, Order $order, User $by): void
    {
        $printer = PrintJobDispatcher::ticketPrinter();
        if (! $printer) {
            return; // sin impresora de tickets: el ticket queda emitido igualmente
        }
        $order->loadMissing('courier');
        $this->printing->dispatch(null, $printer, PrintJobKind::Receipt, $this->formatter->deliveryTicket($ticket, $order, $printer->columns), by: $by, forTicket: $ticket, session: $ticket->cashSession);
        if (Setting::current()->print_courier_copy) {
            $this->printing->dispatch(null, $printer, PrintJobKind::CourierCopy, $this->formatter->courierCopy($ticket, $order), by: $by, forTicket: $ticket, session: $ticket->cashSession);
        }
    }
}
